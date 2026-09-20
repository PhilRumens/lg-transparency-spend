<?php
// Scoped source-registration helper. Writes ONLY to ct_spend_sources.
// Usage: php add_source.php '<json array of source rows>'
// Each row: {council, lad_code, url, period, format, col_supplier, col_amount, col_date, col_service, skip_rows, encoding, notes}
require_once __DIR__ . '/../config.php';
$pdo = db();
$json = $argv[1] ?? "";
$rows = json_decode($json, true);
if (!is_array($rows)) { fwrite(STDERR, "bad json\n"); exit(1); }
$sql = "INSERT INTO ct_spend_sources
  (council, department, lad_code, url, period, format, encoding, col_supplier, col_amount, col_date, col_service, skip_rows, active, notes, created_at)
  VALUES (:council, :department, :lad_code, :url, :period, :format, :encoding, :col_supplier, :col_amount, :col_date, :col_service, :skip_rows, 1, :notes, NOW())";
$st = $pdo->prepare($sql);
$n = 0;
foreach ($rows as $r) {
    $st->execute([
        ':council'      => $r['council'],
        ':department'   => $r['department'] ?? '',
        ':lad_code'     => $r['lad_code'] ?? null,
        ':url'          => $r['url'],
        ':period'       => $r['period'],
        ':format'       => $r['format'] ?? 'csv',
        ':encoding'     => $r['encoding'] ?? 'utf8',
        ':col_supplier' => $r['col_supplier'],
        ':col_amount'   => $r['col_amount'],
        ':col_date'     => $r['col_date'],
        ':col_service'  => $r['col_service'] ?? -1,
        ':skip_rows'    => $r['skip_rows'] ?? 1,
        ':notes'        => $r['notes'] ?? '',
    ]);
    $n++;
}
echo "inserted $n source rows\n";
