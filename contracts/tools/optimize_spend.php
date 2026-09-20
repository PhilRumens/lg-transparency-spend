<?php
// OPTIMIZE TABLE ct_transparency_spend — index/data defrag (user-approved 2026-08-03).
// InnoDB: rebuilds table into fresh copy + rebuilds all indexes, atomic swap. Table locked for duration.
// No imports running (verified). DB on /dev/vda1 with 611GB free — ample headroom for the temp copy.
require_once __DIR__ . '/../config.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$b = $pdo->query("SELECT ROUND(data_length/1024/1024,1) d, ROUND(index_length/1024/1024,1) i, ROUND((data_length+index_length)/1024/1024,1) t FROM information_schema.tables WHERE table_schema='architect' AND table_name='ct_transparency_spend'")->fetch();
echo "BEFORE: data {$b['d']}MB / idx {$b['i']}MB / total {$b['t']}MB\n";

$t0 = microtime(true);
foreach ($pdo->query("OPTIMIZE TABLE ct_transparency_spend") as $row) {
    echo "  " . implode(" | ", $row) . "\n";
}
$secs = round(microtime(true) - $t0, 1);
echo "OPTIMIZE took {$secs}s\n";

// information_schema stats can be stale right after; ANALYZE to refresh, then re-read
$pdo->query("ANALYZE TABLE ct_transparency_spend")->fetchAll();
$a = $pdo->query("SELECT ROUND(data_length/1024/1024,1) d, ROUND(index_length/1024/1024,1) i, ROUND((data_length+index_length)/1024/1024,1) t FROM information_schema.tables WHERE table_schema='architect' AND table_name='ct_transparency_spend'")->fetch();
echo "AFTER:  data {$a['d']}MB / idx {$a['i']}MB / total {$a['t']}MB\n";
echo "RECLAIMED: " . round($b['t'] - $a['t'], 1) . "MB\n";
