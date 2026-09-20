<?php
require_once __DIR__ . '/../config.php';
$pdo = db();
$pdo->exec("ALTER TABLE ct_council_config ADD COLUMN IF NOT EXISTS not_pollable TINYINT(1) NOT NULL DEFAULT 0 AFTER cloudflare_blocked");
$pdo->exec("ALTER TABLE ct_contract_registers ADD COLUMN IF NOT EXISTS not_pollable TINYINT(1) NOT NULL DEFAULT 0");
echo "altered both tables\n";
