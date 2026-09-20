<?php
// Parallel rebuild of the ct_*_summary* cache tables.
//
// Two modes:
//   (no args)        orchestrator — fans out one child process per table,
//                    throttled to MAX_CONCURRENCY (3) so we stay a good
//                    neighbour on the shared box, then verifies + times.
//   --table=<name>   worker — rebuilds exactly ONE summary table.
//
// Each table is rebuilt into a shadow table (`<t>_rb`) then swapped in with an
// atomic RENAME, so live dashboard/API readers never see an empty or partial
// table (preserves the read-consistency the old single-transaction gave) while
// letting the five tables build concurrently. Tables are partitioned so each
// worker owns exactly one table end-to-end — base INSERT(s) AND that table's
// own additive JOINT_SPLITS follow-up — meaning no two workers ever touch the
// same table and the joint-split ordering is respected without any barrier.
//
// Backup of the previous serial version: rebuild_summaries.php.bak-20260819
// Summary-table snapshots: ct_*_summary*_bak_20260819

require_once __DIR__ . '/../config.php';

const MAX_CONCURRENCY = 3;

// Heaviest first (JOIN / two inserts) so they claim slots earliest.
const TABLES = [
    'ct_supplier_summary_nation_fy',
    'ct_supplier_summary',
    'ct_council_summary_fy',
    'ct_supplier_summary_fy',
    'ct_council_summary',
];

// ---- Parse mode ----------------------------------------------------------
$table = null;
foreach ($argv as $a) {
    if (strpos($a, '--table=') === 0) $table = substr($a, 8);
}

if ($table !== null) {
    exit(run_worker($table));
}
exit(run_orchestrator());

// =========================================================================
// WORKER
// =========================================================================
function run_worker(string $table): int
{
    if (!in_array($table, TABLES, true)) {
        fwrite(STDERR, "unknown table '$table'\n");
        return 2;
    }
    $t0 = microtime(true);
    try {
        $pdo = db();
        $tmp = $table . '_rb';
        $old = $table . '_rbold';

        $pdo->exec("DROP TABLE IF EXISTS `$tmp`");
        $pdo->exec("CREATE TABLE `$tmp` LIKE `$table`");

        build_into($pdo, $table, $tmp);

        // Atomic swap: readers see either fully-old or fully-new, never empty.
        $pdo->exec("DROP TABLE IF EXISTS `$old`");
        $pdo->exec("RENAME TABLE `$table` TO `$old`, `$tmp` TO `$table`");
        $pdo->exec("DROP TABLE `$old`");

        $n  = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        $dt = round(microtime(true) - $t0, 1);
        echo "built $table: $n rows in {$dt}s\n";
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED $table: " . $e->getMessage() . "\n");
        return 1;
    }
}

