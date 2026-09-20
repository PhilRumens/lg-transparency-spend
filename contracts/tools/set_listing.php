<?php
// scoped listing_url writer — reuses app PDO from config.php
require_once __DIR__ . '/../config.php';
$council = $argv[1] ?? "";
$which   = $argv[2] ?? "";   // "spend" | "register"
$url     = $argv[3] ?? "";
if ($council === "" || $url === "" || !in_array($which, ["spend","register"], true)) {
    fwrite(STDERR, "usage: php set_listing.php <council> <spend|register> <url>\n"); exit(1);
}
$pdo = db();
if ($which === "spend") {
    $st = $pdo->prepare("UPDATE ct_council_config SET listing_url=?, updated_at=NOW() WHERE council=?");
} else {
    $st = $pdo->prepare("UPDATE ct_contract_registers SET listing_url=? WHERE council=?");
}
$st->execute([$url, $council]);
echo "rows_affected=" . $st->rowCount() . "\n";
