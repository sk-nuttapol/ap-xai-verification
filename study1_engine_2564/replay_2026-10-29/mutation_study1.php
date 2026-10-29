<?php
declare(strict_types=1);
// First-order mutation analysis of engine explainable-rule-engine-1.0.1 (ResearchSimulation::engineEvaluate).
// Each mutant edits the engine SOURCE so that one operator applies to one rule (11 operators x 33 rules = 363).
// Tests: q in {tq-1,tq,tq+1}, a in {ta-1,ta,ta+1} (non-negative), missing x conflict in {0,1}^2.
// A mutant is killed when the decision or the ordered failed-rule trace differs from the unmutated engine.
// Usage: php mutation_study1.php <src_dir> <out_dir>
final class Auth { public static function roleName(): string { return 'researcher'; } public static function user(): array { return []; } }
[$_, $src, $out] = $argv + [null, __DIR__.'/src', __DIR__.'/out'];
@mkdir($out, 0775, true);
$code = file_get_contents("$src/ResearchSimulation.php");
$rules = json_decode(file_get_contents("$src/rule_snapshot.json"), true);
$Q = "(\$input['eligible_work_count'] ?? 0) < \$rule['min_quantity']";
$A = "(\$input['first_author_count'] ?? 0) < \$rule['min_first_author_quantity']";
$MS = "filter_var(\$input['missing_data'] ?? false, FILTER_VALIDATE_BOOLEAN)";
$CF = "filter_var(\$input['conflicting_grade_and_score'] ?? false, FILTER_VALIDATE_BOOLEAN)";
$HEAD = "private static function engineEvaluate(array \$input, array \$rule): array\n    {";
foreach ([$Q, $A, $MS, $CF, $HEAD] as $needle) if (substr_count($code, $needle) < 1) { fwrite(STDERR, "pattern not found: $needle\n"); exit(1); }
// restrict replacements to the engineEvaluate body
$start = strpos($code, $HEAD); $end = strpos($code, 'private static function buildExplanation', $start);
$body = substr($code, $start, $end - $start);
$ops = [
  'QUANTITY_STRICT'  => fn($g) => [$Q, "($g ? ((\$input['eligible_work_count'] ?? 0) <= \$rule['min_quantity']) : ($Q))"],
  'QUANTITY_MINUS_1' => fn($g) => [$Q, "(\$input['eligible_work_count'] ?? 0) < (\$rule['min_quantity'] - ($g ? 1 : 0))"],
  'QUANTITY_PLUS_1'  => fn($g) => [$Q, "(\$input['eligible_work_count'] ?? 0) < (\$rule['min_quantity'] + ($g ? 1 : 0))"],
  'QUANTITY_DELETE'  => fn($g) => [$Q, "(!$g && $Q)"],
  'AUTHOR_STRICT'    => fn($g) => [$A, "($g ? ((\$input['first_author_count'] ?? 0) <= \$rule['min_first_author_quantity']) : ($A))"],
  'AUTHOR_MINUS_1'   => fn($g) => [$A, "(\$input['first_author_count'] ?? 0) < (\$rule['min_first_author_quantity'] - ($g ? 1 : 0))"],
  'AUTHOR_PLUS_1'    => fn($g) => [$A, "(\$input['first_author_count'] ?? 0) < (\$rule['min_first_author_quantity'] + ($g ? 1 : 0))"],
  'AUTHOR_DELETE'    => fn($g) => [$A, "(!$g && $A)"],
  'MISSING_DELETE'   => fn($g) => [$MS, "(!$g && $MS)"],
  'CONFLICT_DELETE'  => fn($g) => [$CF, "(!$g && $CF)"],
  'RULE_DELETE_FAIL_OPEN' => fn($g) => [$HEAD, "$HEAD\n        if ($g) { return ['decision' => 'PASS', 'failed_rules' => []]; }"],
];
// tests
$tests = [];
foreach ($rules as $r) {
  $tq = $r['min_quantity']; $ta = $r['min_first_author_quantity'];
  foreach (array_unique([max(0,$tq-1),$tq,$tq+1]) as $q) foreach (array_unique([max(0,$ta-1),$ta,$ta+1]) as $a)
    foreach ([false,true] as $m) foreach ([false,true] as $c)
      $tests[] = [$r, ['eligible_work_count'=>$q,'first_author_count'=>$a,'quality_code'=>$r['quality_code'],'reviewer_votes_pass'=>2,
        'reviewer_total'=>3,'missing_data'=>$m,'conflicting_grade_and_score'=>$c,'irrelevant_nonce'=>17]];
}
$load = function (string $src, string $cls) { $f = tempnam(sys_get_temp_dir(), 'm1'); file_put_contents($f, $src); require $f; unlink($f);
  $m = new ReflectionMethod($cls, 'engineEvaluate'); $m->setAccessible(true); return $m; };
$base = $load(preg_replace('/final class ResearchSimulation\b/', 'final class RS_BASE', $code), 'RS_BASE');
$ref = array_map(fn($t) => $base->invoke(null, $t[1], $t[0]), $tests);
$rows = []; $n = 0; $stat = ['KILLED'=>0,'SURVIVED'=>0];
foreach ($ops as $op => $mk) foreach ($rules as $r) {
  $n++; $g = "(\$rule['rule_code'] === '{$r['rule_code']}')"; [$from, $to] = $mk($g);
  $mbody = str_replace($from, $to, $body);
  $cls = 'RS_M'.$n; $msrc = str_replace($body, $mbody, $code);
  $msrc = preg_replace('/final class ResearchSimulation\b/', "final class $cls", $msrc);
  $eng = $load($msrc, $cls); $killedBy = '';
  foreach ($tests as $i => $t) { if ($eng->invoke(null, $t[1], $t[0]) !== $ref[$i]) { $killedBy = sprintf('%s q=%d a=%d m=%d c=%d', $t[0]['rule_code'], $t[1]['eligible_work_count'], $t[1]['first_author_count'], $t[1]['missing_data'], $t[1]['conflicting_grade_and_score']); break; } }
  $st = $killedBy ? 'KILLED' : 'SURVIVED'; $stat[$st]++;
  $why = $st === 'SURVIVED' && $r['min_first_author_quantity'] === 0 && in_array($op, ['AUTHOR_MINUS_1','AUTHOR_DELETE'], true)
    ? 'EQUIVALENT: tau_a = 0, so a < -1 and a < 0 are never true for non-negative counts' : ($st === 'SURVIVED' ? 'REVIEW' : '');
  $rows[] = [sprintf('S1M%03d', $n), $op, $r['rule_code'], $r['min_quantity'], $r['min_first_author_quantity'], $st, $killedBy, $why];
}
$f = fopen("$out/study1_mutants.csv", 'w'); fputcsv($f, ['mutant','operator','rule_code','tau_q','tau_a','status','killed_by_test','review'], ',', '"', '');
foreach ($rows as $row) fputcsv($f, $row, ',', '"', ''); fclose($f);
$eq = count(array_filter($rows, fn($x) => str_starts_with($x[7], 'EQUIVALENT')));
$summary = ['mutants' => $n, 'tests' => count($tests), 'killed' => $stat['KILLED'], 'survived' => $stat['SURVIVED'], 'equivalent' => $eq,
  'unexplained_survivors' => $stat['SURVIVED'] - $eq, 'score' => $stat['KILLED'] . '/' . ($n - $eq), 'php' => PHP_VERSION];
file_put_contents("$out/study1_mutation_summary.json", json_encode($summary, JSON_PRETTY_PRINT)); echo json_encode($summary, JSON_PRETTY_PRINT), "\n";
