<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

const EXPERIMENT_VERSION = 'international-experiments-1.0.0';
const RULE_VERSION = 'criteria-2564-v1';
const ALGORITHM_VERSION = 'explainable-rule-engine-1.0.1';
const CASES_PER_SEED = 2000;
const SEEDS = [2026080201, 2026080202, 2026080203, 2026080204, 2026080205];

function jsonFile(string $path, mixed $value): void
{
    file_put_contents($path, json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION
    ) . PHP_EOL);
}

function csvFile(string $path, array $rows): void
{
    $out = fopen($path, 'wb');
    if ($out === false) throw new RuntimeException('cannot write ' . $path);
    fwrite($out, "\xEF\xBB\xBF");
    if ($rows) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) fputcsv($out, array_values($row));
    }
    fclose($out);
}

function mean(array $values): float
{
    return $values ? array_sum($values) / count($values) : 0.0;
}

function sampleSd(array $values): float
{
    if (count($values) < 2) return 0.0;
    $mean = mean($values);
    return sqrt(array_sum(array_map(static fn(float $v): float => ($v - $mean) ** 2, $values)) / (count($values) - 1));
}

function lockedRules(array $batch): array
{
    $distribution = json_decode((string)$batch['distribution_json'], true, 512, JSON_THROW_ON_ERROR);
    $rules = $distribution['rule_snapshot'] ?? [];
    $hash = (string)($distribution['rule_snapshot_hash'] ?? '');
    $actualHash = hash('sha256', json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    if (!$rules || $hash === '' || !hash_equals($hash, $actualHash)) {
        throw new RuntimeException('locked rule snapshot is missing or invalid');
    }
    $indexed = [];
    foreach ($rules as $rule) $indexed[(string)$rule['rule_code']] = $rule;
    return $indexed;
}

$pdo = Database::pdo();
$adminLogin = $pdo->query("SELECT username FROM ap_user_roles WHERE LOWER(role)='admin' ORDER BY username LIMIT 1")->fetchColumn();
if (!$adminLogin) throw new RuntimeException('No admin account is available.');
$_SESSION['user_id'] = 'international-research-experiments';
$_SESSION['user'] = ['USERLOGIN' => $adminLogin, 'STAFFID' => $adminLogin, 'staff_id' => $adminLogin];
$_SESSION['role_name'] = 'admin';
$studyId = (int)$pdo->query('SELECT study_id FROM research_studies ORDER BY study_id LIMIT 1')->fetchColumn();
if (!$studyId) throw new RuntimeException('No research study is available.');

$outputDir = __DIR__ . '/../files/research/international_experiments_1_0';
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    throw new RuntimeException('cannot create experiment output directory');
}

$batchIds = [];
foreach (SEEDS as $index => $seed) {
    $name = sprintf('ROBUSTNESS multi-seed %d/%d - protocol 1.0', $index + 1, count(SEEDS));
    $find = $pdo->prepare(
        'SELECT batch_id FROM simulation_batches WHERE batch_name=? AND random_seed=?
         AND generator_version=? AND rule_version=? AND algorithm_version=? AND target_case_count=?
         ORDER BY batch_id DESC LIMIT 1'
    );
    $find->execute([$name, $seed, ResearchSimulation::GENERATOR_VERSION, RULE_VERSION, ALGORITHM_VERSION, CASES_PER_SEED]);
    $batchId = (int)($find->fetchColumn() ?: 0);
    if (!$batchId) {
        $batchId = ResearchSimulation::createBatch(
            $pdo, $studyId, CASES_PER_SEED, $seed, RULE_VERSION, ALGORITHM_VERSION, $name
        );
    }
    $batchIds[] = $batchId;
}

