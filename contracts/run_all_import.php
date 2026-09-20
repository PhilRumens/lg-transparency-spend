#!/usr/bin/env php8.1
<?php
/**
 * Force re-import all councils with active sources, sequentially.
 * Usage: php8.1 run_all_import.php [--dry-run]
 *
 * Shows per-council progress and a final summary.
 * Pass --dry-run to print the council list without importing.
 */

require_once __DIR__ . '/config.php';
$pdo = db();

$dry_run = in_array('--dry-run', $argv ?? []);

$councils = $pdo->query(
    "SELECT DISTINCT council FROM ct_spend_sources WHERE active=1 ORDER BY council"
)->fetchAll(PDO::FETCH_COLUMN);

$total   = count($councils);
$results = [];
$started = microtime(true);

echo str_repeat('─', 70) . "\n";
echo "Force re-import: $total councils\n";
if ($dry_run) echo "(dry run — no imports will run)\n";
echo str_repeat('─', 70) . "\n\n";

foreach ($councils as $i => $council) {
    $n      = $i + 1;
    $prefix = sprintf("[%d/%d]", $n, $total);

    echo sprintf("%-10s %s\n", $prefix, $council);
    flush();

    if ($dry_run) {
        $results[] = ['council' => $council, 'payments' => null, 'value' => null, 'secs' => 0, 'error' => false];
        continue;
    }

    $t0  = microtime(true);
    $cmd = sprintf(
        "php8.1 %s --council=%s --force 2>&1",
        escapeshellarg(__DIR__ . '/import_transparency.php'),
        escapeshellarg($council)
    );
    $out = [];
    exec($cmd, $out, $rc);

    $secs     = round(microtime(true) - $t0, 1);
    $payments = null;
    $value    = null;
    $error    = $rc !== 0;

    foreach ($out as $line) {
        // "  Tech payments    : 1,547"
        if (preg_match('/Tech payments\s*:\s*([\d,]+)/', $line, $m)) {
            $payments = (int) str_replace(',', '', $m[1]);
        }
        // "  Total value      : £6,569,491 (Council Name)"
        if (preg_match('/Total value\s*:\s*£([\d,]+)/', $line, $m)) {
            $value = (int) str_replace(',', '', $m[1]);
        }
        // Catch errors / warnings
        if (str_contains($line, 'Fatal error') || str_contains($line, 'Uncaught')) {
            $error = true;
        }
    }

    $status = $error ? '  ERROR'
            : ($payments === null ? '  (no output)'
            : sprintf("  → %s payments, £%s  (%ss)",
                number_format($payments),
                $value !== null ? number_format($value / 1e6, 2) . 'M' : '?',
                $secs));

    echo $status . "\n";
    flush();

    $results[] = [
        'council'  => $council,
        'payments' => $payments,
        'value'    => $value,
        'secs'     => $secs,
        'error'    => $error,
    ];
}

$elapsed = round(microtime(true) - $started);

echo "\n" . str_repeat('─', 70) . "\n";
echo sprintf("Done in %dm%ds\n\n", intdiv($elapsed, 60), $elapsed % 60);

if (!$dry_run) {
    $errors   = array_filter($results, fn($r) => $r['error']);
    $zero     = array_filter($results, fn($r) => !$r['error'] && $r['payments'] === 0);
    $ok       = array_filter($results, fn($r) => !$r['error'] && $r['payments'] > 0);
    $total_payments = array_sum(array_column(iterator_to_array((function() use ($results) { foreach ($results as $r) if ($r['payments'] !== null) yield $r; })(), false), 'payments'));

    echo sprintf("Imported:  %d councils, %s payments total\n", count($ok), number_format($total_payments));
    if ($zero)   echo sprintf("Zero rows: %d councils\n", count($zero));
    if ($errors) echo sprintf("Errors:    %d councils\n", count($errors));

    if ($zero) {
        echo "\nZero-payment councils:\n";
        foreach ($zero as $r) echo "  " . $r['council'] . "\n";
    }
    if ($errors) {
        echo "\nError councils:\n";
        foreach ($errors as $r) echo "  " . $r['council'] . "\n";
    }
}
