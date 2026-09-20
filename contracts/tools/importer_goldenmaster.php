<?php
/**
 * importer_goldenmaster.php — regression safety net for import_transparency.php rationalisation.
 *
 * Behaviour-preserving refactors must leave imported rows byte-identical. This tool captures a
 * per-(council, period) fingerprint of ct_transparency_spend and diffs a re-imported shadow table
 * against it. The fingerprint is order-independent and duplicate-safe:
 *   COUNT(*), ROUND(SUM(amount),2), and SUM(CRC32(row-fingerprint)).
 * (SUM not BIT_XOR, so identical duplicate rows — common in line-level imports — don't cancel.)
 *
 * Usage:
 *   php importer_goldenmaster.php --snapshot [--out=FILE] [--table=ct_transparency_spend]
 *   php importer_goldenmaster.php --verify --baseline=FILE [--table=ct_transparency_spend_shadow]
 *                                [--councils=a,b,c]
 *
 * Read-only: --snapshot and --verify only ever SELECT. It never writes to any ct_* table.
 */

require_once __DIR__ . '/../config.php';
$pdo = db();

$opts = getopt('', ['snapshot', 'verify', 'out:', 'baseline:', 'table:', 'councils:']);
$mode = isset($opts['snapshot']) ? 'snapshot' : (isset($opts['verify']) ? 'verify' : null);
if (!$mode) { fwrite(STDERR, "usage: --snapshot [--out=] | --verify --baseline= [--table=] [--councils=]\n"); exit(1); }

// row fingerprint: the columns the importer actually writes (excludes id/fetched_at/validation_*/source_id).
// COALESCE nullable fields to a sentinel so NULL and '' differ. 0x1f = unit separator.
const FP = "CRC32(CONCAT_WS(0x1f, supplier_raw, supplier_canon, internal_provider,"
         . " COALESCE(service,'~N~'), COALESCE(category,'~N~'), amount, COALESCE(paid_date,'~N~')))";

/** Aggregate a spend table to [ "council\x1fperiod" => [n, total, fp] ], optional council filter. */
function fingerprint(PDO $pdo, string $table, array $councils = []): array {
    if (!preg_match('/^ct_transparency_spend(_[a-z0-9_]+)?$/', $table)) {
        fwrite(STDERR, "refused: bad table name '$table'\n"); exit(2);
    }
    $where = ''; $params = [];
    if ($councils) {
        $in = implode(',', array_fill(0, count($councils), '?'));
        $where = "WHERE council IN ($in)"; $params = $councils;
    }
    $sql = "SELECT council, period, COUNT(*) n, ROUND(SUM(amount),2) total, "
         . "CAST(SUM(" . FP . ") AS CHAR) fp FROM `$table` $where GROUP BY council, period";
    $st = $pdo->prepare($sql); $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['council'] . "\x1f" . $r['period']] = [$r['n'], $r['total'], $r['fp']];
    }
    return $out;
}

$table = $opts['table'] ?? 'ct_transparency_spend';
$councils = isset($opts['councils']) ? array_filter(array_map('trim', explode(',', $opts['councils']))) : [];

if ($mode === 'snapshot') {
    $out = $opts['out'] ?? (getenv('HOME') . '/goldenmaster_baseline.json');
    $fp = fingerprint($pdo, $table, $councils);
    file_put_contents($out, json_encode([
        'table' => $table, 'taken_at' => date('c'), 'councils_filter' => $councils, 'groups' => $fp,
    ], JSON_PRETTY_PRINT));
    $rows = array_sum(array_map(fn($g) => (int)$g[0], $fp));
    echo "snapshot: " . count($fp) . " (council,period) groups, $rows rows → $out\n";
    exit(0);
}

// verify
$basefile = $opts['baseline'] ?? null;
if (!$basefile || !is_file($basefile)) { fwrite(STDERR, "need --baseline=FILE (existing)\n"); exit(1); }
$base = json_decode(file_get_contents($basefile), true);
$baseGroups = $base['groups'] ?? [];
// if verifying a subset, only compare the baseline groups for those councils
if ($councils) {
    $set = array_flip($councils);
    $baseGroups = array_filter($baseGroups, fn($k) => isset($set[explode("\x1f", $k)[0]]), ARRAY_FILTER_USE_KEY);
}
$targ = fingerprint($pdo, $table, $councils);

$diffs = []; $ok = 0;
foreach ($baseGroups as $key => $b) {
    [$c, $p] = explode("\x1f", $key);
    if (!isset($targ[$key])) { $diffs[] = "MISSING in $table: $c $p (baseline n={$b[0]}, £{$b[1]})"; continue; }
    $t = $targ[$key];
    if ($b[0] != $t[0] || $b[1] != $t[1] || $b[2] != $t[2]) {
        $diffs[] = "DIFF $c $p: n {$b[0]}→{$t[0]}, £{$b[1]}→{$t[1]}, fp " . ($b[2]==$t[2] ? 'same' : 'CHANGED');
    } else { $ok++; }
}
foreach ($targ as $key => $t) {
    if (!isset($baseGroups[$key])) { [$c,$p]=explode("\x1f",$key); $diffs[] = "EXTRA in $table: $c $p (n={$t[0]}, £{$t[1]})"; }
}

echo "verify: $table vs " . basename($basefile) . ($councils ? " [" . count($councils) . " councils]" : "") . "\n";
echo "  matched groups: $ok\n";
if (!$diffs) { echo "  ✓ ZERO DIFF — behaviour preserved\n"; exit(0); }
echo "  ✗ " . count($diffs) . " difference(s):\n";
foreach (array_slice($diffs, 0, 60) as $d) echo "    - $d\n";
if (count($diffs) > 60) echo "    … +" . (count($diffs) - 60) . " more\n";
exit(3);
