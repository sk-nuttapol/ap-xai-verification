<?php
declare(strict_types=1);

/** Structural checks only. Null/missing is unknown, never an eligibility failure. */
final class Research2568StructureDraft
{
    private static function dateValid(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return false;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    public static function inspect(array $record): array
    {
        $issues = [];
        $add = static function(string $path, string $code, string $kind='INVALID') use (&$issues): void {
            $issues[] = compact('path', 'code', 'kind');
        };
        $dates = static function(array $data, array $keys, string $prefix) use ($add): void {
            foreach ($keys as $key) if (isset($data[$key]) && !self::dateValid($data[$key])) {
                $add($prefix.'.'.$key, 'INVALID_ISO_DATE');
            }
        };
        $object = static fn(mixed $v): bool => is_array($v) && ($v === [] || !array_is_list($v));
        $context = $record['context'] ?? [];
        if (!$object($context)) { $add('context','OBJECT_REQUIRED'); $context=[]; }
        $dates($context,['received_on','assessment_on'],'context');
        $facts = $record['facts'] ?? [];
        if (!$object($facts)) { $add('facts','OBJECT_REQUIRED'); $facts=[]; }
        $works = $facts['works'] ?? [];
        if (!is_array($works) || !array_is_list($works)) { $add('facts.works','LIST_REQUIRED'); $works=[]; }
        $types=['RESEARCH','ARTICLE','BOOK','OTHER'];
        $subtypes=[
            'RESEARCH_ARTICLE'=>'RESEARCH', 'ACADEMIC_ARTICLE'=>'ARTICLE',
            'TEXTBOOK'=>'BOOK', 'AUTHORED_BOOK'=>'BOOK', 'BOOK_CHAPTER_COLLECTION'=>'BOOK',
            'CASE_STUDY'=>'OTHER',
        ];
        $ids=[];
        foreach ($works as $i=>$work) {
            $p='facts.works.'.$i;
            if (!$object($work)) { $add($p,'OBJECT_REQUIRED'); continue; }
            foreach ($work as $key=>$value) {
                if ($value === null) continue;
                if (str_ends_with((string)$key,'_reference') && (!is_string($value)||trim($value)==='')) $add($p.'.'.$key,'NONEMPTY_REFERENCE_REQUIRED');
            }
            if (isset($work['work_id'])) {
                if (!is_string($work['work_id'])||trim($work['work_id'])==='') $add($p.'.work_id','NONEMPTY_ID_REQUIRED');
                elseif (isset($ids[$work['work_id']])) $add($p.'.work_id','DUPLICATE_ID');
                else $ids[$work['work_id']]=true;
            }
            if (isset($work['type'])&&!in_array($work['type'],$types,true)) $add($p.'.type','UNSUPPORTED_TYPE','REVIEW');
            if (isset($work['quality_code'])&&!in_array($work['quality_code'],['B','A','A+'],true)) $add($p.'.quality_code','INVALID_QUALITY');
            foreach (['first_author','corresponding_author','co_author','degree_work_origin','previously_submitted','publication_evidence','journal_indexes_applicable'] as $key) {
                if (isset($work[$key])&&!is_bool($work[$key])) $add($p.'.'.$key,'BOOLEAN_REQUIRED');
            }
            foreach (['case_count','page_count','chapter_count'] as $key) {
                if (isset($work[$key])&&(!is_int($work[$key])||$work[$key]<0)) $add($p.'.'.$key,'NONNEGATIVE_INTEGER_REQUIRED');
            }
            if (isset($work['contribution_percent'])) {
                $v=$work['contribution_percent'];
                if ((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<0||$v>100) $add($p.'.contribution_percent','INVALID_PERCENTAGE');
            }
            $dates($work,['published_on','claimed_publication_date'],$p);
            $sub=$work['subtype']??$work['publication_subtype']??null;
            if (isset($work['subtype'],$work['publication_subtype'])&&$work['subtype']!==$work['publication_subtype']) $add($p.'.subtype','CONFLICTING_SUBTYPE_ALIASES');
            if ($sub!==null) {
                if (!is_string($sub)||!isset($subtypes[$sub])) $add($p.'.subtype','UNSUPPORTED_SUBTYPE','REVIEW');
                elseif (isset($work['type'])&&$work['type']!==$subtypes[$sub]) $add($p.'.subtype','TYPE_SUBTYPE_MISMATCH');
            }
            if (isset($work['journal_indexes'])) {
                $list=$work['journal_indexes'];
                if (!is_array($list)||!array_is_list($list)) $add($p.'.journal_indexes','LIST_REQUIRED');
                else {
                    $seen=[];
                    foreach ($list as $j=>$code) {
                        if (!is_string($code)||!preg_match('/^[A-Z][A-Z0-9_]{0,49}$/D',$code)) $add($p.'.journal_indexes.'.$j,'INVALID_INDEX_CODE');
                        elseif(isset($seen[$code])) $add($p.'.journal_indexes.'.$j,'DUPLICATE_INDEX');
                        else $seen[$code]=true;
                    }
                    if ($list!==[]&&($work['journal_indexes_applicable']??null)===false) $add($p.'.journal_indexes','INDEX_APPLICABILITY_CONFLICT');
                }
            }
        }
        $invalid=count(array_filter($issues,static fn($i)=>$i['kind']==='INVALID'));
        return ['version'=>'structure-draft-1','status'=>$invalid?'INVALID_DATA':($issues?'REVIEW_REQUIRED':'NO_STRUCTURAL_ERRORS_DETECTED'),
            'issues'=>$issues,'decision'=>null,'production_ready'=>false,
            'limitations'=>['Not a completeness or legal eligibility check',
                'Unsupported subtypes require review, not rejection of the application',
                'No minimum-count policy, publication-format policy, evidence authenticity or date-window validation']];
    }
}
