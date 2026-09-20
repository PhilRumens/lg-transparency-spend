<?php
/**
 * import_reference.php
 * One-off / re-runnable importer for external reference data joined on lad_code:
 *   - ref_population.csv : lad_code, population, population_year   -> updates ct_council_config
 *   - ref_budget.csv     : lad_code, net_revenue_exp, budget_year -> ct_la_reference (£ thousands)
 *   - ref_imd.csv        : lad_code, imd_avg_score, imd_rank, imd_year -> ct_la_reference
 *
 * CSVs are read from this directory (upload them alongside the script, same as import_fts.php).
 *
 * Browser: visit <your-host>/contracts/import_reference.php (must be logged in).
 * CLI:     php8.1 import_reference.php   (login guard is skipped on CLI)
 *
 * NOTE: only rows whose lad_code exists in ct_council_config are written, so aggregate/junk
 * rows in the source files (E92 England total, waste/fire authorities, etc) are ignored.
 * English IMD/budget only; devolved councils legitimately get NULL budget/IMD.
 */

declare(strict_types=1);
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';
if (PHP_SAPI !== 'cli') {
    require_login();
}

$is_cli = PHP_SAPI === 'cli';

if (!$is_cli) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no');
    echo '<html><head><style>body{font-family:monospace;font-size:13px;background:#1a1a1a;color:#d4d4d4;padding:1rem;white-space:pre-wrap;}.ok{color:#4ec9b0}.err{color:#f48771}.info{color:#9cdcfe}.warn{color:#dcdcaa}</style></head><body>';
    echo str_pad('', 1024) . "\n";
    flush();
}

function out(string $msg, string $class = 'info'): void {
    global $is_cli;
    if ($is_cli) {
        echo '[' . date('H:i:s') . '] ' . $msg . "\n";
    } else {
        echo '<span class="' . $class . '">' . htmlspecialchars('[' . date('H:i:s') . '] ' . $msg) . '</span>' . "\n";
        ob_flush(); flush();
    }
}

/** Locate a CSV in the script's own directory. */
function find_csv(string $name): ?string {
    $p = __DIR__ . '/' . $name;
    return is_file($p) ? $p : null;
}

/** Read a CSV keyed by header names into an array of assoc rows. */
function read_csv(string $path): array {
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) return $rows;
    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return $rows; }
    $header = array_map(fn($h) => trim((string)$h), $header);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) === 1 && trim((string)$r[0]) === '') continue;
        $rows[] = array_combine($header, array_pad($r, count($header), null));
    }
    fclose($fh);
    return $rows;
}

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// Valid lad_codes we actually track (spine for all joins)
$known = [];
foreach ($pdo->query("SELECT lad_code, population_year FROM ct_council_config WHERE lad_code IS NOT NULL AND lad_code <> ''") as $row) {
    $known[$row['lad_code']] = $row['population_year'];
}
out(count($known) . " councils with lad_code in ct_council_config", 'info');

$total_councils = (int)$pdo->query("SELECT COUNT(*) FROM ct_council_config")->fetchColumn();

// ── Population ─────────────────────────────────────────────────────────────
$pop_path = find_csv('ref_population.csv');
if ($pop_path) {
    out("Population: reading $pop_path");
    $rows = read_csv($pop_path);
    $upd = $pdo->prepare("UPDATE ct_council_config SET population = ?, population_year = ? WHERE lad_code = ?");
    $filled = $updated = $skipped_newer = $unknown = 0;
    $filled_names = [];
    // Which were NULL before?
    $was_null = [];
    foreach ($pdo->query("SELECT lad_code, council FROM ct_council_config WHERE population IS NULL AND lad_code IS NOT NULL") as $r) {
        $was_null[$r['lad_code']] = $r['council'];
    }
    foreach ($rows as $r) {
        $lad = trim((string)($r['lad_code'] ?? ''));
        if ($lad === '' || !array_key_exists($lad, $known)) { $unknown++; continue; }
        $pop = (int)($r['population'] ?? 0);
        $yr  = (int)($r['population_year'] ?? 0);
        if ($pop <= 0 || $yr <= 0) continue;
        // Only overwrite when incoming year >= existing (don't clobber newer)
        $existing_yr = $known[$lad] !== null ? (int)$known[$lad] : 0;
        if ($existing_yr > $yr) { $skipped_newer++; continue; }
        $upd->execute([$pop, $yr, $lad]);
        $updated++;
        if (isset($was_null[$lad])) { $filled++; $filled_names[] = $was_null[$lad]; }
    }
    out("Population: $updated updated ($filled previously-NULL now filled, $skipped_newer skipped as older, $unknown non-tracked rows ignored)", 'ok');
    if ($filled_names) out("  Filled: " . implode(', ', array_slice($filled_names, 0, 60)) . (count($filled_names) > 60 ? ' …' : ''), 'warn');
} else {
    out("Population: ref_population.csv not found — skipped", 'warn');
}

