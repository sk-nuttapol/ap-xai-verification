<?php
declare(strict_types=1);
// Base 2564 clauses only. No 2568 applicability or publication rules inferred.
$slot=static fn($type,$count,$quality,$role='ANY')=>['types'=>[$type],'count'=>$count,'min_quality'=>$quality,'author_role'=>$role];
$research=static fn($count,$quality)=>$slot('RESEARCH',$count,$quality,'FIRST_OR_CORRESPONDING');
$asst1=[$research(1,'B'),$slot('RESEARCH',1,'B')];
$asst2=[$research(1,'B'),$slot('OTHER',1,'B')];
$asst3=[$research(1,'B'),$slot('BOOK',1,'B')];
$assoc=[$research(1,'B'),$slot('OTHER',1,'B'),$slot('BOOK',1,'B','FIRST_ONLY')];
$prof=[$research(2,'A'),$slot('RESEARCH',3,'A'),$slot('BOOK',1,'A','FIRST_ONLY')];
$replacement=['page'=>4,'clause'=>'5.1.4 social/humanities substitution for research in option (2)',
    'scope_assumption'=>'Applicant eligible for social/humanities replacement route; not verified here',
    'alternatives'=>[
        [$slot('OTHER',1,'B','FIRST_ONLY'),$slot('ARTICLE',1,'A')],
        [$slot('OTHER',1,'B'),$slot('ARTICLE',1,'A','FIRST_ONLY')],
    ]];
return [
    'version'=>'reference-component-source-draft-2',
    'source_path'=>__DIR__.'/../outputs/เกณฑ์/2564.pdf',
    'source_sha256'=>'154410e89c4fc76598b702e51ed130f297208712fda25d07d34b60292fe2d27e',
    'models'=>[
        'APR-001'=>['page'=>4,'clause'=>'5.1.4 (1)','quotas'=>$asst1],
        'APR-002'=>['page'=>4,'clause'=>'5.1.4 (2)','quotas'=>$asst2],
        'APR-003'=>['page'=>4,'clause'=>'5.1.4 (2)','quotas'=>$asst2],
        'APR-004'=>['page'=>4,'clause'=>'5.1.4 (3)','quotas'=>$asst3],
        'APR-005'=>['page'=>4,'clause'=>'5.1.4 (3)','quotas'=>$asst3],
        'APR-006'=>$replacement,
        'APR-007'=>$replacement,
        'APR-010'=>['page'=>6,'clause'=>'5.2.3 method 1 (2)+(3)','quotas'=>$assoc],
        'APR-017'=>['page'=>8,'clause'=>'5.3.3 method 1 (1)+(3)','quotas'=>$prof],
        'APR-018'=>['page'=>8,'clause'=>'5.3.3 method 1 (1)+(3)','quotas'=>$prof],
    ],
    'unsupported'=>[],
    'limitations'=>['Quantity, quality and specified roles only','No validation of general co-author participation','No publication, votes, teaching, ethics, university rules or transitional assessment','APR-006/007 assume social/humanities route eligibility; do not validate it'],
];
