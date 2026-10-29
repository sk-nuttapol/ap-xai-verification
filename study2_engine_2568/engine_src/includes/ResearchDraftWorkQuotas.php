<?php
declare(strict_types=1);

/** Disjoint-slot matching only; not a complete legal or publication evaluator. */
final class ResearchDraftWorkQuotas
{
    private const RANK = ['B'=>1,'A'=>2,'A+'=>3];
    private const TYPES = ['RESEARCH','BOOK','OTHER','ARTICLE'];

    /** OR between explicit allocations within ONE preselected legal route. */
    public static function inspectAlternatives(array $works,array $alternatives): array
    {
        if(!array_is_list($alternatives)||!$alternatives||count($alternatives)>20)throw new InvalidArgumentException('Invalid alternatives');
        $results=[];$met=[];$unknown=[];
        foreach($alternatives as $idx=>$quotas){
            if(!is_array($quotas))throw new InvalidArgumentException('Invalid alternative quotas');
            $r=self::inspect($works,$quotas);$results[]=$r;
            if($r['status']==='QUOTAS_MET')$met[]=$idx;
            elseif($r['status']==='UNKNOWN')$unknown[]=$idx;
        }
        return ['version'=>'work-alternatives-draft-1','status'=>$met?'QUOTAS_MET':($unknown?'UNKNOWN':'KNOWN_SHORTFALL'),
            'met_alternatives'=>$met,'unknown_alternatives'=>$unknown,'alternatives'=>$results,'decision'=>null,
            'limitations'=>['Alternative allocations only; does not choose a legal method or waive field eligibility','No evidence can be combined across alternative branches']];
    }

    private static function percent(mixed $value): bool
    {
        return (is_int($value)||is_float($value))&&is_finite((float)$value)&&$value>=0&&$value<=100;
    }

    private static function codes(mixed $value,bool $emptyAllowed): bool
    {
        if(!is_array($value)||!array_is_list($value)||(!$emptyAllowed&&!$value))return false;
        foreach($value as $code)if(!is_string($code)||!preg_match('/^[A-Z][A-Z0-9_]{0,49}$/D',$code))return false;
        return count(array_unique($value))===count($value);
    }

