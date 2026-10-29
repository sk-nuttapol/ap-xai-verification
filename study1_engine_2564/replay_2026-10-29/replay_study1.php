<?php
declare(strict_types=1);
// Replays the Study 1 batches (generator sim-generator-1.0.1, engine explainable-rule-engine-1.0.1) from the
// frozen rule snapshot and seeds, checks batch hashes against the recorded results, and recomputes oracle
// agreement, rule-scenario coverage and boundary assertions. Usage: php replay_study1.php <src_dir> <out_dir>
final class Auth { public static function roleName(): string { return 'researcher'; } public static function user(): array { return []; } }
[$_, $src, $out] = $argv + [null, __DIR__.'/src', __DIR__.'/out'];
require "$src/ResearchSimulation.php"; require "$src/ResearchIndependentOracle.php";
@mkdir($out, 0775, true);
$m = fn(string $n) => (function () use ($n) { $r = new ReflectionMethod(ResearchSimulation::class, $n); $r->setAccessible(true); return $r; })();
$gen = $m('generateCaseDefinitions'); $eng = $m('engineEvaluate');
$rules = json_decode(file_get_contents("$src/rule_snapshot.json"), true);
$recorded = array_map('str_getcsv', file("$src/multi_seed_results.csv", FILE_IGNORE_NEW_LINES));
$hdr = array_map(fn($h) => trim($h, "\xEF\xBB\xBF"), array_shift($recorded)); $rec = [];
foreach ($recorded as $r) { $r = array_combine($hdr, $r); $rec[(int)$r['seed']] = $r; }
$seeds = [2026080201, 2026080202, 2026080203, 2026080204, 2026080205];
$sum = ['seeds' => []]; $cov = []; $bnd = []; $allCases = 0; $agree = 0; $traceAgree = 0;
foreach ($seeds as $seed) {
    $defs = $gen->invoke(null, $rules, 2000, $seed, 'criteria-2564-v1', 'explainable-rule-engine-1.0.1');
    $hash = hash('sha256', implode('|', array_column($defs, 'case_hash')));
    $cnt = ['PASS' => 0, 'FAIL' => 0, 'INSUFFICIENT_DATA' => 0]; $ok = 0;
    foreach ($defs as $d) {
        $e = $eng->invoke(null, $d['input'], $d['rule']);
        $o = ResearchIndependentOracle::evaluate($d['input'], $d['rule']);
        $dOk = $e['decision'] === $o['decision']; $tOk = $dOk && $e['failed_rules'] === $o['failed_rules'];
        $ok += (int)$dOk; $traceAgree += (int)$tOk; $agree += (int)$dOk; $allCases++;
        $cnt[$e['decision']]++;
        $k = $d['rule_code'].'|'.$d['scenario_type'];
        $cov[$k] ??= ['rule_code' => $d['rule_code'], 'scenario' => $d['scenario_type'], 'cases' => 0, 'agree' => 0];
        $cov[$k]['cases']++; $cov[$k]['agree'] += (int)$tOk;
        if ($d['boundary_type'] !== null) $bnd[] = [$seed, $d['rule_code'], $d['boundary_type'], $o['decision'], $e['decision'], (int)$tOk];
    }
    $r = $rec[$seed] ?? [];
    $sum['seeds'][] = ['seed' => $seed, 'replayed_batch_hash' => $hash, 'recorded_batch_hash' => $r['batch_hash'] ?? null,
        'hash_match' => ($r['batch_hash'] ?? '') === $hash, 'decision_agreement' => "$ok/2000",
        'pass_pct' => $cnt['PASS'] / 20, 'fail_pct' => $cnt['FAIL'] / 20, 'insufficient_pct' => $cnt['INSUFFICIENT_DATA'] / 20,
        'recorded_pct' => [$r['pass_pct'] ?? null, $r['fail_pct'] ?? null, $r['insufficient_data_pct'] ?? null]];
}
$cells = count($cov); $full = count(array_filter($cov, fn($c) => $c['cases'] > 0 && $c['agree'] === $c['cases']));
$bPass = array_sum(array_column($bnd, 5));
$sum += ['cases' => $allCases, 'decision_agreement' => "$agree/$allCases", 'trace_agreement' => "$traceAgree/$allCases",
    'coverage_cells_populated' => $cells, 'coverage_cells_all_agree' => $full, 'boundary_assertions' => "$bPass/".count($bnd),
    'boundary_assertions_per_seed' => count($bnd) / 5, 'php' => PHP_VERSION];
file_put_contents("$out/study1_replay_summary.json", json_encode($sum, JSON_PRETTY_PRINT));
$f = fopen("$out/study1_rule_scenario_coverage.csv", 'w'); fputcsv($f, ['rule_code','scenario','cases','trace_agree'], ',', '"', '');
ksort($cov); foreach ($cov as $c) fputcsv($f, array_values($c), ',', '"', ''); fclose($f);
$f = fopen("$out/study1_boundary_assertions.csv", 'w'); fputcsv($f, ['seed','rule_code','boundary_type','oracle','engine','pass'], ',', '"', '');
foreach ($bnd as $b) fputcsv($f, $b, ',', '"', ''); fclose($f);
echo json_encode($sum, JSON_PRETTY_PRINT), "\n";