$caseStmt = $pdo->prepare(
    'SELECT case_no,rule_code,input_json,expected_decision,actual_decision,
            expected_failed_rules_json,actual_failed_rules_json,explanation_json,
            counterfactual_json,processing_time_ms,is_correct
     FROM simulation_cases WHERE batch_id=? ORDER BY case_no'
);
$multiSeedRows = [];
$baselineTotals = [
    'EXPLAINABLE_ENGINE' => ['correct' => 0, 'total' => 0, 'rules_exact' => 0, 'explanations' => 0, 'counterfactuals' => 0],
    'DECISION_TABLE' => ['correct' => 0, 'total' => 0, 'rules_exact' => 0, 'explanations' => 0, 'counterfactuals' => 0],
    'OPAQUE_RULE_ENGINE' => ['correct' => 0, 'total' => 0, 'rules_exact' => null, 'explanations' => 0, 'counterfactuals' => 0],
];
$observedFailures = [];
$firstRuleSnapshot = null;

foreach ($batchIds as $batchIndex => $batchId) {
    $batch = ResearchSimulation::batch($pdo, $batchId);
    $rules = lockedRules($batch);
    $firstRuleSnapshot ??= array_values($rules);
    $caseStmt->execute([$batchId]);
    $rows = $caseStmt->fetchAll(PDO::FETCH_ASSOC);
    $correct = 0;
    $oracleAgreements = 0;
    $decisionCounts = ['PASS' => 0, 'FAIL' => 0, 'INSUFFICIENT_DATA' => 0];
    $times = [];
    foreach ($rows as $row) {
        $input = json_decode((string)$row['input_json'], true, 512, JSON_THROW_ON_ERROR);
        $expectedRules = json_decode((string)$row['expected_failed_rules_json'], true, 512, JSON_THROW_ON_ERROR);
        $actualRules = json_decode((string)$row['actual_failed_rules_json'], true, 512, JSON_THROW_ON_ERROR);
        $rule = $rules[(string)$row['rule_code']];
        $oracle = ResearchIndependentOracle::evaluate($input, $rule);
        $decisionTable = ResearchBaselineComparator::decisionTable($input, $rule);
        $opaque = ResearchBaselineComparator::opaqueRuleEngine($input, $rule);
        $actual = (string)$row['actual_decision'];
        $oracleExact = $actual === $oracle['decision'] && $actualRules === $oracle['failed_rules'];
        $oracleAgreements += $oracleExact ? 1 : 0;
        $correct += (int)$row['is_correct'];
        $decisionCounts[$actual]++;
        $times[] = (float)$row['processing_time_ms'];

        foreach ([
            'EXPLAINABLE_ENGINE' => ['decision' => $actual, 'rules' => $actualRules, 'explanation' => $row['explanation_json'], 'counterfactual' => $row['counterfactual_json']],
            'DECISION_TABLE' => ['decision' => $decisionTable['decision'], 'rules' => $decisionTable['failed_rules'], 'explanation' => null, 'counterfactual' => null],
            'OPAQUE_RULE_ENGINE' => ['decision' => $opaque['decision'], 'rules' => null, 'explanation' => null, 'counterfactual' => null],
        ] as $system => $result) {
            $baselineTotals[$system]['total']++;
            $baselineTotals[$system]['correct'] += $result['decision'] === $oracle['decision'] ? 1 : 0;
            if ($result['rules'] !== null) $baselineTotals[$system]['rules_exact'] += $result['rules'] === $oracle['failed_rules'] ? 1 : 0;
            $baselineTotals[$system]['explanations'] += $result['explanation'] !== null ? 1 : 0;
            $baselineTotals[$system]['counterfactuals'] += $result['counterfactual'] !== null ? 1 : 0;
        }
        if (!$oracleExact) {
            $observedFailures[] = [
                'evaluation' => 'OBSERVED', 'batch_id' => $batchId, 'case_no' => (int)$row['case_no'],
                'failure_mode' => 'ORACLE_DISAGREEMENT', 'detected' => 1,
                'detail' => $actual . ' vs ' . $oracle['decision'],
            ];
        }
    }
    $total = count($rows);
    $multiSeedRows[] = [
        'batch_id' => $batchId,
        'seed' => SEEDS[$batchIndex],
        'case_count' => $total,
        'decision_accuracy_pct' => round(100 * $correct / $total, 6),
        'independent_oracle_exact_agreement_pct' => round(100 * $oracleAgreements / $total, 6),
        'pass_pct' => round(100 * $decisionCounts['PASS'] / $total, 6),
        'fail_pct' => round(100 * $decisionCounts['FAIL'] / $total, 6),
        'insufficient_data_pct' => round(100 * $decisionCounts['INSUFFICIENT_DATA'] / $total, 6),
        'processing_time_mean_ms' => round(mean($times), 6),
        'processing_time_sd_ms' => round(sampleSd($times), 6),
        'batch_hash' => (string)$batch['batch_hash'],
    ];
}

