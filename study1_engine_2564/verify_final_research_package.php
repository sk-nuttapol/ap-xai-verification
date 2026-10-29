<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$batchId = isset($argv[1]) ? (int)$argv[1] : 20;
if ($batchId < 1) {
    throw new InvalidArgumentException('batch id must be positive');
}

$packageDir = __DIR__ . '/../files/research/final_batch_' . $batchId;
$metadataPath = $packageDir . '/metadata.json';
$manifestPath = $packageDir . '/SHA256SUMS.txt';
$errors = [];
$checks = [];

if (!is_dir($packageDir)) {
    throw new RuntimeException('package directory not found: ' . $packageDir);
}

$metadata = json_decode((string)file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
if ((int)($metadata['batch']['batch_id'] ?? 0) !== $batchId) {
    $errors[] = 'metadata batch id mismatch';
}
if (($metadata['scope'] ?? '') !== '100% synthetic data; no human participants; no personal data') {
    $errors[] = 'metadata scope declaration mismatch';
}
$checks[] = 'metadata and synthetic-only scope';

$manifestLines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($manifestLines === false) {
    throw new RuntimeException('cannot read SHA256SUMS.txt');
}
foreach ($manifestLines as $line) {
    if (!preg_match('~^([a-f0-9]{64})  ([^\\\\/]+)$~', $line, $matches)) {
        $errors[] = 'invalid manifest line: ' . $line;
        continue;
    }
    $path = $packageDir . '/' . $matches[2];
    if (!is_file($path)) {
        $errors[] = 'missing package file: ' . $matches[2];
        continue;
    }
    if (!hash_equals($matches[1], hash_file('sha256', $path))) {
        $errors[] = 'SHA-256 mismatch: ' . $matches[2];
    }
}
$checks[] = 'SHA-256 manifest (' . count($manifestLines) . ' files)';

foreach (($metadata['row_counts'] ?? []) as $file => $expectedRows) {
    $path = $packageDir . '/' . basename((string)$file);
    if (!is_file($path)) {
        $errors[] = 'missing CSV file: ' . $file;
        continue;
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        $errors[] = 'cannot read CSV file: ' . $file;
        continue;
    }
    $lines = 0;
    while (fgets($handle) !== false) {
        $lines++;
    }
    fclose($handle);
    $actualRows = max(0, $lines - 1);
    if ($actualRows !== (int)$expectedRows) {
        $errors[] = sprintf('%s row count mismatch: expected %d, got %d', $file, $expectedRows, $actualRows);
    }
    $checks[] = sprintf('%s rows: %d', $file, $actualRows);
}

$pdo = Database::pdo();
$batch = ResearchSimulation::batch($pdo, $batchId);
$databaseHash = (string)($batch['batch_hash'] ?? '');
$metadataHash = (string)($metadata['batch']['batch_hash'] ?? '');
$rerunHash = ResearchSimulation::rerunHash($pdo, $batchId);
if (($batch['status'] ?? '') !== 'COMPLETED') {
    $errors[] = 'database batch is not completed';
}
if ($databaseHash === '' || !hash_equals($databaseHash, $metadataHash)) {
    $errors[] = 'database and metadata batch hashes differ';
}
if ($databaseHash === '' || !hash_equals($databaseHash, $rerunHash)) {
    $errors[] = 'database rerun hash mismatch';
}
$checks[] = 'database status and reproducibility hash';

foreach ($checks as $check) {
    echo '[OK] ' . $check . PHP_EOL;
}
if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, '[FAIL] ' . $error . PHP_EOL);
    }
    exit(1);
}

echo sprintf('[PASS] Final research package for batch %d is complete and reproducible.%s', $batchId, PHP_EOL);
