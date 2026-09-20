<?php
/**
 * sniff_candidates.php — READ-ONLY. Builds the sniffer work-queue: councils whose
 * published transparency data is (probably) behind, so only those get fetched in Phase 2.
 *
 * Writes NOTHING to the DB. Emits a TSV work-queue file + a summary to STDOUT.
 *
 * Usage:
 *   php ~/scripts/sniff_candidates.php                 # England only (default)
 *   php ~/scripts/sniff_candidates.php --devolved      # England + Scotland/Wales/NI
 *   php ~/scripts/sniff_candidates.php --all           # every region
 *   php ~/scripts/sniff_candidates.php --side=spend    # spend only (default: both)
 *   php ~/scripts/sniff_candidates.php --side=register # register only
 *   php ~/scripts/sniff_candidates.php --out=/path.tsv # override output path
 */

require_once __DIR__ . '/../config.php';
$pdo = db();

// ---- args ----
$argv_join = implode(' ', array_slice($argv, 1));
$scope = 'england';                       // england | devolved | all
if (strpos($argv_join, '--devolved') !== false) $scope = 'devolved';
if (strpos($argv_join, '--all') !== false)      $scope = 'all';
$side = 'both';
if (preg_match('/--side=(spend|register|both)/', $argv_join, $m)) $side = $m[1];
$out = null;
if (preg_match('/--out=(\S+)/', $argv_join, $m)) $out = $m[1];
if ($out === null) $out = getenv('HOME') . '/sniffer_queue_' . date('Ymd') . '.tsv';

$DEVOLVED = ['Scotland', 'Wales', 'Northern Ireland'];

// Councils to EXCLUDE from the sniffer entirely (special-case imports the sniffer must not flag).
// Adur/Worthing = joint arrangement, 3 CSVs/period split 50/50 — handled manually, not pollable here.
$SNIFF_EXCLUDE = [
    'Adur District Council', 'Worthing Borough Council', 'Adur and Worthing Councils Joint',
];

// Known dead-source / never-force councils — annotated, NOT dropped (report-only tool).
$DEAD_SOURCE = [
    'East Riding of Yorkshire Council', 'City of York Council', 'Manchester City Council',
    'Derbyshire Dales District Council', 'Lancaster City Council', 'Bedford Borough Council',
    'East Devon District Council', 'Durham County Council',
];

// ---- helpers ----
function period_to_idx($p) {            // 'YYYY-MM' -> integer month index
    if (!preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) return null;
    return ((int)$m[1]) * 12 + ((int)$m[2]) - 1;
}
function idx_to_period($idx) {
    $y = intdiv($idx, 12); $mo = ($idx % 12) + 1;
    return sprintf('%04d-%02d', $y, $mo);
}
// modal gap (in months) between the last N distinct periods -> inferred cadence
function infer_cadence(array $periods) {
    $idx = [];
    foreach ($periods as $p) { $i = period_to_idx($p); if ($i !== null) $idx[] = $i; }
    $idx = array_values(array_unique($idx));
    sort($idx);
    if (count($idx) < 3) return [1, 'low-confidence'];   // assume monthly, flag it
    $idx = array_slice($idx, -13);                        // last ~12 gaps
    $gaps = [];
    for ($k = 1; $k < count($idx); $k++) { $g = $idx[$k] - $idx[$k-1]; if ($g > 0) $gaps[] = $g; }
    if (!$gaps) return [1, 'low-confidence'];
    $counts = array_count_values($gaps);
    arsort($counts);
    $cad = (int)array_key_first($counts);
    // snap to the usual publishing rhythms
    if ($cad <= 1) $cad = 1; elseif ($cad <= 2) $cad = 1; elseif ($cad <= 4) $cad = 3;
    elseif ($cad <= 7) $cad = 6; else $cad = 12;
    return [$cad, 'ok'];
}

// ---- scope predicate ----
function region_clause($scope, $DEVOLVED, $pdo) {
    if ($scope === 'all') return ['1=1', []];
    $ph = implode(',', array_fill(0, count($DEVOLVED), '?'));
    if ($scope === 'devolved') return ["c.region IN ($ph)", $DEVOLVED];
    return ["c.region NOT IN ($ph)", $DEVOLVED];   // england (default)
}

$now_idx = period_to_idx(date('Y-m'));
$rows_out = [];   // TSV rows
$stats = ['spend_checked'=>0, 'spend_behind'=>0, 'register_checked'=>0, 'register_behind'=>0];