// ── Budget + IMD -> ct_la_reference ────────────────────────────────────────
$ref_up = $pdo->prepare("
    INSERT INTO ct_la_reference (lad_code, net_revenue_exp, budget_year, imd_avg_score, imd_rank, imd_year)
    VALUES (:lad, :nre, :byr, :ims, :irk, :iyr)
    ON DUPLICATE KEY UPDATE
        net_revenue_exp = COALESCE(VALUES(net_revenue_exp), net_revenue_exp),
        budget_year     = COALESCE(VALUES(budget_year), budget_year),
        imd_avg_score   = COALESCE(VALUES(imd_avg_score), imd_avg_score),
        imd_rank        = COALESCE(VALUES(imd_rank), imd_rank),
        imd_year        = COALESCE(VALUES(imd_year), imd_year)
");

// Budget
$bud_path = find_csv('ref_budget.csv');
$budget_written = 0;
if ($bud_path) {
    out("Budget: reading $bud_path (net_revenue_exp is £ thousands)");
    $rows = read_csv($bud_path);
    $unknown = 0;
    foreach ($rows as $r) {
        $lad = trim((string)($r['lad_code'] ?? ''));
        if ($lad === '' || !array_key_exists($lad, $known)) { $unknown++; continue; }
        $nre = $r['net_revenue_exp'];
        if ($nre === null || $nre === '') continue;
        $nre = (float)$nre;
        if ($nre <= 0) continue; // ignore negatives/aggregates
        $ref_up->execute([
            ':lad' => $lad, ':nre' => $nre, ':byr' => trim((string)($r['budget_year'] ?? '')) ?: null,
            ':ims' => null, ':irk' => null, ':iyr' => null,
        ]);
        $budget_written++;
    }
    out("Budget: $budget_written councils written ($unknown non-tracked rows ignored)", 'ok');
} else {
    out("Budget: ref_budget.csv not found — skipped", 'warn');
}

// IMD
$imd_path = find_csv('ref_imd.csv');
$imd_written = 0;
if ($imd_path) {
    out("IMD: reading $imd_path");
    $rows = read_csv($imd_path);
    $unknown = 0;
    foreach ($rows as $r) {
        $lad = trim((string)($r['lad_code'] ?? ''));
        if ($lad === '' || !array_key_exists($lad, $known)) { $unknown++; continue; }
        $ims = ($r['imd_avg_score'] ?? '') === '' ? null : (float)$r['imd_avg_score'];
        $irk = ($r['imd_rank'] ?? '') === '' ? null : (int)$r['imd_rank'];
        $iyr = ($r['imd_year'] ?? '') === '' ? null : (int)$r['imd_year'];
        if ($ims === null && $irk === null) continue;
        $ref_up->execute([
            ':lad' => $lad, ':nre' => null, ':byr' => null,
            ':ims' => $ims, ':irk' => $irk, ':iyr' => $iyr,
        ]);
        $imd_written++;
    }
    out("IMD: $imd_written councils written ($unknown non-tracked rows ignored)", 'ok');
} else {
    out("IMD: ref_imd.csv not found — skipped", 'warn');
}

// ── Coverage summary ───────────────────────────────────────────────────────
$cov = $pdo->query("SELECT COUNT(*) t, COUNT(net_revenue_exp) b, COUNT(imd_rank) i FROM ct_la_reference")->fetch();
$pop_cov = (int)$pdo->query("SELECT COUNT(population) FROM ct_council_config")->fetchColumn();
out("──────────────────────────────────────────", 'info');
out("Coverage of $total_councils councils:", 'ok');
out("  Budget (net revenue exp): {$cov['b']}", 'ok');
out("  IMD rank:                 {$cov['i']}", 'ok');
out("  Population (config):      $pop_cov", 'ok');
out("Done.", 'ok');

if (!$is_cli) echo '</body></html>';
