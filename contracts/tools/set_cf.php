<?php
require_once __DIR__ . '/../config.php';
$council = $argv[1] ?? ""; $val = $argv[2] ?? "";
if ($council === "" || !in_array($val, ["0","1"], true)) { fwrite(STDERR,"usage: set_cf.php <council> <0|1>\n"); exit(1); }
$pdo = db();
$st = $pdo->prepare("UPDATE ct_council_config SET cloudflare_blocked=? WHERE council=?");
$st->execute([(int)$val, $council]);
echo "$council cloudflare_blocked=$val rows=".$st->rowCount()."\n";
