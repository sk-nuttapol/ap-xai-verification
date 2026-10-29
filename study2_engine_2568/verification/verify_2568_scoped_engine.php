<?php
declare(strict_types=1);
/**
 * Computational verification of the scoped 2025 (B.E. 2568) assessment engine
 * SYNTHETIC_WORK_ASSESSMENT_2568_V1 (Research2568MixedPreviewEvaluator).
 *
 * Replicates the Study 1 design for the Study 2 engine:
 *   1. seed-locked synthetic case generator (default 5 seeds x 2,000 cases);
 *   2. independent oracle with its own code path and a different quota algorithm
 *      (exhaustive assignment search instead of augmenting-path matching);
 *   3. route x outcome and condition x state coverage;
 *   4. targeted boundary assertions;
 *   5. first-order source mutation analysis (token-level) of the production engine.
 *
 * Read-only: production files are never modified; mutants are written to a temp dir.
 *
 * Usage (run from anywhere; --app defaults to the parent of this script's directory):
 *   php verify_2568_scoped_engine.php [--app=/path/to/ap] [--out=DIR] [--cases=2000]
 *       [--seeds=2026102901,2026102902,2026102903,2026102904,2026102905]
 *       [--mutation-cases=2000] [--skip-mutation] [--mutant-timeout=120]
 */

const SCOPE = 'SYNTHETIC_WORK_ASSESSMENT_2568_V1';
const CLASSES = ['Research2568MixedPreviewEvaluator','Research2568WorkPolicyDraft','Research2568StructureDraft','ResearchDraftWorkQuotas'];
const MUTATED_FILES = ['includes/Research2568MixedPreviewEvaluator.php','includes/Research2568WorkPolicyDraft.php',
    'includes/ResearchDraftWorkQuotas.php','config/research_reference_component_drafts.php'];
const COPIED_FILES = ['includes/Research2568StructureDraft.php'];

// ---------------------------------------------------------------- CLI
ini_set('display_errors', 'stderr'); ini_set('log_errors', '0'); error_reporting(E_ALL);
register_shutdown_function(static function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) fwrite(STDERR, "FATAL: {$e['message']} in {$e['file']}:{$e['line']}\n");
});
$opt = getopt('', ['app:','out:','cases:','seeds:','mutation-cases:','skip-mutation','mutant-timeout:',
    'run-suite:','suffix:','suite:','result:']);
if (isset($opt['run-suite'])) { exit(runSuiteChild($opt['run-suite'], $opt['suffix'] ?? '', $opt['suite'], $opt['result'])); }
$app   = rtrim($opt['app'] ?? dirname(__DIR__), '/');
$app   = realpath($app) ?: $app; // absolute, so the outputs/ symlink inside the temporary root resolves
$out   = $opt['out'] ?? (getcwd().'/verify_2568_'.date('Ymd_His'));
$nCase = (int)($opt['cases'] ?? 2000);
$seeds = array_map('intval', explode(',', $opt['seeds'] ?? '2026102901,2026102902,2026102903,2026102904,2026102905'));
$nMut  = (int)($opt['mutation-cases'] ?? 2000);
$tmo   = (int)($opt['mutant-timeout'] ?? 120);
foreach (array_merge(MUTATED_FILES, COPIED_FILES) as $f) if (!is_file("$app/$f")) fail("Missing $app/$f (set --app)");
if (!is_dir($out) && !mkdir($out, 0775, true)) fail("Cannot create $out");
define('CAN_SPAWN', function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true));
logln("App: $app\nOut: $out\nPHP: ".PHP_VERSION."\nMode: ".(CAN_SPAWN ? 'child processes' : 'in-process (proc_open unavailable; no per-mutant timeout)'));

// ---------------------------------------------------------------- 1. cases
$suite = []; $perSeed = [];
foreach ($seeds as $seed) { $cases = Gen::batch($seed, $nCase); $perSeed[$seed] = count($cases); foreach ($cases as $c) $suite[] = $c; }
$boundary = Boundary::cases();
$negative = Negative::cases();
logln(sprintf('Generated %d cases (%d seeds) + %d boundary cases', count($suite), count($seeds), count($boundary)));
file_put_contents("$out/suite_generated.json", json_encode($suite, JSON_UNESCAPED_UNICODE));
file_put_contents("$out/suite_boundary.json", json_encode($boundary, JSON_UNESCAPED_UNICODE));
file_put_contents("$out/suite_negative.json", json_encode($negative, JSON_UNESCAPED_UNICODE));
logln(sprintf('Negative (invalid-input) tests: %d', count($negative)));

// ---------------------------------------------------------------- 2. production vs oracle
logln('Running production engine on the generated and boundary suites ...');
$baseRoot = makeRoot($app, '__V0', null);
$T0 = microtime(true);
logln('[1/4] Production engine: generated suite ('.count($suite).' cases)');
$prodGen = runSuite($baseRoot, '__V0', "$out/suite_generated.json", "$out/prod_generated.json", 0);
logln('[1/4] Production engine: boundary suite ('.count($boundary).' cases)');
$prodBnd = runSuite($baseRoot, '__V0', "$out/suite_boundary.json", "$out/prod_boundary.json", 0);
logln('[1/4] Production engine: negative suite ('.count($negative).' cases)');
$prodNeg = runSuite($baseRoot, '__V0', "$out/suite_negative.json", "$out/prod_negative.json", 0);
logln('[2/4] Comparing with the independent oracle ('.fmt_s(microtime(true) - $T0).' so far)');
if ($prodGen === null || $prodBnd === null) fail('Production engine failed to run (see stderr)');

