<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$rule = ['rule_code' => 'APR-TEST', 'min_quantity' => 3, 'min_first_author_quantity' => 1];
$cases = [
    'PASS' => ['eligible_work_count' => 3, 'first_author_count' => 1, 'missing_data' => false, 'conflicting_grade_and_score' => false],
    'FAIL_QUANTITY' => ['eligible_work_count' => 2, 'first_author_count' => 1, 'missing_data' => false, 'conflicting_grade_and_score' => false],
    'FAIL_AUTHOR' => ['eligible_work_count' => 3, 'first_author_count' => 0, 'missing_data' => false, 'conflicting_grade_and_score' => false],
    'MISSING' => ['eligible_work_count' => 3, 'first_author_count' => 1, 'missing_data' => true, 'conflicting_grade_and_score' => false],
    'CONFLICT' => ['eligible_work_count' => 3, 'first_author_count' => 1, 'missing_data' => false, 'conflicting_grade_and_score' => true],
];
$expected = [
    'PASS' => 'PASS', 'FAIL_QUANTITY' => 'FAIL', 'FAIL_AUTHOR' => 'FAIL',
    'MISSING' => 'INSUFFICIENT_DATA', 'CONFLICT' => 'INSUFFICIENT_DATA',
];
$errors = [];
foreach ($cases as $name => $input) {
    $oracle = ResearchIndependentOracle::evaluate($input, $rule);
    $table = ResearchBaselineComparator::decisionTable($input, $rule);
    $opaque = ResearchBaselineComparator::opaqueRuleEngine($input, $rule);
    foreach (['oracle' => $oracle['decision'], 'decision_table' => $table['decision'], 'opaque' => $opaque['decision']] as $system => $decision) {
        if ($decision !== $expected[$name]) $errors[] = "$name $system mismatch";
    }
}

$pdo = Database::pdo();
$protocolId = (int)$pdo->query("SELECT protocol_id FROM expert_validation_protocols WHERE protocol_code='XAI-EXPERT-VALIDATION' AND protocol_version='3.1'")->fetchColumn();
try {
    ResearchExpertValidation::assertCollectionAllowed($pdo, $protocolId);
    $errors[] = 'expert-validation gate unexpectedly opened';
} catch (RuntimeException $e) {
    if ($e->getMessage() === '') $errors[] = 'unexpected empty expert-gate error';
}
$q=$pdo->prepare('SELECT COUNT(*) FROM expert_validation_ratings WHERE protocol_id=?'); $q->execute([$protocolId]); $ratingCount=(int)$q->fetchColumn();
if ($ratingCount !== 0) $errors[] = 'expert ratings must remain empty before approval';
$q=$pdo->prepare('SELECT COUNT(*) FROM expert_validation_samples WHERE protocol_id=?'); $q->execute([$protocolId]); $sampleCount=(int)$q->fetchColumn();
if ($sampleCount !== 12) $errors[] = 'frozen expert sample must contain exactly 12 cases';
$q=$pdo->prepare('SELECT MIN(display_order) min_order,MAX(display_order) max_order,COUNT(DISTINCT simulation_case_id) unique_cases FROM expert_validation_samples WHERE protocol_id=?'); $q->execute([$protocolId]); $sampleBounds=$q->fetch(PDO::FETCH_ASSOC);
if ((int)$sampleBounds['min_order'] !== 1 || (int)$sampleBounds['max_order'] !== 12 || (int)$sampleBounds['unique_cases'] !== 12) {
    $errors[] = 'expert sample order or uniqueness mismatch';
}

if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, '[FAIL] ' . $error . PHP_EOL);
    exit(1);
}
echo '[PASS] Independent oracle and both baselines passed five branch tests.' . PHP_EOL;
echo '[PASS] Expert-validation collection gate is closed and rating count is zero.' . PHP_EOL;
echo '[PASS] Frozen expert-validation sample contains 12 unique ordered synthetic cases.' . PHP_EOL;