// Run the table-specific INSERT(s) into the shadow table $tmp.
function build_into(PDO $pdo, string $table, string $tmp): void
{
    // Shared predicate + FY expressions used by base + joint-split inserts.
    $jf = "internal_provider = 0 AND (validation_status IS NULL OR validation_status != 'invalid')";
    $fyExpr = "CASE WHEN CAST(SUBSTRING(period,6,2) AS UNSIGNED) >= 4
                    THEN CAST(SUBSTRING(period,1,4) AS UNSIGNED)
                    ELSE CAST(SUBSTRING(period,1,4) AS UNSIGNED) - 1 END";

    switch ($table) {

    case 'ct_supplier_summary':
        $pdo->exec("
            INSERT INTO `$tmp` (canonical_name, council_count, payment_count, total_spend, contract_count, contract_value, updated_at)
            SELECT t.supplier_canon, COUNT(DISTINCT t.council), COUNT(*), SUM(t.amount),
                   COALESCE(r.contract_count,0), COALESCE(r.contract_value,0), NOW()
            FROM ct_transparency_spend t
            LEFT JOIN (
                SELECT supplier_canon, COUNT(*) as contract_count, SUM(value_amount) as contract_value
                FROM ct_contract_register_entries WHERE is_tech=1 GROUP BY supplier_canon
            ) r ON r.supplier_canon = t.supplier_canon
            WHERE t.supplier_canon IS NOT NULL AND t.supplier_canon != '' AND t.internal_provider = 0 AND (t.validation_status IS NULL OR t.validation_status != 'invalid')
            GROUP BY t.supplier_canon, r.contract_count, r.contract_value
        ");
        return;

    case 'ct_supplier_summary_fy':
        $pdo->exec("
            INSERT INTO `$tmp` (canonical_name, fy_year, council_count, payment_count, total_spend, updated_at)
            SELECT supplier_canon,
                   CASE WHEN MONTH(paid_date) >= 4 THEN YEAR(paid_date) ELSE YEAR(paid_date)-1 END,
                   COUNT(DISTINCT council), COUNT(*), SUM(amount), NOW()
            FROM ct_transparency_spend
            WHERE supplier_canon IS NOT NULL AND supplier_canon != '' AND paid_date IS NOT NULL AND internal_provider = 0 AND (validation_status IS NULL OR validation_status != 'invalid')
            GROUP BY supplier_canon, CASE WHEN MONTH(paid_date) >= 4 THEN YEAR(paid_date) ELSE YEAR(paid_date)-1 END
        ");
        return;

    case 'ct_council_summary_fy':
        $pdo->exec("
            INSERT INTO `$tmp` (lad_code, fy_year, total_spend, payment_count, supplier_count)
            SELECT lad_code,
                   CASE WHEN CAST(SUBSTRING(period,6,2) AS UNSIGNED) >= 4
                        THEN CAST(SUBSTRING(period,1,4) AS UNSIGNED)
                        ELSE CAST(SUBSTRING(period,1,4) AS UNSIGNED) - 1
                   END AS fy,
                   SUM(amount), COUNT(*), COUNT(DISTINCT supplier_canon)
            FROM ct_transparency_spend
            WHERE internal_provider = 0 AND lad_code IS NOT NULL AND lad_code != ''
              AND (validation_status IS NULL OR validation_status != 'invalid')
            GROUP BY lad_code, fy
        ");
        // Joint / shared-service 50/50 attribution (additive) into shadow.
        foreach (JOINT_SPLITS as $jointName => $targets) {
            foreach ($targets as $lad => $share) {
                $pdo->prepare("
                    INSERT INTO `$tmp` (lad_code, fy_year, total_spend, payment_count, supplier_count)
                    SELECT ?, $fyExpr AS fy, SUM(amount)*?, ROUND(COUNT(*)*?), COUNT(DISTINCT supplier_canon)
                    FROM ct_transparency_spend WHERE council = ? AND $jf
                    GROUP BY fy
                    ON DUPLICATE KEY UPDATE
                        total_spend    = total_spend    + VALUES(total_spend),
                        payment_count  = payment_count  + VALUES(payment_count),
                        supplier_count = supplier_count + VALUES(supplier_count)
                ")->execute([$lad, $share, $share, $jointName]);
            }
        }
        return;

    case 'ct_supplier_summary_nation_fy':
        // Regional (single-nation-prefix) rows.
        $pdo->exec("
            INSERT INTO `$tmp` (canonical_name, fy_year, lad_prefix, council_count, payment_count, total_spend, updated_at)
            SELECT ts.supplier_canon,
                   CASE WHEN CAST(SUBSTRING(ts.period,6,2) AS UNSIGNED) >= 4
                        THEN CAST(SUBSTRING(ts.period,1,4) AS UNSIGNED)
                        ELSE CAST(SUBSTRING(ts.period,1,4) AS UNSIGNED) - 1
                   END AS fy,
                   LEFT(ts.lad_code, 1) AS lad_prefix,
                   COUNT(DISTINCT ts.council), COUNT(*), SUM(ts.amount), NOW()
            FROM ct_transparency_spend ts
            LEFT JOIN ct_council_config cc ON cc.lad_code = ts.lad_code
            WHERE ts.supplier_canon IS NOT NULL AND ts.supplier_canon != ''
              AND ts.lad_code IS NOT NULL AND ts.lad_code != '' AND ts.internal_provider = 0
              AND (ts.validation_status IS NULL OR ts.validation_status != 'invalid')
              AND NOT (cc.council_type = 'Strategic Authority' AND cc.region IN ('Scotland','Wales','Northern Ireland'))
            GROUP BY ts.supplier_canon, fy, lad_prefix
        ");
        // UK-wide ('' prefix) rollup.
        $pdo->exec("
            INSERT INTO `$tmp` (canonical_name, fy_year, lad_prefix, council_count, payment_count, total_spend, updated_at)
            SELECT ts.supplier_canon,
                   CASE WHEN CAST(SUBSTRING(ts.period,6,2) AS UNSIGNED) >= 4
                        THEN CAST(SUBSTRING(ts.period,1,4) AS UNSIGNED)
                        ELSE CAST(SUBSTRING(ts.period,1,4) AS UNSIGNED) - 1
                   END AS fy,
                   '' AS lad_prefix,
                   COUNT(DISTINCT ts.council), COUNT(*), SUM(ts.amount), NOW()
            FROM ct_transparency_spend ts
            LEFT JOIN ct_council_config cc ON cc.lad_code = ts.lad_code
            WHERE ts.supplier_canon IS NOT NULL AND ts.supplier_canon != '' AND ts.internal_provider = 0
              AND (ts.validation_status IS NULL OR ts.validation_status != 'invalid')
              AND NOT (cc.council_type = 'Strategic Authority' AND cc.region IN ('Scotland','Wales','Northern Ireland'))
            GROUP BY ts.supplier_canon, fy
            ON DUPLICATE KEY UPDATE council_count=VALUES(council_count), payment_count=VALUES(payment_count), total_spend=VALUES(total_spend), updated_at=VALUES(updated_at)
        ");
        // Joint / shared-service attribution (additive) into shadow.
        foreach (JOINT_SPLITS as $jointName => $targets) {
            foreach ($targets as $lad => $share) {
                $prefix = substr($lad, 0, 1);
                $pdo->prepare("
                    INSERT INTO `$tmp` (canonical_name, fy_year, lad_prefix, council_count, payment_count, total_spend, updated_at)
                    SELECT supplier_canon, $fyExpr AS fy, ?, COUNT(DISTINCT council), ROUND(COUNT(*)*?), SUM(amount)*?, NOW()
                    FROM ct_transparency_spend
                    WHERE council = ? AND supplier_canon IS NOT NULL AND supplier_canon != '' AND $jf
                    GROUP BY supplier_canon, fy
                    ON DUPLICATE KEY UPDATE
                        payment_count = payment_count + VALUES(payment_count),
                        total_spend   = total_spend   + VALUES(total_spend),
                        updated_at    = VALUES(updated_at)
                ")->execute([$prefix, $share, $share, $jointName]);
            }
        }
        return;

    case 'ct_council_summary':
        $pdo->exec("
            INSERT INTO `$tmp` (lad_code, total_spend, payment_count, updated_at)
            SELECT lad_code, SUM(amount), COUNT(*), NOW()
            FROM ct_transparency_spend
            WHERE lad_code IS NOT NULL AND lad_code != ''
              AND internal_provider = 0
              AND (validation_status IS NULL OR validation_status != 'invalid')
            GROUP BY lad_code
        ");
        // Joint / shared-service attribution (additive) into shadow.
        foreach (JOINT_SPLITS as $jointName => $targets) {
            foreach ($targets as $lad => $share) {
                $pdo->prepare("
                    INSERT INTO `$tmp` (lad_code, total_spend, payment_count, updated_at)
                    SELECT ?, SUM(amount)*?, ROUND(COUNT(*)*?), NOW()
                    FROM ct_transparency_spend WHERE council = ? AND $jf
                    ON DUPLICATE KEY UPDATE
                        total_spend   = total_spend   + VALUES(total_spend),
                        payment_count = payment_count + VALUES(payment_count),
                        updated_at    = VALUES(updated_at)
                ")->execute([$lad, $share, $share, $jointName]);
            }
        }
        return;
    }
}

// =========================================================================
// ORCHESTRATOR
// =========================================================================
function run_orchestrator(): int
{
    $t0    = microtime(true);
    $self  = __FILE__;
    $php   = PHP_BINARY;
    $queue = TABLES;
    $running = [];   // table => [proc, pipes]
    $results = [];   // table => exit code
    $buf     = [];   // table => accumulated output

    echo "Parallel rebuild starting (max " . MAX_CONCURRENCY . " concurrent)…\n";

    while ($queue || $running) {
        // Fill free slots.
        while ($queue && count($running) < MAX_CONCURRENCY) {
            $t   = array_shift($queue);
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($self) . ' --table=' . escapeshellarg($t);
            $p   = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($p)) { $results[$t] = 127; continue; }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[$t] = [$p, $pipes];
            $buf[$t] = '';
            echo "  → launched $t\n";
        }
        // Poll running children; drain pipes to avoid buffer-fill deadlock.
        foreach ($running as $t => [$p, $pipes]) {
            $buf[$t] .= stream_get_contents($pipes[1]);
            $buf[$t] .= stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $buf[$t] .= stream_get_contents($pipes[1]);
                $buf[$t] .= stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                proc_close($p);
                $results[$t] = $st['exitcode'];
                echo "  ✓ " . trim($buf[$t]) . "\n";
                unset($running[$t]);
            }
        }
        if ($running) usleep(200000);
    }

    // Verify + report.
    $fail = array_filter($results, fn($c) => $c !== 0);
    $dt   = round(microtime(true) - $t0, 1);
    if ($fail) {
        fwrite(STDERR, "REBUILD FAILED for: " . implode(', ', array_keys($fail)) . " (in {$dt}s)\n");
        fwrite(STDERR, "Backups intact: ct_*_summary*_bak_20260819\n");
        return 1;
    }
    $pdo = db();
    $x = $pdo->query("SELECT ROUND(total_spend) FROM ct_supplier_summary WHERE canonical_name LIKE '%XMA%'")->fetchColumn();
    echo "REBUILD DONE in {$dt}s. XMA national total now £" . number_format((float)$x) . "\n";
    return 0;
}