    public static function inspect(array $works,array $quotas): array
    {
        if(!array_is_list($works)||!array_is_list($quotas)||!$quotas||count($works)>200) throw new InvalidArgumentException('Invalid or oversized lists');
        $ids=[];
        foreach($works as $w){
            if(!is_array($w)||!is_string($w['work_id']??null)||$w['work_id']===''||isset($ids[$w['work_id']])) throw new InvalidArgumentException('Unique work_id required');
            $ids[$w['work_id']]=true;
            if(!array_key_exists('type',$w)||!array_key_exists('quality_code',$w)) throw new InvalidArgumentException('Explicit type and quality required; null means unknown');
            if($w['type']!==null&&!in_array($w['type'],self::TYPES,true)) throw new InvalidArgumentException('Invalid type');
            if($w['quality_code']!==null&&(!is_string($w['quality_code'])||!isset(self::RANK[$w['quality_code']]))) throw new InvalidArgumentException('Invalid quality');
            foreach(['first_author','corresponding_author','publication_evidence'] as $field) {
                if(isset($w[$field])&&!is_bool($w[$field]))throw new InvalidArgumentException('Evidence fields must be boolean or null');
            }
            if(isset($w['contribution_percent'])&&!self::percent($w['contribution_percent']))throw new InvalidArgumentException('Invalid contribution percentage');
            // [] means a verified complete list with no index; null means unknown.
            if(isset($w['journal_indexes'])&&!self::codes($w['journal_indexes'],true))throw new InvalidArgumentException('Invalid journal indexes');
        }
        $slots=[];
        foreach($quotas as $q){
            if(!is_array($q)||!is_int($q['count']??null)||$q['count']<1||$q['count']>200||!is_string($q['min_quality']??null)||!isset(self::RANK[$q['min_quality']])||!is_array($q['types']??null)||!$q['types']) throw new InvalidArgumentException('Invalid quota');
            foreach($q['types'] as $t)if(!in_array($t,self::TYPES,true))throw new InvalidArgumentException('Invalid quota type');
            if(!in_array($q['author_role']??'ANY',['ANY','FIRST_ONLY','FIRST_OR_CORRESPONDING'],true))throw new InvalidArgumentException('Invalid author role');
            if(array_key_exists('require_publication',$q)&&!is_bool($q['require_publication']))throw new InvalidArgumentException('Invalid publication constraint');
            if(array_key_exists('min_contribution_percent',$q)&&!self::percent($q['min_contribution_percent']))throw new InvalidArgumentException('Invalid contribution threshold');
            if(array_key_exists('accepted_journal_indexes',$q)&&!self::codes($q['accepted_journal_indexes'],false))throw new InvalidArgumentException('Explicit nonempty accepted indexes required');
            for($i=0;$i<$q['count'];$i++)$slots[]=$q;
            if(count($slots)>200)throw new InvalidArgumentException('Too many quota slots');
        }
        $match=static function(bool $allowUnknown)use($works,$slots):array{
            $edges=[];
            foreach($slots as $s=>$quota){$edges[$s]=[];foreach($works as $i=>$work){
                $type=$work['type'];$quality=$work['quality_code'];
                if($type===null&&!$allowUnknown||$quality===null&&!$allowUnknown)continue;
                if($type!==null&&!in_array($type,$quota['types'],true))continue;
                if($quality!==null&&self::RANK[$quality]<self::RANK[$quota['min_quality']])continue;
                $role=$quota['author_role']??'ANY';
                if($role!=='ANY'){
                    $first=$work['first_author']??null;$corr=$work['corresponding_author']??null;
                    $met=$first===true||($role==='FIRST_OR_CORRESPONDING'&&$corr===true);
                    $possible=$met||$first===null||($role==='FIRST_OR_CORRESPONDING'&&$corr===null);
                    if(!$possible||(!$allowUnknown&&!$met))continue;
                }
                if($quota['require_publication']??false){
                    $pub=$work['publication_evidence']??null;
                    if($pub===false||(!$allowUnknown&&$pub!==true))continue;
                }
                if(array_key_exists('min_contribution_percent',$quota)){
                    $percent=$work['contribution_percent']??null;
                    if($percent===null&&!$allowUnknown)continue;
                    if($percent!==null&&$percent<$quota['min_contribution_percent'])continue;
                }
                if(array_key_exists('accepted_journal_indexes',$quota)){
                    $indexes=$work['journal_indexes']??null;
                    if($indexes===null&&!$allowUnknown)continue;
                    if($indexes!==null&&!array_intersect($indexes,$quota['accepted_journal_indexes']))continue;
                }
                $edges[$s][]=$i;
            }}
            $assigned=[];
            $visit=function(int $slot,array &$seen)use(&$visit,&$assigned,$edges):bool{
                foreach($edges[$slot] as $work){if(isset($seen[$work]))continue;$seen[$work]=true;
                    if(!isset($assigned[$work])||$visit($assigned[$work],$seen)){$assigned[$work]=$slot;return true;}
                }return false;
            };
            foreach($slots as $s=>$_){$seen=[];$visit($s,$seen);}
            $mapping=[];foreach($assigned as $i=>$slot)$mapping[$slot]=$works[$i]['work_id'];ksort($mapping);
            return ['count'=>count($assigned),'assignment'=>$mapping];
        };
        $known=$match(false);$possible=$match(true);$required=count($slots);
        return ['version'=>'work-quota-draft-3','scope'=>'DISJOINT_WORK_CONSTRAINTS_ONLY','required'=>$required,
            'known'=>$known,'possible'=>$possible,'status'=>$possible['count']<$required?'KNOWN_SHORTFALL':($known['count']<$required?'UNKNOWN':'QUOTAS_MET'),
            'decision'=>null,'limitations'=>['Evidence and journal index lists are supplied facts, not independently verified','No default percentage or journal policy; caller must resolve field, publication format, revision and applicability','Does not validate dates or transition provisions','Quotas represent distinct slots; split role subquotas rather than adding overlapping author-count slots']];
    }
}