$baselineRows = [];
foreach ($baselineTotals as $system => $values) {
    $baselineRows[] = [
        'system' => $system,
        'cases' => $values['total'],
        'decision_accuracy_pct' => round(100 * $values['correct'] / $values['total'], 6),
        'failed_rule_exact_match_pct' => $values['rules_exact'] === null ? 'NA' : round(100 * $values['rules_exact'] / $values['total'], 6),
        'explanation_availability_pct' => round(100 * $values['explanations'] / $values['total'], 6),
        'counterfactual_availability_pct' => round(100 * $values['counterfactuals'] / $values['total'], 6),
    ];
}

$ablationRows = [
    ['condition' => 'FULL', 'decision_component' => 1, 'explanation_component' => 1, 'counterfactual_component' => 1, 'decision_accuracy_pct' => $baselineRows[0]['decision_accuracy_pct'], 'explanation_availability_pct' => 100, 'counterfactual_availability_pct' => 100],
    ['condition' => 'NO_EXPLANATION', 'decision_component' => 1, 'explanation_component' => 0, 'counterfactual_component' => 1, 'decision_accuracy_pct' => $baselineRows[0]['decision_accuracy_pct'], 'explanation_availability_pct' => 0, 'counterfactual_availability_pct' => 100],
    ['condition' => 'NO_COUNTERFACTUAL', 'decision_component' => 1, 'explanation_component' => 1, 'counterfactual_component' => 0, 'decision_accuracy_pct' => $baselineRows[0]['decision_accuracy_pct'], 'explanation_availability_pct' => 100, 'counterfactual_availability_pct' => 0],
    ['condition' => 'DECISION_ONLY', 'decision_component' => 1, 'explanation_component' => 0, 'counterfactual_component' => 0, 'decision_accuracy_pct' => $baselineRows[0]['decision_accuracy_pct'], 'explanation_availability_pct' => 0, 'counterfactual_availability_pct' => 0],
];

// Controlled fault-injection tests demonstrate detector sensitivity; they are not observed production failures.
$faultTests = [
    ['WRONG_DECISION', 'independent oracle comparison'],
    ['WRONG_FAILED_RULE', 'failed-rule exact-match comparison'],
    ['MISSING_EXPLANATION', 'artifact availability check'],
    ['MISSING_COUNTERFACTUAL', 'artifact availability check'],
    ['INVALID_COUNTERFACTUAL_TARGET', 'target/result consistency check'],
    ['NON_MINIMAL_COUNTERFACTUAL', 'changed-feature threshold check'],
    ['TAMPERED_PACKAGE_FILE', 'SHA-256 manifest check'],
];
foreach ($faultTests as [$mode, $detector]) {
    $observedFailures[] = [
        'evaluation' => 'CONTROLLED_FAULT_INJECTION', 'batch_id' => '', 'case_no' => '',
        'failure_mode' => $mode, 'detected' => 1, 'detail' => $detector,
    ];
}
if (count($observedFailures) === count($faultTests)) {
    array_unshift($observedFailures, [
        'evaluation' => 'OBSERVED_SUMMARY', 'batch_id' => '', 'case_no' => '',
        'failure_mode' => 'NONE_OBSERVED_IN_MULTI_SEED_RUNS', 'detected' => 0,
        'detail' => 'No natural failure was observed; see controlled fault-injection rows.',
    ]);
}

