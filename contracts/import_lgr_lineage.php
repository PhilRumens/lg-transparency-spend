<?php
/**
 * import_lgr_lineage.php — load the English local-government reorganisation
 * lineage (Merge/Split + Constituent-partner edges) from the node/edge CSVs
 * into ct_council_lineage, ct_council_ca and stub ct_council_config rows.
 *
 * CLI only. Idempotent — safe to re-run (clears its own rows first).
 *
 * Usage:
 *   php import_lgr_lineage.php data/local-government_all_nodes.csv data/local-government_all_edges.csv
 */
if (php_sapi_name() !== 'cli') { header('HTTP/1.1 403 Forbidden'); exit; }
require_once __DIR__ . '/config.php';

$nodes_path = $argv[1] ?? (__DIR__ . '/data/local-government_all_nodes.csv');
$edges_path = $argv[2] ?? (__DIR__ . '/data/local-government_all_edges.csv');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

/* ---- helpers ---------------------------------------------------------- */
function read_csv(string $path): array {
    $rows = [];
    if (($fh = fopen($path, 'r')) === false) { fwrite(STDERR, "cannot open $path\n"); exit(1); }
    $hdr = fgetcsv($fh);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) === 1 && trim($r[0]) === '') continue;
        $rows[] = array_combine($hdr, array_pad($r, count($hdr), null));
    }
    fclose($fh);
    return $rows;
}
function norm_region(?string $r): string {
    $r = trim((string)$r);
    // Node file uses "The Humber"; config uses "the Humber".
    if (strcasecmp($r, 'Yorkshire and The Humber') === 0) return 'Yorkshire and the Humber';
    return $r;
}
/** Parse status + go-live year out of a provisional successor name. */
function parse_status(string $name, bool $has_gss): array {
    $year = null;
    if (preg_match('/\b(20\d{2})\b/', $name, $m)) $year = (int)$m[1];
    $lname = strtolower($name);
    if (strpos($lname, 'tbd') !== false) return ['tbd', $year];
    if (strpos($lname, 'proposed') !== false) return ['proposed', $year];
    // A successor that already exists as a real authority (carries a GSS code)
    // is treated as live; everything else in the White Paper wave is proposed.
    return [$has_gss ? 'live' : 'proposed', $year];
}

/* ---- load nodes: name -> GSS, name -> region -------------------------- */
$nodes = read_csv($nodes_path);
$gss_by_name = [];
$region_by_name = [];
$gss_by_id = [];   // Node ID -> GSS. The node file can hold TWO nodes with the
                   // same Name (e.g. "South Staffordshire Council" exists as both
                   // an existing Non-metropolitan District WITH a GSS and the
                   // PROPOSED English Unitary Authority WITHOUT one). Name lookup
                   // can't tell them apart; edges reference the right node by ID.
foreach ($nodes as $n) {
    $name = trim($n['Name'] ?? '');
    $id   = trim($n['Node ID'] ?? '');
    if ($id !== '' && !empty($n['GSS Code'])) $gss_by_id[$id] = trim($n['GSS Code']);
    if ($name === '') continue;
    if (!empty($n['GSS Code'])) $gss_by_name[$name] = trim($n['GSS Code']);
    if (!empty($n['Region']))   $region_by_name[$name] = norm_region($n['Region']);
}

/* ---- patch known-missing predecessor GSS from ct_council_config ------- */
// Elmbridge / Tendring have empty GSS in the node file; resolve by exact name.
$cfg_by_name = [];
$stmt = $pdo->query("SELECT council, lad_code, region FROM ct_council_config WHERE lad_code IS NOT NULL AND lad_code<>''");
foreach ($stmt as $c) $cfg_by_name[$c['council']] = $c;
foreach (['Elmbridge Borough Council', 'Tendring District Council'] as $nm) {
    if (empty($gss_by_name[$nm]) && isset($cfg_by_name[$nm])) {
        $gss_by_name[$nm] = $cfg_by_name[$nm]['lad_code'];
        fwrite(STDERR, "patched GSS for $nm -> {$cfg_by_name[$nm]['lad_code']}\n");
    }
}

/* ---- load edges ------------------------------------------------------- */
$edges = read_csv($edges_path);

// Merge/Split edges: predecessor (From) -> successor unitary (To)
$merges = [];
$successors = [];   // succ_name => ['region_votes'=>[], 'gss'=>?string]
foreach ($edges as $e) {
    $etype = trim($e['Edge Type'] ?? '');
    if ($etype !== 'Merge/Split') continue;
    $pred = trim($e['From Name']);
    $succ = trim($e['To Name']);
    if ($pred === '' || $succ === '') continue;
    $merges[] = ['pred' => $pred, 'succ' => $succ];
    if (!isset($successors[$succ])) {
        // Resolve the successor's GSS from the edge's TO ID node — this
        // disambiguates same-named nodes. A proposed unitary node has no GSS
        // even when an existing district shares its name.
        $succ_gss = $gss_by_id[trim($e['To ID'] ?? '')] ?? null;
        $successors[$succ] = ['region_votes' => [], 'gss' => $succ_gss];
    }
    $pr = $region_by_name[$pred] ?? ($cfg_by_name[$pred]['region'] ?? null);
    if ($pr) $successors[$succ]['region_votes'][] = $pr;
}

/* ---- assign synthetic keys to successors ------------------------------ */
// Deterministic order (alphabetical) so keys are stable across re-runs.
$succ_names = array_keys($successors);
sort($succ_names, SORT_STRING);
// Build the reverse map: which existing config councils own each lad_code.
$cfg_name_by_lad = [];
foreach ($cfg_by_name as $nm => $c) $cfg_name_by_lad[$c['lad_code']] = $nm;