// ================= SPEND =================
if ($side === 'both' || $side === 'spend') {
    [$rc, $rp] = region_clause($scope, $DEVOLVED, $pdo);
    $sql = "SELECT c.council, c.region, c.listing_url
              FROM ct_council_config c
             WHERE c.not_pollable = 0 AND $rc
               AND EXISTS (SELECT 1 FROM ct_spend_sources s WHERE s.council = c.council AND s.active = 1)
             ORDER BY c.council";
    $st = $pdo->prepare($sql); $st->execute($rp);
    $councils = $st->fetchAll(PDO::FETCH_ASSOC);

    $pst = $pdo->prepare("SELECT DISTINCT period FROM ct_spend_sources WHERE council = ? AND active = 1 ORDER BY period");
    foreach ($councils as $c) {
        if (in_array($c['council'], $SNIFF_EXCLUDE, true)) continue;
        $pst->execute([$c['council']]);
        $periods = array_column($pst->fetchAll(PDO::FETCH_ASSOC), 'period');
        if (!$periods) continue;
        $stats['spend_checked']++;
        $held = end($periods);
        $held_idx = period_to_idx($held);
        [$cad, $conf] = infer_cadence($periods);
        $lag = ($cad <= 1) ? 2 : 3;                  // publication grace, months
        $offset = $cad + $lag;
        $expected_idx = $now_idx - $offset;
        if ($held_idx !== null && $held_idx < $expected_idx) {
            $stats['spend_behind']++;
            $dead = in_array($c['council'], $GLOBALS['DEAD_SOURCE'], true) ? 'DEAD_SOURCE' : '';
            $rows_out[] = [
                $c['council'], $c['region'], 'spend', $c['listing_url'] ?? '',
                $held, ($cad==1?'monthly':($cad==3?'quarterly':($cad==6?'six-monthly':$cad.'mo'))) . ($conf==='ok'?'':'?'),
                idx_to_period($expected_idx),
                'held<' . idx_to_period($expected_idx), $dead,
            ];
        }
    }
}

// ================= REGISTER =================
if ($side === 'both' || $side === 'register') {
    [$rc, $rp] = region_clause($scope, $DEVOLVED, $pdo);
    $sql = "SELECT r.council, c.region, r.listing_url, r.last_fetched, r.notes
              FROM ct_contract_registers r
              JOIN ct_council_config c ON c.council = r.council
             WHERE r.active = 1 AND r.not_pollable = 0 AND r.listing_url IS NOT NULL AND $rc
             ORDER BY r.council";
    $st = $pdo->prepare($sql); $st->execute($rp);
    $regs = $st->fetchAll(PDO::FETCH_ASSOC);
    $today = strtotime(date('Y-m-d'));
    foreach ($regs as $r) {
        if (in_array($r['council'], $SNIFF_EXCLUDE, true)) continue;
        $stats['register_checked']++;
        $notes = strtolower((string)$r['notes']);
        if (strpos($notes, 'month') !== false)        { $win = 45;  $cadlbl = 'monthly'; }
        elseif (strpos($notes, 'quarter') !== false)  { $win = 120; $cadlbl = 'quarterly'; }
        elseif (strpos($notes, 'annual') !== false)   { $win = 400; $cadlbl = 'annual'; }
        else                                          { $win = 120; $cadlbl = 'default-90d+'; }
        $lf = $r['last_fetched'];
        $stale = ($lf === null);
        $age_days = null;
        if (!$stale) { $age_days = (int)floor(($today - strtotime($lf)) / 86400); $stale = $age_days > $win; }
        if ($stale) {
            $stats['register_behind']++;
            $rows_out[] = [
                $r['council'], $r['region'], 'register', $r['listing_url'],
                ($lf === null ? 'never' : $lf), $cadlbl,
                'window ' . $win . 'd',
                ($lf === null ? 'never-fetched' : 'stale ' . $age_days . 'd'), '',
            ];
        }
    }
}

// ---- write TSV ----
$fh = fopen($out, 'w');
fwrite($fh, "council\tregion\tside\tlisting_url\theld_or_lastfetched\tcadence\texpected_or_window\treason\tflag\n");
foreach ($rows_out as $r) fwrite($fh, implode("\t", $r) . "\n");
fclose($fh);

// ---- summary ----
echo "SNIFF CANDIDATES — scope=$scope side=$side  (today=" . date('Y-m') . ")\n";
echo "spend: {$stats['spend_checked']} checked, {$stats['spend_behind']} behind\n";
echo "register: {$stats['register_checked']} checked, {$stats['register_behind']} behind\n";
echo "queue rows: " . count($rows_out) . "\n";
echo "written: $out\n";
