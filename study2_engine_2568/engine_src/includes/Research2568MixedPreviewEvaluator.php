<?php
declare(strict_types=1);
require_once __DIR__.'/ResearchDraftWorkQuotas.php';
require_once __DIR__.'/Research2568WorkPolicyDraft.php';
require_once __DIR__.'/Research2568StructureDraft.php';

/** Current mixed-field synthetic evaluation scope; not a general appointment eligibility engine. */
final class Research2568MixedPreviewEvaluator
{
    public const SCOPE='SYNTHETIC_WORK_ASSESSMENT_2568_V1';
    public const ASSUMPTIONS=['normal_route_eligibility','teaching_and_service','ethics_and_degree_origin',
        'no_prior_work_reuse','valid_three_voter_meeting','no_transition_claim','complete_submission_inventory'];

    public static function evaluate(array $case): array
    {
        if(($case['scope']??null)!==self::SCOPE)throw new InvalidArgumentException('Synthetic scope required');
        foreach(self::ASSUMPTIONS as $key)if(($case['experiment_conditions'][$key]??null)!==true)throw new InvalidArgumentException('Explicit experiment condition required: '.$key);
        $context=$case['context'];$route=$context['route_code']??'';
        $positions=['APR-001'=>'ASST_PROF','APR-002'=>'ASST_PROF','APR-003'=>'ASST_PROF','APR-005'=>'ASST_PROF',
            'APR-006'=>'ASST_PROF','APR-007'=>'ASST_PROF','APR-010'=>'ASSOC_PROF','APR-017'=>'PROF','APR-018'=>'PROF'];
        if(!isset($positions[$route])||($context['position']??null)!==$positions[$route]
            ||!in_array($context['field']??null,['SOCIAL_HUMANITIES','OTHER_FIELDS'],true)
            ||($context['appointment_mode']??null)!=='NORMAL'||($context['transition_choice']??null)!=='NOT_REQUESTED')throw new InvalidArgumentException('Unsupported experiment route');
        if($context['field']==='OTHER_FIELDS' && (!in_array($route,['APR-001','APR-005','APR-017','APR-018'],true)))throw new InvalidArgumentException('Unreviewed STEM route');
        $structure=Research2568StructureDraft::inspect($case);
        if($structure['status']!=='NO_STRUCTURAL_ERRORS_DETECTED')throw new InvalidArgumentException('Invalid experiment structure');
        if(!isset($context['received_on'])||$context['received_on']<'2025-11-27')throw new InvalidArgumentException('Unsupported receipt date');
        $base=require __DIR__.'/../config/research_reference_component_drafts.php';
        if(hash_file('sha256',$base['source_path'])!==$base['source_sha256'])throw new RuntimeException('Source changed');
        $model=$base['models'][$route];$workCopies=[];$checks=[];
        foreach($case['facts']['works'] as $w){
            $policy=Research2568WorkPolicyDraft::inspect($w,$context);
            if($policy['status']!=='COMPONENTS_INSPECTED')throw new InvalidArgumentException('Work policy unresolved');
            $conditions=$policy['checks'];
            foreach(['publication_evidence','peer_review_evidence','participation_certified','definition_verified'] as $key){
                $v=$w[$key]??null;
                if($v!==null&&!is_bool($v))throw new InvalidArgumentException('Invalid evidence flag');
                $conditions[$key]=$v===null?'UNKNOWN':($v?'MET':'NOT_MET');
            }
            $sub=$w['subtype']??null;
            $expected=['BOOK'=>'TEXTBOOK','OTHER'=>'CASE_STUDY','RESEARCH'=>'RESEARCH_ARTICLE','ARTICLE'=>'ACADEMIC_ARTICLE'];
            if($sub!==($expected[$w['type']]??null))throw new InvalidArgumentException('Unsupported subtype');
            $extra=$sub==='CASE_STUDY'?['teaching_notes','used_in_teaching','non_overlapping_cases','whole_collection_review']:
                ($sub==='TEXTBOOK'?['course_identified','used_in_teaching','broad_dissemination','textbook_not_unmodified_handout']:[]);
            foreach($extra as $key){$v=$w[$key]??null;if($v!==null&&!is_bool($v))throw new InvalidArgumentException('Invalid subtype flag');$conditions[$key]=$v===null?'UNKNOWN':($v?'MET':'NOT_MET');}
            if($sub==='CASE_STUDY')foreach(['case_count'=>5,'page_count'=>80] as $key=>$minimum){$v=$w[$key]??null;$conditions[$key]=$v===null?'UNKNOWN':($v>=$minimum?'MET':'NOT_MET');}
            if($context['position']==='PROF'&&$w['type']==='RESEARCH')$conditions['prof_route_international']=($w['publication_level']??null)==='INTERNATIONAL'?'MET':'NOT_MET';
            $date=$w['published_on']??null;
            $conditions['publication_date']=$date===null?'UNKNOWN':($date>='2025-11-27'&&$date<=$context['received_on']?'MET':'NOT_MET');
            $valid=in_array('NOT_MET',$conditions,true)?false:(in_array('UNKNOWN',$conditions,true)?null:true);
            // Local matcher input: conjunction of work admissibility checks, not a rewrite of evidence.
            $copy=$w;$copy['publication_evidence']=$valid;$workCopies[]=$copy;
            $checks[$w['work_id']]=['conditions'=>$conditions,'admissible'=>$valid];
        }
        $allocations=$model['alternatives']??[$model['quotas']];
        foreach($allocations as &$qs){foreach($qs as &$q)$q['require_publication']=true;unset($q);}unset($qs);
        $quota=ResearchDraftWorkQuotas::inspectAlternatives($workCopies,$allocations);
        $votes=$case['facts']['reviewer_votes_pass']??null;
        if(($case['facts']['reviewer_total']??null)!==3||($votes!==null&&(!is_int($votes)||$votes<0||$votes>3)))throw new InvalidArgumentException('Declared three-voter experiment required');
        $decision=$quota['status']==='KNOWN_SHORTFALL'||($votes!==null&&$votes<2)?'FAIL':($quota['status']==='UNKNOWN'||$votes===null?'INSUFFICIENT_DATA':'PASS');
        return ['scope'=>self::SCOPE,'decision'=>$decision,'work_checks'=>$checks,'quota_result'=>$quota,
            'vote_check'=>$votes===null?'UNKNOWN':($votes>=2?'MET':'NOT_MET'),'production_ready'=>false,
            'limitations'=>['Synthetic declared conditions only','Not a real appointment approval','Golden answer requires independent expert review']];
    }
}