$succ_key = [];
$i = 0;
foreach ($succ_names as $s) {
    // GSS resolved from the successor's own TO-ID node (proposed unitaries have
    // none, even when an existing district shares the name).
    $gss = $successors[$s]['gss'] ?? null;
    // A successor may reuse an existing GSS as its key ONLY when the config row
    // at that GSS is the SAME council (same name) — i.e. it genuinely already
    // exists (Brighton/Plymouth/Torbay). Otherwise mint a synthetic LGR key.
    if ($gss && isset($cfg_name_by_lad[$gss]) && $cfg_name_by_lad[$gss] === $s) {
        $succ_key[$s] = $gss;               // genuinely the same live authority
    } else {
        $succ_key[$s] = sprintf('LGR%06d', ++$i);
    }
}

/* ---- write everything in a transaction -------------------------------- */
$pdo->beginTransaction();

// Clear our own previous rows (idempotent re-run). Only delete stub config
// rows we minted (LGR* keys) — NEVER delete by council_type, which could nuke a
// real council row whose type we (previously, buggily) flipped.
$pdo->exec("DELETE FROM ct_council_lineage");
$pdo->exec("DELETE FROM ct_council_ca");
$pdo->exec("DELETE FROM ct_council_config WHERE lad_code LIKE 'LGR%'");

// Stub successor rows in ct_council_config.
$ins_cfg = $pdo->prepare("
    INSERT INTO ct_council_config
      (council, lad_code, council_type, region, combined_authority)
    VALUES (?, ?, 'Unitary Authority (proposed)', ?, 'No current strategic authority')
    ON DUPLICATE KEY UPDATE council_type=VALUES(council_type)
");
foreach ($succ_names as $s) {
    $key = $succ_key[$s];
    $votes = $successors[$s]['region_votes'];
    $region = '';
    if ($votes) { $c = array_count_values($votes); arsort($c); $region = array_key_first($c); }
    // Skip stubbing successors that already exist as a real config row under
    // the SAME name — i.e. we assigned them their own live GSS as the key.
    // Synthetic LGR keys always get a stub row.
    if (strncmp($key, 'LGR', 3) !== 0 && isset($cfg_name_by_lad[$key]) && $cfg_name_by_lad[$key] === $s) continue;
    $ins_cfg->execute([$s, $key, $region]);
}

// Lineage rows.
$ins_lin = $pdo->prepare("
    INSERT INTO ct_council_lineage
      (pred_lad_code, pred_name, succ_key, succ_name, status, go_live_year, edge_type)
    VALUES (?, ?, ?, ?, ?, ?, 'Merge/Split')
    ON DUPLICATE KEY UPDATE succ_name=VALUES(succ_name), status=VALUES(status), go_live_year=VALUES(go_live_year)
");
$unmatched = [];
foreach ($merges as $m) {
    $pred = $m['pred']; $succ = $m['succ'];
    $pred_lad = $gss_by_name[$pred] ?? null;
    if (!$pred_lad) $unmatched[] = $pred;
    // "live" only when the successor genuinely already exists — i.e. its own
    // TO-ID node carries a GSS AND we kept that GSS as its key (not an LGR stub).
    $succ_is_live = strncmp($succ_key[$succ], 'LGR', 3) !== 0;
    [$status, $year] = parse_status($succ, $succ_is_live);
    $ins_lin->execute([$pred_lad, $pred, $succ_key[$succ], $succ, $status, $year]);
}

// Constituent-partner (combined authority) layer. Normalise the "Partmer" typo.
$ins_ca = $pdo->prepare("
    INSERT INTO ct_council_ca (lad_code, ca_name, partner_type)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE partner_type=VALUES(partner_type)
");
$ca_written = 0;
foreach ($edges as $e) {
    $etype = trim($e['Edge Type'] ?? '');
    if (stripos($etype, 'onstituent') === false) continue; // catches "partner" and "Partmer"
    $ptype = (stripos($etype, 'non-') === 0) ? 'Non-constituent partner' : 'Constituent partner';
    $ca   = trim($e['From Name']);   // From = the combined authority
    $unit = trim($e['To Name']);     // To   = the member unitary/borough
    if ($ca === '' || $unit === '') continue;
    // Resolve the member to a lad_code: its own GSS, else a successor synthetic key.
    $lad = $gss_by_name[$unit] ?? ($succ_key[$unit] ?? null);
    if (!$lad) continue;
    $ins_ca->execute([$lad, $ca, $ptype]);
    $ca_written++;
}

$pdo->commit();

/* ---- report ----------------------------------------------------------- */
$n_lin  = $pdo->query("SELECT COUNT(*) FROM ct_council_lineage")->fetchColumn();
$n_succ = $pdo->query("SELECT COUNT(DISTINCT succ_key) FROM ct_council_lineage")->fetchColumn();
$n_stub = $pdo->query("SELECT COUNT(*) FROM ct_council_config WHERE council_type='Unitary Authority (proposed)'")->fetchColumn();
$n_ca   = $pdo->query("SELECT COUNT(*) FROM ct_council_ca")->fetchColumn();

echo "lineage rows:      $n_lin\n";
echo "distinct succ:     $n_succ\n";
echo "stub config rows:  $n_stub\n";
echo "CA partner rows:   $n_ca (written $ca_written)\n";
echo "unmatched preds:   " . count($unmatched) . (count($unmatched) ? ' -> '.implode(', ', array_unique($unmatched)) : '') . "\n";
