<?php
// CLI test harness for the expiry-cluster engine.
// Usage: php8.2 cluster_cli.php [radius_km] [window_months] [min_councils]
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../cluster_lib.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$opts = [
    'radius_km'     => (float)($argv[1] ?? 40),
    'window_months' => (int)  ($argv[2] ?? 9),
    'min_councils'  => (int)  ($argv[3] ?? 3),
];
fwrite(STDOUT, sprintf("radius=%skm window=%smo min_councils=%d\n\n",
    $opts['radius_km'], $opts['window_months'], $opts['min_councils']));

$t0 = microtime(true);
$clusters = find_expiry_clusters($pdo, $opts);
$elapsed = round((microtime(true) - $t0) * 1000);

fwrite(STDOUT, count($clusters) . " clusters found in {$elapsed}ms\n");
fwrite(STDOUT, str_repeat('=', 70) . "\n\n");

foreach (array_slice($clusters, 0, 20) as $i => $c) {
    fwrite(STDOUT, sprintf("#%d  score=%.1f  %s  [%s]\n",
        $i + 1, $c['score'], $c['product_name'], "held by {$c['ubiq_count']} councils nationally"));
    fwrite(STDOUT, sprintf("    %d councils | expiries %s → %s (span %d days) | £%s total\n",
        $c['n_councils'], $c['earliest_end'], $c['latest_end'], $c['span_days'],
        number_format($c['total_value'])));
    foreach ($c['councils'] as $m) {
        fwrite(STDOUT, sprintf("      - %-42s exp %s  £%s\n",
            $m['council'], $m['end_date'], number_format($m['value'])));
    }
    fwrite(STDOUT, "\n");
}
