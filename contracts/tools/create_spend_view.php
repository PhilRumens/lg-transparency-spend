<?php
/**
 * Creates/refreshes ct_spend_display: a DISPLAY view over ct_transparency_spend
 * that attributes joint/shared-service spend to the real councils (50/50 for
 * Adur & Worthing) by relabelling each joint row into two half-amount rows.
 * Aggregation/display pages read this view so the joint entity never appears as
 * its own council and its spend is split — with no row surgery on the base table.
 * Mapping mirrors JOINT_SPLITS in lgam/config.php.
 */
require_once __DIR__ . '/../config.php';
$pdo = db();

$cols = "id, supplier_raw, supplier_canon, internal_provider, service, category, "
      . "paid_date, period, source_id, fetched_at, validation_status, validation_notes, validated_at";

$pdo->exec("DROP VIEW IF EXISTS ct_spend_display");
$pdo->exec("
CREATE VIEW ct_spend_display AS
  SELECT council, lad_code, amount, $cols
    FROM ct_transparency_spend
   WHERE council <> 'Adur and Worthing Councils Joint'
  UNION ALL
  SELECT 'Adur District Council' AS council, 'E07000223' AS lad_code, amount/2 AS amount, $cols
    FROM ct_transparency_spend WHERE council = 'Adur and Worthing Councils Joint'
  UNION ALL
  SELECT 'Worthing Borough Council' AS council, 'E07000229' AS lad_code, amount/2 AS amount, $cols
    FROM ct_transparency_spend WHERE council = 'Adur and Worthing Councils Joint'
");
echo "ct_spend_display created\n";