$rows = []; $agree = ['decision'=>0,'trace'=>0,'n'=>0]; $bySeed = []; $cov = []; $condCov = []; $dis = [];
foreach ($suite as $i => $case) {
    $o = Oracle::evaluate($case); $p = $prodGen[$i];
    $dOk = ($p['decision'] ?? null) === $o['decision'];
    $tOk = $dOk && Norm::trace($p) === Norm::trace($o);
    $agree['n']++; $agree['decision'] += (int)$dOk; $agree['trace'] += (int)$tOk;
    $s = $case['meta']['seed'];
    $bySeed[$s]['n'] = ($bySeed[$s]['n'] ?? 0) + 1;
    $bySeed[$s]['agree'] = ($bySeed[$s]['agree'] ?? 0) + (int)$dOk;
    $bySeed[$s][$o['decision']] = ($bySeed[$s][$o['decision']] ?? 0) + 1;
    $route = $case['context']['route_code'];
    $cov[$route][$o['decision']] = ($cov[$route][$o['decision']] ?? 0) + 1;
    foreach ($o['work_checks'] as $w) foreach ($w['conditions'] as $k => $v) $condCov[$k][$v] = ($condCov[$k][$v] ?? 0) + 1;
    $condCov['reviewer_vote'][$o['vote_check']] = ($condCov['reviewer_vote'][$o['vote_check']] ?? 0) + 1;
    $condCov['quota_status'][$o['quota_result']['status']] = ($condCov['quota_status'][$o['quota_result']['status']] ?? 0) + 1;
    if (!$tOk && count($dis) < 50) $dis[] = ['case_id'=>$case['meta']['case_id'],'oracle'=>Norm::trace($o),'production'=>Norm::trace($p)];
    $rows[] = [$case['meta']['case_id'], $s, $route, $case['context']['field'], $o['decision'], $p['decision'] ?? 'EXCEPTION', (int)$dOk, (int)$tOk];
}
csv("$out/cases.csv", ['case_id','seed','route','field','oracle_decision','production_decision','decision_agree','trace_agree'], $rows);
file_put_contents("$out/disagreements.json", json_encode($dis, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

$routes = array_keys(Oracle::MODELS); $cells = 0; $filled = 0; $covRows = [];
foreach ($routes as $r) foreach (['PASS','FAIL','INSUFFICIENT_DATA'] as $d) { $cells++; $n = $cov[$r][$d] ?? 0; $filled += (int)($n > 0); $covRows[] = [$r, $d, $n]; }
csv("$out/coverage_route_outcome.csv", ['route','decision','cases'], $covRows);
$ccRows = []; foreach ($condCov as $k => $vals) foreach (['MET','NOT_MET','UNKNOWN','QUOTAS_MET','KNOWN_SHORTFALL'] as $v) if (isset($vals[$v])) $ccRows[] = [$k, $v, $vals[$v]];
csv("$out/coverage_conditions.csv", ['condition','state','work_or_case_count'], $ccRows);

// ---------------------------------------------------------------- 3. boundary assertions
$bRows = []; $bPass = 0; $bByType = [];
foreach ($boundary as $i => $case) {
    $o = Oracle::evaluate($case); $p = $prodBnd[$i];
    $ok = ($p['decision'] ?? null) === $o['decision'] && Norm::trace($p) === Norm::trace($o);
    $exp = $case['meta']['expect'] ?? null; $expOk = $exp === null || $exp === $o['decision'];
    $ok = $ok && $expOk; $bPass += (int)$ok; $t = $case['meta']['btype'];
    $bByType[$t]['n'] = ($bByType[$t]['n'] ?? 0) + 1; $bByType[$t]['pass'] = ($bByType[$t]['pass'] ?? 0) + (int)$ok;
    $bRows[] = [$case['meta']['case_id'], $case['context']['route_code'], $t, $exp ?? '', $o['decision'], $p['decision'] ?? 'EXCEPTION', (int)$ok];
}
csv("$out/boundary.csv", ['case_id','route','boundary_type','expected','oracle','production','pass'], $bRows);
logln(sprintf('Oracle agreement: decision %d/%d, trace %d/%d; boundary %d/%d', $agree['decision'], $agree['n'], $agree['trace'], $agree['n'], $bPass, count($boundary)));

// ---------------------------------------------------------------- 3b. negative (invalid-input) assertions
$nRows = []; $nPass = 0;
foreach ($negative as $i => $case) {
    $p = $prodNeg[$i]; $meta = $case['meta'];
    if ($meta['unit'] === 'workpolicy') {
        $exp = Oracle::workPolicyStatus($case['work'], $case['context']);
        $got = $p['unit_status'] ?? ('EXCEPTION: '.($p['exception'] ?? ''));
    } else {
        $exp = Oracle::rejects($case) ? 'REJECT' : 'ACCEPT';
        $got = ($p['decision'] ?? null) === 'EXCEPTION' ? 'REJECT' : 'ACCEPT';
    }
    $ok = $exp === $got && $exp === $meta['expect']; $nPass += (int)$ok;
    $nRows[] = [$meta['case_id'], $meta['unit'], $meta['ntype'], $meta['expect'], $exp, $got, $p['exception'] ?? '', (int)$ok];
}
csv("$out/negative.csv", ['case_id','unit','negative_type','expected','oracle','production','production_exception','pass'], $nRows);
logln(sprintf('Negative assertions: %d/%d', $nPass, count($negative)));

// ---------------------------------------------------------------- 4. mutation analysis
$mut = ['generated'=>0,'stillborn'=>0,'killed'=>0,'survived'=>0,'timeout'=>0];
$mutRows = [];
if (!isset($opt['skip-mutation'])) {
    $mSuite = array_merge($boundary, $negative, array_slice($suite, 0, $nMut));
    file_put_contents("$out/suite_mutation.json", json_encode($mSuite, JSON_UNESCAPED_UNICODE));
    logln('[3/4] Mutation reference run ('.count($mSuite).' cases)');
    $ref = runSuite($baseRoot, '__V0', "$out/suite_mutation.json", "$out/prod_mutation.json", 0);
    $refN = array_map([Norm::class, 'full'], $ref);
    $mutants = Mutator::enumerate($app);
    $mut['generated'] = count($mutants);
    logln(sprintf('[4/4] Mutation: %d mutants over %d test cases', count($mutants), count($mSuite)));
    $TM = microtime(true);
    foreach ($mutants as $k => $m) {
        $id = sprintf('M%04d', $k + 1); $suffix = '__'.$id;
        $root = makeRoot($app, $suffix, $m);
        $parsable = true; try { token_get_all(file_get_contents("$root/{$m['file']}"), TOKEN_PARSE); } catch (ParseError $e) { $parsable = false; }
        if (!$parsable) { $status = 'STILLBORN'; $detail = 'syntax error'; }
        else {
            $res = runSuite($root, $suffix, "$out/suite_mutation.json", "$root/result.json", $tmo);
            if ($res === 'TIMEOUT') { $status = 'KILLED'; $detail = 'timeout'; $mut['timeout']++; }
            elseif ($res === null) { $status = 'KILLED'; $detail = 'crash'; }
            else {
                $first = null;
                foreach ($res as $j => $r) if (Norm::full($r) !== $refN[$j]) { $first = $j; break; }
                [$status, $detail] = $first === null ? ['SURVIVED', ''] : ['KILLED', 'case '.$mSuite[$first]['meta']['case_id']];
            }
        }
        $mut[strtolower($status)]++;
        $hint = $status === 'SURVIVED' ? Mutator::hint($m, explode("\n", file_get_contents("$app/{$m['file']}"))[$m['line'] - 1] ?? '') : '';
        if ($status === 'SURVIVED' && !str_starts_with($hint, 'review')) $mut['survived_hint_equivalent'] = ($mut['survived_hint_equivalent'] ?? 0) + 1;
        $mutRows[] = [$id, $m['file'], $m['line'], $m['op'], $m['from'], $m['to'], $status, $detail, $hint];
        rrmdir($root);
        $el = microtime(true) - $TM; $done = $k + 1;
        logln(sprintf('  %s %d/%d %-9s %s:%d %s  | killed %d survived %d | %s elapsed, ~%s left', $id, $done, count($mutants), $status, basename($m['file']), $m['line'], $m['op'],
            $mut['killed'], $mut['survived'], fmt_s($el), fmt_s($el / $done * (count($mutants) - $done))));
    }
    csv("$out/mutants.csv", ['mutant','file','line','operator','original','mutated','status','detail','triage_hint'], $mutRows);
}
rrmdir($baseRoot);

// ---------------------------------------------------------------- 5. summary
$seedRows = [];
foreach ($bySeed as $s => $v) $seedRows[] = [$s, $v['n'], $v['agree'], $v['PASS'] ?? 0, $v['FAIL'] ?? 0, $v['INSUFFICIENT_DATA'] ?? 0];
csv("$out/seeds.csv", ['seed','cases','decision_agree','PASS','FAIL','INSUFFICIENT_DATA'], $seedRows);
$valid = $mut['generated'] - $mut['stillborn'];
$summary = [
    'engine_scope' => SCOPE, 'app' => $app, 'php' => PHP_VERSION, 'run_at' => date('c'),
    'source_sha256' => array_combine(array_merge(MUTATED_FILES, COPIED_FILES), array_map(fn($f) => hash_file('sha256', "$app/$f"), array_merge(MUTATED_FILES, COPIED_FILES))),
    'seeds' => $seeds, 'cases_per_seed' => $nCase, 'cases_total' => $agree['n'],
    'decision_agreement' => [$agree['decision'], $agree['n']], 'trace_agreement' => [$agree['trace'], $agree['n']],
    'route_outcome_cells' => [$filled, $cells], 'boundary_assertions' => [$bPass, count($boundary)], 'negative_assertions' => [$nPass, count($negative)], 'boundary_by_type' => $bByType,
    'mutation' => $mut + ['non_stillborn' => $valid, 'raw_score_killed_over_non_stillborn' => $valid ? round($mut['killed'] / $valid, 4) : null,
        'note' => 'Survivors must be reviewed manually; those proven equivalent over the valid input domain are excluded from the final denominator.'],
];
file_put_contents("$out/summary.json", json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
$md = "# Verification of ".SCOPE."\n\nRun: {$summary['run_at']} · PHP ".PHP_VERSION."\n\n"
    ."| Check | Result |\n|---|---|\n"
    ."| Generated cases | {$agree['n']} (".count($seeds)." seeds × $nCase) |\n"
    ."| Decision agreement with independent oracle | {$agree['decision']}/{$agree['n']} |\n"
    ."| Trace agreement (quota status, vote check, per-work admissibility and conditions) | {$agree['trace']}/{$agree['n']} |\n"
    ."| Route × outcome cells populated | $filled/$cells |\n"
    ."| Boundary assertions passed | $bPass/".count($boundary)." |\n"
    ."| Negative (invalid-input) assertions passed | $nPass/".count($negative)." |\n"
    ."| Mutants generated / stillborn | {$mut['generated']} / {$mut['stillborn']} |\n"
    ."| Killed / survived (timeouts) | {$mut['killed']} / {$mut['survived']} ({$mut['timeout']}) |\n"
    ."| Survivors flagged likely equivalent/unreachable (heuristic) | ".($mut['survived_hint_equivalent'] ?? 0)." |\n\n"
    ."Survived mutants are listed in mutants.csv and must be classified by hand as equivalent or as test gaps.\n";
file_put_contents("$out/summary.md", $md);
$sums = ''; foreach (glob("$out/*.{csv,json,md}", GLOB_BRACE) as $f) $sums .= hash_file('sha256', $f).'  '.basename($f)."\n";
file_put_contents("$out/SHA256SUMS.txt", $sums);
logln("\n$md\nTotal time: ".fmt_s(microtime(true) - $T0)."\nResults in $out");

// ================================================================ helpers
function logln(string $s): void { fwrite(STDERR, $s."\n"); }
function v2568_cached_hash_file(string $algo, string $path): string|false {
    static $cache = []; $k = $algo.'|'.$path;
    return $cache[$k] ??= hash_file($algo, $path);
}
function fmt_s(float $s): string { $i = (int) $s; return $s >= 3600 ? sprintf('%dh%02dm', intdiv($i, 3600), intdiv($i % 3600, 60)) : ($s >= 60 ? sprintf('%dm%02ds', intdiv($i, 60), $i % 60) : sprintf('%.1fs', $s)); }
function fail(string $s): never { logln("ERROR: $s"); exit(1); }
function csv(string $path, array $head, array $rows): void {
    $h = fopen($path, 'w'); fputcsv($h, $head, ',', '"', ''); foreach ($rows as $r) fputcsv($h, $r, ',', '"', ''); fclose($h);
}
function rrmdir(string $d): void {
    if (!is_dir($d) || is_link($d)) { if (is_link($d) || is_file($d)) unlink($d); return; }
    foreach (scandir($d) as $f) if ($f !== '.' && $f !== '..') rrmdir("$d/$f");
    rmdir($d);
}
/** Copy engine files to a temp root, rename classes with $suffix, apply optional mutation. */
function makeRoot(string $app, string $suffix, ?array $mutation): string {
    $root = sys_get_temp_dir().'/v2568_'.getmypid().$suffix;
    rrmdir($root); mkdir("$root/includes", 0775, true); mkdir("$root/config", 0775, true);
    if (is_dir("$app/outputs") && !@symlink("$app/outputs", "$root/outputs")) fail('Cannot symlink outputs into '.$root);
    foreach (array_merge(MUTATED_FILES, COPIED_FILES) as $f) {
        $src = file_get_contents("$app/$f");
        if ($mutation !== null && $mutation['file'] === $f) $src = Mutator::apply($src, $mutation);
        foreach (CLASSES as $c) $src = preg_replace('/\b'.$c.'\b(?!\.php)/', $c.$suffix, $src);
        // Same result as hash_file(), memoised per process: the engine re-hashes the source PDFs on every call.
        $src = preg_replace('/\bhash_file\(/', 'v2568_cached_hash_file(', $src);
        file_put_contents("$root/$f", $src);
    }
    return $root;
}
/** Run a suite in a child PHP process. Returns list of results, null on crash, 'TIMEOUT'. */
function runSuite(string $root, string $suffix, string $suite, string $result, int $timeout): array|string|null {
    @unlink($result);
    if (!CAN_SPAWN) {
        putenv('V2568_PROGRESS='.($suffix === '__V0' ? '1' : '0'));
        try { runSuiteChild($root, $suffix, $suite, $result); } catch (Throwable $e) { logln("  in-process failure ($suffix): ".$e->getMessage()); return null; }
        finally { restore_error_handler(); }
        return is_file($result) ? json_decode(file_get_contents($result), true) : null;
    }
    $cmd = [PHP_BINARY, '-d', 'memory_limit=1024M', '-d', 'display_errors=stderr', __FILE__, '--run-suite='.$root, '--suffix='.$suffix, '--suite='.$suite, '--result='.$result];
    putenv('V2568_PROGRESS='.($suffix === '__V0' ? '1' : '0'));
    $p = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($p)) return null;
    fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $start = time(); $err = '';
    while (true) {
        $chunk = (string)stream_get_contents($pipes[2]); stream_get_contents($pipes[1]);
        if ($chunk !== '' && $suffix === '__V0') fwrite(STDERR, $chunk); else $err .= $chunk;
        $st = proc_get_status($p);
        if (!$st['running']) break;
        if ($timeout > 0 && time() - $start > $timeout) { proc_terminate($p, 9); proc_close($p); return 'TIMEOUT'; }
        usleep(20000);
    }
    $chunk = (string)stream_get_contents($pipes[2]); if ($suffix === '__V0') fwrite(STDERR, $chunk); else $err .= $chunk; proc_close($p);
    if (!is_file($result)) { if ($suffix === '__V0') logln($err); return null; }
    return json_decode(file_get_contents($result), true);
}
/** Child mode: evaluate each case with the (possibly mutated) production engine. */
function runSuiteChild(string $root, string $suffix, string $suite, string $result): int {
    error_reporting(E_ALL);
    set_error_handler(static function (int $no, string $msg) { throw new ErrorException($msg, 0, $no); });
    require_once "$root/includes/Research2568MixedPreviewEvaluator.php";
    $cls = 'Research2568MixedPreviewEvaluator'.$suffix;
    $out = []; $all = json_decode(file_get_contents($suite), true); $n = count($all); $t0 = microtime(true);
    $progress = getenv('V2568_PROGRESS') === '1';
    foreach ($all as $ci => $case) {
        if ($progress && $ci > 0 && $ci % 500 === 0) { $el = microtime(true) - $t0; fwrite(STDERR, sprintf("    %d/%d cases (%s elapsed, ~%s left)\n", $ci, $n, fmt_s($el), fmt_s($el / $ci * ($n - $ci)))); }
        $unit = $case['meta']['unit'] ?? 'evaluator'; unset($case['meta']);
        if ($unit === 'workpolicy') {
            $wp = 'Research2568WorkPolicyDraft'.$suffix;
            try { $r = $wp::inspect($case['work'], $case['context']); $c = $r['checks']; ksort($c); $out[] = ['decision' => 'UNIT', 'unit_status' => $r['status'], 'checks' => $c]; }
            catch (Throwable $e) { $out[] = ['decision' => 'EXCEPTION', 'exception' => get_class($e).': '.$e->getMessage()]; }
            continue;
        }
        try { $r = $cls::evaluate($case); $out[] = Norm::extract($r); }
        catch (Throwable $e) { $out[] = ['decision' => 'EXCEPTION', 'exception' => get_class($e).': '.$e->getMessage()]; }
    }
    file_put_contents($result, json_encode($out, JSON_UNESCAPED_UNICODE));
    return 0;
}

// ================================================================ normalisation
final class Norm {
    public static function extract(array $r): array {
        $checks = [];
        foreach ($r['work_checks'] ?? [] as $id => $w) { $c = $w['conditions']; ksort($c); $checks[$id] = ['admissible' => $w['admissible'], 'conditions' => $c]; }
        ksort($checks);
        return ['decision' => $r['decision'], 'quota_status' => $r['quota_result']['status'] ?? null, 'vote_check' => $r['vote_check'] ?? null, 'work_checks' => $checks];
    }
    public static function trace(array $r): string {
        if (isset($r['quota_result'])) $r = self::extract($r);
        unset($r['exception']);
        return json_encode($r, JSON_UNESCAPED_UNICODE);
    }
    public static function full(array $r): string { return json_encode($r, JSON_UNESCAPED_UNICODE); }
}

// ================================================================ independent oracle
/**
 * Re-encodes the frozen specification from the B.E. 2564 base clauses and the 2568 No. 3 amendment
 * as used in the study. Shares no code with the production classes. Quota satisfaction is decided by
 * exhaustive search over injective work-to-slot assignments (production uses augmenting paths).
 */
final class Oracle {
    const RANK = ['B' => 1, 'A' => 2, 'A+' => 3];
    const WINDOW_START = '2025-11-27';
    const INTL = ['ERIC','MATHSCINET','PUBMED','SCOPUS','WOS_SCIE','WOS_SSCI','WOS_AHCI','JSTOR','PROJECT_MUSE'];
    // slot = [types, count, min_quality, role]; role: ANY | FIRST | FIRST_OR_CORR
    const ASST1 = [[['RESEARCH'],1,'B','FIRST_OR_CORR'], [['RESEARCH'],1,'B','ANY']];
    const ASST2 = [[['RESEARCH'],1,'B','FIRST_OR_CORR'], [['OTHER'],1,'B','ANY']];
    const ASST3 = [[['RESEARCH'],1,'B','FIRST_OR_CORR'], [['BOOK'],1,'B','ANY']];
    const ASSOC = [[['RESEARCH'],1,'B','FIRST_OR_CORR'], [['OTHER'],1,'B','ANY'], [['BOOK'],1,'B','FIRST']];
    const PROF  = [[['RESEARCH'],2,'A','FIRST_OR_CORR'], [['RESEARCH'],3,'A','ANY'], [['BOOK'],1,'A','FIRST']];
    const REPL  = [[[['OTHER'],1,'B','FIRST'], [['ARTICLE'],1,'A','ANY']], [[['OTHER'],1,'B','ANY'], [['ARTICLE'],1,'A','FIRST']]];
    const MODELS = ['APR-001' => [self::ASST1], 'APR-002' => [self::ASST2], 'APR-003' => [self::ASST2], 'APR-005' => [self::ASST3],
        'APR-006' => self::REPL, 'APR-007' => self::REPL, 'APR-010' => [self::ASSOC], 'APR-017' => [self::PROF], 'APR-018' => [self::PROF]];
    const POSITION = ['APR-001' => 'ASST_PROF', 'APR-002' => 'ASST_PROF', 'APR-003' => 'ASST_PROF', 'APR-005' => 'ASST_PROF',
        'APR-006' => 'ASST_PROF', 'APR-007' => 'ASST_PROF', 'APR-010' => 'ASSOC_PROF', 'APR-017' => 'PROF', 'APR-018' => 'PROF'];
    const SUBTYPE_EXTRA = ['CASE_STUDY' => ['teaching_notes','used_in_teaching','non_overlapping_cases','whole_collection_review'],
        'TEXTBOOK' => ['course_identified','used_in_teaching','broad_dissemination','textbook_not_unmodified_handout']];

    private static function tri(?bool $v): string { return $v === null ? 'UNKNOWN' : ($v ? 'MET' : 'NOT_MET'); }

    public static function workConditions(array $w, array $ctx): array {
        $c = []; $type = $w['type']; $isJournal = ($w['publication_format'] ?? null) === 'JOURNAL' && in_array($type, ['RESEARCH','ARTICLE'], true);
        $pos = $ctx['position']; $soc = $ctx['field'] === 'SOCIAL_HUMANITIES';
        if ($isJournal) {
            $level = $w['publication_level'] ?? null; $acc = null;
            if ($level === 'INTERNATIONAL') $acc = self::INTL;
            if ($level === 'NATIONAL') {
                if ($pos === 'ASST_PROF' || ($pos === 'ASSOC_PROF' && $soc)) $acc = ['TCI1','TCI2'];
                elseif ($pos === 'ASSOC_PROF' || $soc) $acc = ['TCI1'];
            }
            $idx = $w['journal_indexes'] ?? null;
            $c['JOURNAL_INDEX'] = ($acc === null || $idx === null) ? 'UNKNOWN' : (count(array_intersect($acc, $idx)) > 0 ? 'MET' : 'NOT_MET');
        }
        if ($isJournal && $type === 'RESEARCH') {
            $roles = [$w['first_author'] ?? null, $w['corresponding_author'] ?? null, $w['co_author'] ?? null];
            $c['JOURNAL_RESEARCH_PARTICIPATION'] = in_array(true, $roles, true) ? 'MET' : (in_array(null, $roles, true) ? 'UNKNOWN' : 'NOT_MET');
        } else {
            $pct = $w['contribution_percent'] ?? null;
            $c['NON_RESEARCH_PARTICIPATION_50'] = $pct === null ? 'UNKNOWN' : ($pct >= 50 ? 'MET' : 'NOT_MET');
        }
        foreach (['publication_evidence','peer_review_evidence','participation_certified','definition_verified'] as $k) $c[$k] = self::tri($w[$k] ?? null);
        $sub = $w['subtype'];
        foreach (self::SUBTYPE_EXTRA[$sub] ?? [] as $k) $c[$k] = self::tri($w[$k] ?? null);
        if ($sub === 'CASE_STUDY') {
            $c['case_count'] = isset($w['case_count']) ? ($w['case_count'] >= 5 ? 'MET' : 'NOT_MET') : 'UNKNOWN';
            $c['page_count'] = isset($w['page_count']) ? ($w['page_count'] >= 80 ? 'MET' : 'NOT_MET') : 'UNKNOWN';
        }
        if ($pos === 'PROF' && $type === 'RESEARCH') $c['prof_route_international'] = ($w['publication_level'] ?? null) === 'INTERNATIONAL' ? 'MET' : 'NOT_MET';
        $d = $w['published_on'] ?? null;
        $c['publication_date'] = $d === null ? 'UNKNOWN' : (strcmp($d, self::WINDOW_START) >= 0 && strcmp($d, $ctx['received_on']) <= 0 ? 'MET' : 'NOT_MET');
        return $c;
    }

    /** Fit of a work to a slot: 'YES', 'MAYBE' or 'NO'. */
    private static function fit(array $w, ?bool $admissible, array $slot): string {
        [$types, , $minQ, $role] = $slot; $maybe = false;
        if ($w['type'] === null) $maybe = true; elseif (!in_array($w['type'], $types, true)) return 'NO';
        if ($w['quality_code'] === null) $maybe = true; elseif (self::RANK[$w['quality_code']] < self::RANK[$minQ]) return 'NO';
        if ($role !== 'ANY') {
            $f = $w['first_author'] ?? null; $co = $w['corresponding_author'] ?? null;
            $yes = $f === true || ($role === 'FIRST_OR_CORR' && $co === true);
            $may = $f === null || ($role === 'FIRST_OR_CORR' && $co === null);
            if (!$yes && !$may) return 'NO'; if (!$yes) $maybe = true;
        }
        if ($admissible === false) return 'NO'; if ($admissible === null) $maybe = true;
        return $maybe ? 'MAYBE' : 'YES';
    }

    /** Exhaustive search: can every slot get a distinct work with fit in $allowed? */
    private static function assignable(array $fit, int $nWorks, array $allowed): bool {
        $nSlots = count($fit); if ($nSlots > $nWorks) return false;
        $used = array_fill(0, $nWorks, false);
        $rec = function (int $s) use (&$rec, &$used, $fit, $nWorks, $nSlots, $allowed): bool {
            if ($s === $nSlots) return true;
            for ($i = 0; $i < $nWorks; $i++) if (!$used[$i] && in_array($fit[$s][$i], $allowed, true)) {
                $used[$i] = true; if ($rec($s + 1)) return true; $used[$i] = false;
            }
            return false;
        };
        return $rec(0);
    }

    const STEM_ROUTES = ['APR-001','APR-005','APR-017','APR-018'];
    const SUBTYPE_OF = ['RESEARCH' => 'RESEARCH_ARTICLE', 'ARTICLE' => 'ACADEMIC_ARTICLE', 'BOOK' => 'TEXTBOOK', 'OTHER' => 'CASE_STUDY'];
    const CONDITIONS = ['normal_route_eligibility','teaching_and_service','ethics_and_degree_origin','no_prior_work_reuse','valid_three_voter_meeting','no_transition_claim','complete_submission_inventory'];

    /** Scope of the per-work policy check: which contexts and works it inspects. */
    public static function workPolicyStatus(array $w, array $ctx): string {
        if (($ctx['policy_revision'] ?? null) !== 'GPA-2564-AMENDED-2568-NO3' || ($ctx['transition_choice'] ?? null) !== 'NOT_REQUESTED'
            || ($ctx['appointment_mode'] ?? null) !== 'NORMAL' || !in_array($ctx['position'] ?? null, ['ASST_PROF','ASSOC_PROF','PROF'], true)
            || !in_array($ctx['field'] ?? null, ['SOCIAL_HUMANITIES','OTHER_FIELDS'], true)) return 'SCOPE_REVIEW_REQUIRED';
        $type = $w['type'] ?? null; $journal = ($w['publication_format'] ?? null) === 'JOURNAL' && in_array($type, ['RESEARCH','ARTICLE'], true);
        if (!$journal && !in_array($type, ['BOOK','OTHER'], true)) return 'SCOPE_REVIEW_REQUIRED';
        return 'COMPONENTS_INSPECTED';
    }
    /** True when the declared scope does not accept the case (the engine must refuse to decide). */
    public static function rejects(array $c): bool {
        $ctx = $c['context'] ?? []; $route = $ctx['route_code'] ?? '';
        if (($c['scope'] ?? null) !== SCOPE) return true;
        foreach (self::CONDITIONS as $k) if (($c['experiment_conditions'][$k] ?? null) !== true) return true;
        if (!isset(self::POSITION[$route]) || ($ctx['position'] ?? null) !== self::POSITION[$route]) return true;
        if (!in_array($ctx['field'] ?? null, ['SOCIAL_HUMANITIES','OTHER_FIELDS'], true)) return true;
        if (($ctx['appointment_mode'] ?? null) !== 'NORMAL' || ($ctx['transition_choice'] ?? null) !== 'NOT_REQUESTED') return true;
        if ($ctx['field'] === 'OTHER_FIELDS' && !in_array($route, self::STEM_ROUTES, true)) return true;
        if (!isset($ctx['received_on']) || strcmp($ctx['received_on'], self::WINDOW_START) < 0) return true;
        foreach ($c['facts']['works'] ?? [] as $w) {
            if (self::workPolicyStatus($w, $ctx) !== 'COMPONENTS_INSPECTED') return true;
            if (($w['subtype'] ?? null) !== (self::SUBTYPE_OF[$w['type']] ?? null)) return true;
        }
        $v = $c['facts']['reviewer_votes_pass'] ?? null;
        if (($c['facts']['reviewer_total'] ?? null) !== 3 || ($v !== null && (!is_int($v) || $v < 0 || $v > 3))) return true;
        return false;
    }

    public static function evaluate(array $case): array {
        $ctx = $case['context']; $works = $case['facts']['works']; $checks = []; $adm = [];
        foreach ($works as $w) {
            $c = self::workConditions($w, $ctx);
            $a = in_array('NOT_MET', $c, true) ? false : (in_array('UNKNOWN', $c, true) ? null : true);
            $checks[$w['work_id']] = ['conditions' => $c, 'admissible' => $a]; $adm[] = $a;
        }
        $status = 'KNOWN_SHORTFALL';
        foreach (self::MODELS[$ctx['route_code']] as $alt) {
            $slots = []; foreach ($alt as $s) for ($k = 0; $k < $s[1]; $k++) $slots[] = $s;
            $fit = []; foreach ($slots as $si => $s) foreach ($works as $wi => $w) $fit[$si][$wi] = self::fit($w, $adm[$wi], $s);
            if (self::assignable($fit, count($works), ['YES'])) { $status = 'QUOTAS_MET'; break; }
            if (self::assignable($fit, count($works), ['YES','MAYBE'])) $status = 'UNKNOWN';
        }
        $v = $case['facts']['reviewer_votes_pass'];
        $vote = $v === null ? 'UNKNOWN' : ($v >= 2 ? 'MET' : 'NOT_MET');
        if ($status === 'KNOWN_SHORTFALL' || $vote === 'NOT_MET') $d = 'FAIL';
        elseif ($status === 'UNKNOWN' || $vote === 'UNKNOWN') $d = 'INSUFFICIENT_DATA';
        else $d = 'PASS';
        return ['decision' => $d, 'quota_result' => ['status' => $status], 'vote_check' => $vote, 'work_checks' => $checks];
    }
}

// ================================================================ generator
final class Gen {
    private static function p(float $prob): bool { return mt_rand() / mt_getrandmax() < $prob; }
    private static function pick(array $a): mixed { return $a[mt_rand(0, count($a) - 1)]; }
    private static function tri(float $pNull = 0.05, float $pFalse = 0.05): ?bool { $r = mt_rand() / mt_getrandmax(); return $r < $pNull ? null : ($r < $pNull + $pFalse ? false : true); }
    private static function date(string $from, string $to): string { $a = strtotime($from); $b = strtotime($to); return date('Y-m-d', mt_rand($a, $b)); }

    public static function context(string $route): array {
        $stem = in_array($route, ['APR-001','APR-005','APR-017','APR-018'], true);
        return ['route_code' => $route, 'position' => Oracle::POSITION[$route], 'field' => $stem && self::p(0.5) ? 'OTHER_FIELDS' : 'SOCIAL_HUMANITIES',
            'appointment_mode' => 'NORMAL', 'transition_choice' => 'NOT_REQUESTED', 'policy_revision' => 'GPA-2564-AMENDED-2568-NO3',
            'received_on' => self::date('2026-01-15', '2026-09-30')];
    }
    public static function work(string $id, string $type, string $minQ, string $role, array $ctx, bool $good): array {
        $sub = ['RESEARCH' => 'RESEARCH_ARTICLE', 'ARTICLE' => 'ACADEMIC_ARTICLE', 'BOOK' => 'TEXTBOOK', 'OTHER' => 'CASE_STUDY'][$type];
        $rank = Oracle::RANK[$minQ]; $qs = array_keys(Oracle::RANK); $pn = $good ? 0.012 : 0.05; $pf = $good ? 0.008 : 0.06;
        $q = $good ? $qs[mt_rand($rank - 1, 2)] : self::pick($qs);
        $w = ['work_id' => $id, 'type' => $type, 'subtype' => $sub, 'quality_code' => self::p($pn) ? null : $q,
            'published_on' => self::p($pn) ? null : (self::p($good ? 0.99 : 0.8) ? self::date(Oracle::WINDOW_START, $ctx['received_on']) : self::pick(['2025-11-26', '2025-06-01', date('Y-m-d', strtotime($ctx['received_on'].' +1 day'))])),
            'publication_evidence' => self::tri($pn, $pf), 'peer_review_evidence' => self::tri($pn, $pf), 'participation_certified' => self::tri($pn, $pf), 'definition_verified' => self::tri($pn, $pf)];
        if (in_array($type, ['RESEARCH','ARTICLE'], true)) {
            $w['publication_format'] = 'JOURNAL';
            $intl = $ctx['position'] === 'PROF' ? self::p($good ? 0.99 : 0.85) : self::p(0.4);
            $w['publication_level'] = self::p($pn) ? null : ($intl ? 'INTERNATIONAL' : 'NATIONAL');
            $w['journal_indexes_applicable'] = true;
            $okIdx = $w['publication_level'] === 'NATIONAL' ? ['TCI1'] : ['SCOPUS'];
            $badIdx = $w['publication_level'] === 'NATIONAL' ? (self::p(0.5) ? ['TCI2'] : []) : [];
            $w['journal_indexes'] = self::p($pn) ? null : (self::p($good ? 0.99 : 0.75) ? $okIdx : $badIdx);
        } else { $w['publication_format'] = 'BOOK'; }
        $needFirst = $role !== 'ANY' && $good && self::p(0.98);
        $w['first_author'] = $needFirst ? true : (self::p($pn) ? null : self::p(0.4));
        $w['corresponding_author'] = self::p(0.05) ? null : self::p(0.3);
        $w['co_author'] = self::p(0.05) ? null : !$w['first_author'];
        if ($type !== 'RESEARCH') $w['contribution_percent'] = self::p($pn) ? null : (self::p($good ? 0.99 : 0.7) ? self::pick([50, 60, 75, 100]) : self::pick([30, 40, 49]));
        foreach (Oracle::SUBTYPE_EXTRA[$sub] ?? [] as $k) $w[$k] = self::tri($pn, $pf);
        if ($sub === 'CASE_STUDY') { $w['case_count'] = self::p($pn) ? null : (self::p($good ? 0.99 : 0.8) ? mt_rand(5, 9) : 4); $w['page_count'] = self::p($pn) ? null : (self::p($good ? 0.99 : 0.8) ? mt_rand(80, 150) : 79); }
        return $w;
    }
    public static function wrap(array $ctx, array $works, ?int $votes, array $meta): array {
        $conds = array_fill_keys(['normal_route_eligibility','teaching_and_service','ethics_and_degree_origin','no_prior_work_reuse','valid_three_voter_meeting','no_transition_claim','complete_submission_inventory'], true);
        return ['scope' => SCOPE, 'experiment_conditions' => $conds, 'context' => $ctx,
            'facts' => ['works' => $works, 'reviewer_total' => 3, 'reviewer_votes_pass' => $votes], 'meta' => $meta];
    }
    public static function batch(int $seed, int $n): array {
        mt_srand($seed); $out = []; $routes = array_keys(Oracle::MODELS);
        for ($i = 0; $i < $n; $i++) {
            $route = $routes[$i % count($routes)]; $ctx = self::context($route);
            $alts = Oracle::MODELS[$route]; $alt = $alts[mt_rand(0, count($alts) - 1)];
            $works = []; $k = 0; $good = self::p(0.6);
            foreach ($alt as [$types, $count, $minQ, $role]) for ($c = 0; $c < $count; $c++) {
                if (!$good && self::p(0.12)) continue; // drop a required work
                $works[] = self::work('W'.(++$k), $types[0], $minQ, $role, $ctx, $good || self::p(0.5));
            }
            for ($x = mt_rand(0, 2); $x > 0; $x--) $works[] = self::work('W'.(++$k), self::pick(['RESEARCH','ARTICLE','BOOK','OTHER']), 'B', 'ANY', $ctx, self::p(0.5));
            if (!$works) $works[] = self::work('W1', 'RESEARCH', 'B', 'ANY', $ctx, true);
            $r = mt_rand() / mt_getrandmax();
            $votes = $r < 0.05 ? null : ($r < 0.12 ? mt_rand(0, 1) : mt_rand(2, 3));
            $out[] = self::wrap($ctx, $works, $votes, ['case_id' => sprintf('G%d-%05d', $seed, $i + 1), 'seed' => $seed]);
        }
        return $out;
    }
}

// ================================================================ boundary suite
final class Boundary {
    /** Canonical PASS case for a route: one exactly-qualifying work per slot, all evidence met. */
    public static function base(string $route, int $alt = 0): array {
        $ctx = ['route_code' => $route, 'position' => Oracle::POSITION[$route], 'field' => 'SOCIAL_HUMANITIES', 'appointment_mode' => 'NORMAL',
            'transition_choice' => 'NOT_REQUESTED', 'policy_revision' => 'GPA-2564-AMENDED-2568-NO3', 'received_on' => '2026-06-30'];
        $works = []; $k = 0;
        foreach (Oracle::MODELS[$route][$alt] as [$types, $count, $minQ, $role]) for ($c = 0; $c < $count; $c++) {
            $t = $types[0]; $sub = ['RESEARCH' => 'RESEARCH_ARTICLE', 'ARTICLE' => 'ACADEMIC_ARTICLE', 'BOOK' => 'TEXTBOOK', 'OTHER' => 'CASE_STUDY'][$t];
            $w = ['work_id' => 'W'.(++$k), 'type' => $t, 'subtype' => $sub, 'quality_code' => $minQ, 'published_on' => '2026-03-01',
                'publication_evidence' => true, 'peer_review_evidence' => true, 'participation_certified' => true, 'definition_verified' => true,
                'first_author' => $role !== 'ANY', 'corresponding_author' => false, 'co_author' => $role === 'ANY'];
            if (in_array($t, ['RESEARCH','ARTICLE'], true)) $w += ['publication_format' => 'JOURNAL', 'publication_level' => 'INTERNATIONAL', 'journal_indexes' => ['SCOPUS'], 'journal_indexes_applicable' => true];
            else $w['publication_format'] = 'BOOK';
            if ($t !== 'RESEARCH') $w['contribution_percent'] = 50;
            foreach (Oracle::SUBTYPE_EXTRA[$sub] ?? [] as $x) $w[$x] = true;
            if ($sub === 'CASE_STUDY') { $w['case_count'] = 5; $w['page_count'] = 80; }
            $works[] = $w;
        }
        return [$ctx, $works];
    }
    public static function cases(): array {
        $out = []; $n = 0;
        $add = function (array $ctx, array $works, ?int $votes, string $type, ?string $expect) use (&$out, &$n) {
            $out[] = Gen::wrap($ctx, $works, $votes, ['case_id' => sprintf('B%04d', ++$n), 'btype' => $type, 'expect' => $expect]);
        };
        foreach (array_keys(Oracle::MODELS) as $route) foreach (array_keys(Oracle::MODELS[$route]) as $alt) {
            [$ctx, $W] = self::base($route, $alt);
            $add($ctx, $W, 3, 'BASE_PASS', 'PASS');
            $add($ctx, $W, 2, 'VOTES_AT_2', 'PASS'); $add($ctx, $W, 1, 'VOTES_BELOW_2', 'FAIL'); $add($ctx, $W, null, 'VOTES_MISSING', 'INSUFFICIENT_DATA');
            $m = $W; $m[0]['published_on'] = '2025-11-27'; $add($ctx, $m, 3, 'DATE_AT_WINDOW_START', 'PASS');
            $m = $W; $m[0]['published_on'] = '2025-11-26'; $add($ctx, $m, 3, 'DATE_BEFORE_WINDOW', 'FAIL');
            $m = $W; $m[0]['published_on'] = $ctx['received_on']; $add($ctx, $m, 3, 'DATE_AT_RECEIPT', 'PASS');
            $m = $W; $m[0]['published_on'] = '2026-07-01'; $add($ctx, $m, 3, 'DATE_AFTER_RECEIPT', 'FAIL');
            $m = $W; $m[0]['published_on'] = null; $add($ctx, $m, 3, 'DATE_MISSING', 'INSUFFICIENT_DATA');
            $m = $W; array_pop($m); $add($ctx, $m, 3, 'COUNT_MINUS_ONE', 'FAIL');
            foreach (['publication_evidence','peer_review_evidence','participation_certified','definition_verified'] as $f) {
                $m = $W; $m[0][$f] = false; $add($ctx, $m, 3, 'EVIDENCE_FALSE_'.$f, 'FAIL');
                $m = $W; $m[0][$f] = null; $add($ctx, $m, 3, 'EVIDENCE_NULL_'.$f, 'INSUFFICIENT_DATA');
            }
            foreach ($W as $i => $w) {
                $minQ = $w['quality_code']; $r = Oracle::RANK[$minQ];
                if ($r > 1) { $m = $W; $m[$i]['quality_code'] = array_search($r - 1, Oracle::RANK, true); $add($ctx, $m, 3, 'QUALITY_BELOW_MIN', 'FAIL'); }
                $m = $W; $m[$i]['quality_code'] = null; $add($ctx, $m, 3, 'QUALITY_MISSING', 'INSUFFICIENT_DATA');
                if ($w['first_author']) {
                    $m = $W; $m[$i]['first_author'] = false; $m[$i]['corresponding_author'] = false; $m[$i]['co_author'] = true; $add($ctx, $m, 3, 'ROLE_NOT_MET', 'FAIL');
                    $m = $W; $m[$i]['first_author'] = false; $m[$i]['corresponding_author'] = true; $m[$i]['co_author'] = false; $add($ctx, $m, 3, 'ROLE_CORRESPONDING_ONLY', null);
                    $m = $W; $m[$i]['first_author'] = null; $add($ctx, $m, 3, 'ROLE_MISSING', null);
                }
                if (isset($w['contribution_percent'])) {
                    $m = $W; $m[$i]['contribution_percent'] = 49; $add($ctx, $m, 3, 'CONTRIBUTION_49', 'FAIL');
                    $m = $W; $m[$i]['contribution_percent'] = null; $add($ctx, $m, 3, 'CONTRIBUTION_MISSING', 'INSUFFICIENT_DATA');
                }
                if ($w['subtype'] === 'CASE_STUDY') {
                    $m = $W; $m[$i]['case_count'] = 4; $add($ctx, $m, 3, 'CASES_4', 'FAIL');
                    $m = $W; $m[$i]['page_count'] = 79; $add($ctx, $m, 3, 'PAGES_79', 'FAIL');
                }
                if ($w['type'] === 'RESEARCH' || $w['type'] === 'ARTICLE') {
                    $m = $W; $m[$i]['journal_indexes'] = []; $add($ctx, $m, 3, 'INDEX_NONE', 'FAIL');
                    $m = $W; $m[$i]['journal_indexes'] = null; $add($ctx, $m, 3, 'INDEX_MISSING', 'INSUFFICIENT_DATA');
                    $m = $W; $m[$i]['publication_level'] = 'NATIONAL'; $m[$i]['journal_indexes'] = ['TCI1']; $add($ctx, $m, 3, 'LEVEL_NATIONAL_TCI1', null);
                    $m = $W; $m[$i]['publication_level'] = 'NATIONAL'; $m[$i]['journal_indexes'] = ['TCI2']; $add($ctx, $m, 3, 'LEVEL_NATIONAL_TCI2', null);
                }
                if ($w['type'] === 'RESEARCH') {
                    $m = $W; $m[$i]['first_author'] = false; $m[$i]['corresponding_author'] = false; $m[$i]['co_author'] = false; $add($ctx, $m, 3, 'NO_PARTICIPATION', 'FAIL');
                }
            }
            if (in_array($route, ['APR-001','APR-005','APR-017','APR-018'], true)) { $c2 = $ctx; $c2['field'] = 'OTHER_FIELDS'; $add($c2, $W, 3, 'FIELD_OTHER', 'PASS'); }
            foreach ($W as $i => $w) if (in_array($w['type'], ['BOOK','OTHER'], true)) {
                $m = $W; $m[$i]['publication_format'] = 'JOURNAL'; $add($ctx, $m, 3, 'NONJOURNAL_TYPE_WITH_JOURNAL_FORMAT', 'PASS'); break;
            }
            $m = $W; $m[] = $W[0]; $m[count($m) - 1]['work_id'] = 'WX'; $m[count($m) - 1]['quality_code'] = 'A+'; $add($ctx, $m, 3, 'EXTRA_WORK', 'PASS');
        }
        return $out;
    }
}

// ================================================================ negative (invalid-input) suite
final class Negative {
    public static function cases(): array {
        $out = []; $n = 0;
        $ev = function (array $case, string $type) use (&$out, &$n) { $case['meta'] = ['case_id' => sprintf('N%03d', ++$n), 'unit' => 'evaluator', 'ntype' => $type, 'expect' => 'REJECT']; $out[] = $case; };
        $mk = function (string $route = 'APR-001'): array { [$ctx, $W] = Boundary::base($route); return Gen::wrap($ctx, $W, 3, []); };
        $c = $mk(); $c['context']['position'] = 'ASSOC_PROF'; $ev($c, 'POSITION_NOT_MATCHING_ROUTE');
        $c = $mk(); $c['context']['route_code'] = 'APR-099'; $ev($c, 'UNSUPPORTED_ROUTE');
        $c = $mk(); $c['context']['field'] = 'MEDICINE'; $ev($c, 'UNSUPPORTED_FIELD');
        $c = $mk('APR-002'); $c['context']['field'] = 'OTHER_FIELDS'; $ev($c, 'OTHER_FIELDS_ON_UNREVIEWED_ROUTE');
        $c = $mk(); $c['context']['appointment_mode'] = 'SPECIAL'; $ev($c, 'APPOINTMENT_MODE_NOT_NORMAL');
        $c = $mk(); $c['context']['transition_choice'] = 'REQUESTED'; $ev($c, 'TRANSITION_REQUESTED');
        $c = $mk(); $c['context']['received_on'] = '2025-11-26'; $ev($c, 'RECEIVED_BEFORE_WINDOW');
        $c = $mk(); $c['context']['policy_revision'] = 'GPA-2564'; $ev($c, 'POLICY_REVISION_NOT_2568');
        $c = $mk(); $c['facts']['reviewer_total'] = 5; $ev($c, 'REVIEWER_TOTAL_NOT_3');
        $c = $mk(); $c['facts']['reviewer_votes_pass'] = 4; $ev($c, 'VOTES_ABOVE_TOTAL');
        $c = $mk(); $c['experiment_conditions']['valid_three_voter_meeting'] = false; $ev($c, 'EXPERIMENT_CONDITION_FALSE');
        $c = $mk(); $c['scope'] = 'OTHER_SCOPE'; $ev($c, 'WRONG_SCOPE');
        $c = $mk(); $c['facts']['works'][0]['subtype'] = 'TEXTBOOK'; $ev($c, 'SUBTYPE_TYPE_MISMATCH');
        $c = $mk(); $c['facts']['works'][0]['publication_format'] = 'BOOK'; $ev($c, 'RESEARCH_NOT_IN_JOURNAL');
        // unit-level tests of the per-work policy scope guard
        [$ctx, $W] = Boundary::base('APR-001');
        $u = function (array $ctx, string $type, string $expect) use (&$out, &$n, $W) {
            $out[] = ['context' => $ctx, 'work' => $W[0], 'meta' => ['case_id' => sprintf('N%03d', ++$n), 'unit' => 'workpolicy', 'ntype' => $type, 'expect' => $expect]];
        };
        $u($ctx, 'WORKPOLICY_VALID_CONTEXT', 'COMPONENTS_INSPECTED');
        foreach (['policy_revision' => 'GPA-2564', 'transition_choice' => 'REQUESTED', 'appointment_mode' => 'SPECIAL', 'position' => 'LECTURER', 'field' => 'MEDICINE'] as $k => $bad) {
            $x = $ctx; $x[$k] = $bad; $u($x, 'WORKPOLICY_INVALID_'.strtoupper($k), 'SCOPE_REVIEW_REQUIRED');
        }
        return $out;
    }
}

// ================================================================ mutation operators
final class Mutator {
    /** Heuristic hints for manual equivalence review of surviving mutants (not a substitute for review). */
    public static function hint(array $m, string $code): string {
        if ($m['op'] === 'BOOL' && preg_match('/in_array\(.*,true\)/', $code)) return 'likely equivalent: strict flag of in_array on string/bool values';
        if (preg_match("/positions\[\\\$route\]|transition_choice|appointment_mode|\['ASST_PROF','ASSOC_PROF','PROF'\]|\['SOCIAL_HUMANITIES','OTHER_FIELDS'\]/", $code)) return 'likely unreachable: input guard for valid scoped inputs';
        if (str_contains($m['file'], 'ResearchDraftWorkQuotas') && preg_match("/min_contribution_percent|accepted_journal_indexes|require_publication'\]\?\?false|\\\$percent|\\\$indexes/", $code)) return 'likely unreachable: quota option not used by any model';
        if (str_contains($code, 'RANK = [')) return 'likely equivalent: rank change preserves the order of the minima used (B < A)';
        if (preg_match('/\$ids\[|\$seen\[/', $code)) return 'likely equivalent: isset() is true for a stored false';
        if (str_contains($code, "foreach(['first_author','corresponding_author','publication_evidence']")) return 'likely equivalent: validation of already-valid fields';
        if (str_contains($code, '$type===null')) return 'likely unreachable: type is never null in valid scoped inputs (subtype check rejects it)';
        return 'review: possible test gap';
    }
    /** Lines containing these fragments are excluded (input validation, source-hash and exception code). */
    const SKIP = ['throw new', 'hash_file', 'source_sha256', "'sha256'", 'INVALID_DATA', 'preg_match', 'is_finite', 'array_is_list(', 'count($works)>200', 'count($slots)>200', 'count($alternatives)>20', "'page'=>", 'declare(strict_types', "'production_ready'=>"];
    const STR = ["'MET'" => ["'NOT_MET'"], "'NOT_MET'" => ["'MET'"], "'UNKNOWN'" => ["'MET'"],
        "'2025-11-27'" => ["'2025-11-26'", "'2025-11-28'"],
        "'B'" => ["'A'"], "'A'" => ["'B'", "'A+'"], "'A+'" => ["'A'"],
        "'FIRST_OR_CORRESPONDING'" => ["'ANY'", "'FIRST_ONLY'"], "'FIRST_ONLY'" => ["'ANY'"],
        "'INTERNATIONAL'" => ["'NATIONAL'"], "'NATIONAL'" => ["'INTERNATIONAL'"], "'TCI1'" => ["'TCI2'"], "'TCI2'" => ["'TCI1'"],
        "'SCOPUS'" => ["'SCOPUS_X'"], "'SOCIAL_HUMANITIES'" => ["'OTHER_FIELDS'"],
        "'ASST_PROF'" => ["'ASSOC_PROF'"], "'ASSOC_PROF'" => ["'PROF'"],
        "'KNOWN_SHORTFALL'" => ["'UNKNOWN'"], "'QUOTAS_MET'" => ["'UNKNOWN'"],
        "'FAIL'" => ["'INSUFFICIENT_DATA'"], "'INSUFFICIENT_DATA'" => ["'FAIL'"], "'PASS'" => ["'INSUFFICIENT_DATA'"],
        "'teaching_notes'" => ["'teaching_notes_x'"], "'used_in_teaching'" => ["'used_in_teaching_x'"], "'non_overlapping_cases'" => ["'non_overlapping_cases_x'"],
        "'whole_collection_review'" => ["'whole_collection_review_x'"], "'course_identified'" => ["'course_identified_x'"], "'broad_dissemination'" => ["'broad_dissemination_x'"],
        "'textbook_not_unmodified_handout'" => ["'textbook_not_unmodified_handout_x'"], "'publication_evidence'" => ["'publication_evidence_x'"],
        "'peer_review_evidence'" => ["'peer_review_evidence_x'"], "'participation_certified'" => ["'participation_certified_x'"], "'definition_verified'" => ["'definition_verified_x'"]];

    public static function enumerate(string $app): array {
        $list = [];
        foreach (MUTATED_FILES as $f) {
            $src = file_get_contents("$app/$f"); $toks = token_get_all($src); $lines = explode("\n", $src); $line = 1;
            foreach ($toks as $i => $t) {
                [$id, $text, $ln] = is_array($t) ? $t : [null, $t, $line];
                if (is_array($t)) $line = $t[2]; $ln = $line;
                $code = $lines[$ln - 1] ?? '';
                foreach (self::SKIP as $s) if (str_contains($code, $s)) continue 2;
                $muts = [];
                if ($id === T_IS_GREATER_OR_EQUAL) $muts = [['ROR', '>']];
                elseif ($id === T_IS_SMALLER_OR_EQUAL) $muts = [['ROR', '<']];
                elseif ($id === null && $text === '<') $muts = [['ROR', '<=']];
                elseif ($id === null && $text === '>') $muts = [['ROR', '>=']];
                elseif ($id === T_IS_IDENTICAL) $muts = [['ROR', '!==']];
                elseif ($id === T_IS_NOT_IDENTICAL) $muts = [['ROR', '===']];
                elseif ($id === T_BOOLEAN_AND) $muts = [['LCR', '||']];
                elseif ($id === T_BOOLEAN_OR) $muts = [['LCR', '&&']];
                elseif ($id === null && $text === '!') $muts = [['NEG', '']];
                elseif ($id === T_LNUMBER) { $v = (int)$text; $muts = [['CRP+1', (string)($v + 1)]]; if ($v > 0) $muts[] = ['CRP-1', (string)($v - 1)]; }
                elseif ($id === T_STRING && in_array(strtolower($text), ['true','false'], true)) $muts = [['BOOL', strtolower($text) === 'true' ? 'false' : 'true']];
                elseif ($id === T_CONSTANT_ENCAPSED_STRING && isset(self::STR[$text])) foreach (self::STR[$text] as $r) $muts[] = ['STR', $r];
                foreach ($muts as [$op, $to]) $list[] = ['file' => $f, 'token' => $i, 'line' => $ln, 'op' => $op, 'from' => $text, 'to' => $to];
            }
        }
        return $list;
    }
    public static function apply(string $src, array $m): string {
        $toks = token_get_all($src); $out = '';
        foreach ($toks as $i => $t) { $text = is_array($t) ? $t[1] : $t; $out .= $i === $m['token'] ? $m['to'] : $text; }
        return $out;
    }
}