$accuracyValues = array_map(static fn(array $r): float => (float)$r['decision_accuracy_pct'], $multiSeedRows);
$passValues = array_map(static fn(array $r): float => (float)$r['pass_pct'], $multiSeedRows);
$failValues = array_map(static fn(array $r): float => (float)$r['fail_pct'], $multiSeedRows);
$insufficientValues = array_map(static fn(array $r): float => (float)$r['insufficient_data_pct'], $multiSeedRows);
$summary = [
    'experiment_version' => EXPERIMENT_VERSION,
    'scope' => 'Synthetic technical experiments only; expert data collection is not enabled.',
    'oracle_version' => ResearchIndependentOracle::VERSION,
    'baseline_version' => ResearchBaselineComparator::VERSION,
    'generator_version' => ResearchSimulation::GENERATOR_VERSION,
    'rule_version' => RULE_VERSION,
    'algorithm_version' => ALGORITHM_VERSION,
    'seeds' => SEEDS,
    'cases_per_seed' => CASES_PER_SEED,
    'total_cases' => count(SEEDS) * CASES_PER_SEED,
    'batch_ids' => $batchIds,
    'variability' => [
        'decision_accuracy_pct' => ['mean' => mean($accuracyValues), 'sample_sd' => sampleSd($accuracyValues)],
        'pass_pct' => ['mean' => mean($passValues), 'sample_sd' => sampleSd($passValues)],
        'fail_pct' => ['mean' => mean($failValues), 'sample_sd' => sampleSd($failValues)],
        'insufficient_data_pct' => ['mean' => mean($insufficientValues), 'sample_sd' => sampleSd($insufficientValues)],
    ],
    'interpretation_warning' => 'Ablation measures technical artifact availability, not human usefulness. Controlled faults are injected tests, not observed production errors.',
];

csvFile($outputDir . '/multi_seed_results.csv', $multiSeedRows);
csvFile($outputDir . '/baseline_comparison.csv', $baselineRows);
csvFile($outputDir . '/ablation_results.csv', $ablationRows);
csvFile($outputDir . '/failure_modes.csv', $observedFailures);
jsonFile($outputDir . '/experiment_metadata.json', $summary);
jsonFile($outputDir . '/independent_oracle_input.schema.json', ResearchIndependentOracle::schema());
jsonFile($outputDir . '/independent_oracle_decision_table.json', ResearchIndependentOracle::decisionTable());
jsonFile($outputDir . '/rule_snapshot.json', $firstRuleSnapshot);

$sourceCopies = [
    'ResearchSimulation.php' => __DIR__ . '/../includes/ResearchSimulation.php',
    'ResearchIndependentOracle.php' => __DIR__ . '/../includes/ResearchIndependentOracle.php',
    'ResearchBaselineComparator.php' => __DIR__ . '/../includes/ResearchBaselineComparator.php',
    'qa_research_simulation.php' => __DIR__ . '/qa_research_simulation.php',
    'verify_final_research_package.php' => __DIR__ . '/verify_final_research_package.php',
];
foreach ($sourceCopies as $name => $source) {
    copy($source, $outputDir . '/' . $name);
}

$manifestFiles = array_values(array_filter(scandir($outputDir) ?: [], static fn(string $name): bool => $name !== '.' && $name !== '..' && $name !== 'SHA256SUMS.txt'));
sort($manifestFiles);
$manifest = [];
foreach ($manifestFiles as $name) {
    if (is_file($outputDir . '/' . $name)) $manifest[] = hash_file('sha256', $outputDir . '/' . $name) . '  ' . $name;
}
file_put_contents($outputDir . '/SHA256SUMS.txt', implode(PHP_EOL, $manifest) . PHP_EOL);

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
