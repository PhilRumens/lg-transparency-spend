<?php
/**
 * import_geo.php
 * One-off / re-runnable importer for LAD centroid coordinates, joined on lad_code.
 *   ref_la_centroids.csv : lad_code, lad_name, lat, lon  -> ct_la_geo
 *
 * Source: ONS Open Geography LAD_MAY_2025_UK_BFC_V2 (LONG/LAT attribute fields),
 * plus a manual Erewash alias (our E07000049 -> ONS E07000036 centroid).
 *
 * Mirrors import_reference.php conventions: CSV read from ~/ or contracts dir,
 * only rows whose lad_code exists in ct_council_config are written.
 * CLI:  php8.2 import_geo.php
 */
declare(strict_types=1);
set_time_limit(0);
require_once __DIR__ . '/config.php';
if (PHP_SAPI !== 'cli') { require_login(); }
$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

function out(string $m): void { echo '[' . date('H:i:s') . '] ' . $m . "\n"; }

function find_csv(string $name): ?string {
    $p = __DIR__ . '/' . $name;
    return is_file($p) ? $p : null;
}

// Create table if absent
$pdo->exec("CREATE TABLE IF NOT EXISTS ct_la_geo (
    lad_code   VARCHAR(9)  NOT NULL PRIMARY KEY,
    lad_name   VARCHAR(100) NULL,
    lat        DECIMAL(8,5) NOT NULL,
    lon        DECIMAL(8,5) NOT NULL,
    source     VARCHAR(30)  NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
out("ct_la_geo ready");

$path = find_csv('ref_la_centroids.csv');
if (!$path) { out("ERROR: ref_la_centroids.csv not found"); exit(1); }
out("reading $path");

// Valid lad_codes from config (only write matches, like import_reference.php)
$valid = array_flip($pdo->query("SELECT lad_code FROM ct_council_config WHERE lad_code IS NOT NULL AND lad_code != ''")->fetchAll(PDO::FETCH_COLUMN));

$fh = fopen($path, 'r');
$header = fgetcsv($fh);
$header = array_map(fn($h) => trim((string)$h), $header);
$idx = array_flip($header);
$ins = $pdo->prepare("INSERT INTO ct_la_geo (lad_code, lad_name, lat, lon, source)
    VALUES (:c,:n,:lat,:lon,'ONS LAD_MAY_2025_UK_BFC_V2')
    ON DUPLICATE KEY UPDATE lad_name=VALUES(lad_name), lat=VALUES(lat), lon=VALUES(lon), source=VALUES(source)");
$written = 0; $skipped_nomatch = 0;
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) === 1 && trim((string)$r[0]) === '') continue;
    $code = trim((string)($r[$idx['lad_code']] ?? ''));
    if ($code === '') continue;
    if (!isset($valid[$code])) { $skipped_nomatch++; continue; }
    $ins->execute([
        ':c'   => $code,
        ':n'   => trim((string)($r[$idx['lad_name']] ?? '')),
        ':lat' => (float)($r[$idx['lat']] ?? 0),
        ':lon' => (float)($r[$idx['lon']] ?? 0),
    ]);
    $written++;
}
fclose($fh);
out("written/updated: $written; skipped (not in config): $skipped_nomatch");

// Coverage report
$tot = $pdo->query("SELECT COUNT(*) FROM ct_la_geo")->fetchColumn();
$with_contracts = $pdo->query("SELECT COUNT(DISTINCT cc.lad_code)
    FROM ct_contract_register_entries e
    JOIN ct_council_config cc ON cc.council=e.council
    JOIN ct_la_geo g ON g.lad_code=cc.lad_code
    WHERE e.is_tech=1 AND e.end_date >= CURDATE()")->fetchColumn();
out("ct_la_geo total rows: $tot");
out("councils w/ future tech contracts AND coords: $with_contracts");
