<?php
require_once __DIR__ . '/../config.php';
$pdo = db();
$pdo->exec("INSERT INTO ct_cr_council_summary (council, total, tech, tech_value, from_date, to_date)
    SELECT council, COUNT(*), SUM(is_tech), SUM(CASE WHEN is_tech=1 THEN value_amount END), MIN(start_date), MAX(end_date)
    FROM ct_contract_register_entries GROUP BY council
    ON DUPLICATE KEY UPDATE total=VALUES(total), tech=VALUES(tech), tech_value=VALUES(tech_value), from_date=VALUES(from_date), to_date=VALUES(to_date)");
echo "cr_council_summary refreshed for all councils\n";
