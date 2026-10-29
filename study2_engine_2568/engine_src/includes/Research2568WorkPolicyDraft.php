<?php
declare(strict_types=1);

/** Source-based per-work component checks for explicitly selected non-transition scope. */
final class Research2568WorkPolicyDraft
{
    public static function inspect(array $work,array $context): array
    {
        $out=['version'=>'work-policy-draft-1','status'=>'SCOPE_REVIEW_REQUIRED','checks'=>[],
            'decision'=>null,'production_ready'=>false,
            'source'=>['document'=>'GPA-2568-NO3','sha256'=>'c9fbab10667fafd735e8bb5555a1b0235101562034bffd52f2cd514162d4289b','pdf_pages'=>[1,2,5,6,15,21]],
            'not_evaluated'=>['Actual policy applicability and transition deadlines','Full subtype and dissemination requirements',
                'Peer-review and participation-document authenticity','Quota-specific first/corresponding requirements','Final application decision']];
        if(hash_file('sha256',__DIR__.'/../outputs/เกณฑ์/2568-3-1.pdf')!==$out['source']['sha256']){$out['status']='SOURCE_CHANGED_REVIEW';return $out;}
        if(($context['policy_revision']??null)!=='GPA-2564-AMENDED-2568-NO3'
            ||($context['transition_choice']??null)!=='NOT_REQUESTED'
            ||($context['appointment_mode']??null)!=='NORMAL'
            ||!in_array($context['position']??null,['ASST_PROF','ASSOC_PROF','PROF'],true)
            ||!in_array($context['field']??null,['SOCIAL_HUMANITIES','OTHER_FIELDS'],true))return $out;
        $type=$work['type']??null;$format=$work['publication_format']??null;
        $journal=$format==='JOURNAL'&&in_array($type,['RESEARCH','ARTICLE'],true);
        if(!$journal&&!in_array($type,['BOOK','OTHER'],true))return $out;
        $out['status']='COMPONENTS_INSPECTED';
        $add=static function(string $id,?bool $value)use(&$out):void{
            $out['checks'][$id]=$value===null?'UNKNOWN':($value?'MET':'NOT_MET');
        };
        if($journal){
            $accepted=null;$level=$work['publication_level']??null;
            if($level==='INTERNATIONAL')$accepted=['ERIC','MATHSCINET','PUBMED','SCOPUS','WOS_SCIE','WOS_SSCI','WOS_AHCI','JSTOR','PROJECT_MUSE'];
            elseif($level==='NATIONAL'){
                if($context['position']==='ASST_PROF'||($context['field']==='SOCIAL_HUMANITIES'&&$context['position']==='ASSOC_PROF'))$accepted=['TCI1','TCI2'];
                elseif($context['position']==='ASSOC_PROF'||$context['field']==='SOCIAL_HUMANITIES')$accepted=['TCI1'];
            }
            $indexes=$work['journal_indexes']??null;
            if($indexes!==null&&(!is_array($indexes)||!array_is_list($indexes)||array_filter($indexes,static fn($v)=>!is_string($v)))){
                $out['status']='INVALID_DATA';return $out;
            }
            $out['accepted_indexes']=$accepted;
            $add('JOURNAL_INDEX',$accepted===null||$indexes===null?null:(bool)array_intersect($accepted,$indexes));
        }
        if($type==='RESEARCH'&&$journal){
            $roles=[];
            foreach(['first_author','corresponding_author','co_author'] as $key){
                $v=$work[$key]??null;
                if($v!==null&&!is_bool($v)){$out['status']='INVALID_DATA';return $out;}
                $roles[]=$v;
            }
            $add('JOURNAL_RESEARCH_PARTICIPATION',in_array(true,$roles,true)?true:(in_array(null,$roles,true)?null:false));
            // No blanket 50% threshold for journal research.
        }else{
            $percent=$work['contribution_percent']??null;
            if($percent!==null&&((!is_int($percent)&&!is_float($percent))||!is_finite((float)$percent)||$percent<0||$percent>100)){
                $out['status']='INVALID_DATA';return $out;
            }
            $add('NON_RESEARCH_PARTICIPATION_50',$percent===null?null:$percent>=50);
        }
        return $out;
    }
}
