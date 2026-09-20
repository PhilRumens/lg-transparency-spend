<?php
// scoped not_pollable flag setter — reuses app PDO from config.php
require_once __DIR__ . '/../config.php';
$council = $argv[1] ?? "";
$which   = $argv[2] ?? "";   // "spend" | "register"
$val     = $argv[3] ?? "";   // 0 | 1
if ($council === "" || !in_array($which, ["spend","register"], true) || !in_array($val, ["0","1"], true)) {
    fwrite(STDERR, "usage: php set_pollable.php <council> <spend|register> <0|1>\n"); exit(1);
}
$pdo = db();
$tbl = ($which === "spend") ? "ct_council_config" : "ct_contract_registers";
$st = $pdo->prepare("UPDATE $tbl SET not_pollable=? WHERE council=?");
$st->execute([(int)$val, $council]);
echo "$which:$council not_pollable=$val rows=" . $st->rowCount() . "\n";
