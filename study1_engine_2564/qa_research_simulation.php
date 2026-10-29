<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = Database::pdo();
$adminLogin = $pdo->query(
    "SELECT username FROM ap_user_roles WHERE LOWER(role)='admin' ORDER BY username LIMIT 1"
)->fetchColumn();
if (!$adminLogin) {
    throw new RuntimeException('No admin account is available.');
}
$_SESSION['user_id'] = 'simulation-qa';
$_SESSION['user'] = ['USERLOGIN' => $adminLogin, 'STAFFID' => $adminLogin, 'staff_id' => $adminLogin];
$_SESSION['role_name'] = 'admin';

$studyId = (int)$pdo->query('SELECT study_id FROM research_studies ORDER BY study_id LIMIT 1')->fetchColumn();
if (!$studyId) {
    throw new RuntimeException('No research study is available.');
}

$seed = 20260731;
$count = 100;
$ruleVersion = 'criteria-2564-v1';
$algorithmVersion = 'explainable-rule-engine-1.0.1';
$firstId = ResearchSimulation::createBatch(
    $pdo, $studyId, $count, $seed, $ruleVersion, $algorithmVersion, 'QA reproducibility A'
);
$secondId = ResearchSimulation::createBatch(
    $pdo, $studyId, $count, $seed, $ruleVersion, $algorithmVersion, 'QA reproducibility B'
);
$first = ResearchSimulation::batch($pdo, $firstId);
$second = ResearchSimulation::batch($pdo, $secondId);

$errors = [];
foreach ([$first, $second] as $batch) {
    $batchId = (int)$batch['batch_id'];
    if ($batch['status'] !== 'COMPLETED') $errors[] = "batch {$batchId} is not completed";
    if ((int)$batch['generated_case_count'] !== $count) $errors[] = "batch {$batchId} has wrong case count";
    if ($batch['batch_hash'] !== ResearchSimulation::rerunHash($pdo, $batchId)) {
        $errors[] = "batch {$batchId} rerun hash mismatch";
    }
    foreach ([
        'simulation_cases' => $count,
        'explanation_evaluations' => $count,
        'counterfactual_evaluations' => $count,
    ] as $table => $expected) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE batch_id=?");
        $stmt->execute([$batchId]);
        if ((int)$stmt->fetchColumn() !== $expected) $errors[] = "{$table} count mismatch in batch {$batchId}";
    }
    foreach (['rule_coverage', 'boundary_tests'] as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE batch_id=?");
        $stmt->execute([$batchId]);
        if ((int)$stmt->fetchColumn() < 1) $errors[] = "{$table} missing in batch {$batchId}";
    }
}
if ($first['batch_hash'] !== $second['batch_hash']) {
    $errors[] = 'same locked inputs produced different batch hashes';
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo "Simulation QA passed\n";
echo "batch_ids={$firstId},{$secondId}\n";
echo "case_count={$count}\n";
echo "reproducible_hash={$first['batch_hash']}\n";
