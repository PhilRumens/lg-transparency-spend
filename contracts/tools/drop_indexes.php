<?php
require_once __DIR__ . '/../config.php';
$pdo = db();

// Safety: refuse only if an actual PHP import/rebuild is executing (exclude shell wrappers/grep/ssh)
$busy = trim(shell_exec("pgrep -af 'php.*import_transparency.php|php.*rebuild_summaries.php' | grep -v grep | head -1"));
if ($busy !== '') { fwrite(STDERR, "ABORT: import/rebuild active :: $busy\n"); exit(1); }

foreach ([
  ['idx_date_council_cover','D2 ~96MB (no live paid_date query lacks ip filter)'],
  ['idx_ip_lad_period_amt','D3 ~62MB (falls back to idx_ip_lad_period_vs)'],
] as [$idx,$why]) {
  echo "Dropping $idx — $why...\n";
  $t=microtime(true);
  $pdo->exec("ALTER TABLE ct_transparency_spend DROP INDEX `$idx`");
  printf("  done in %.2fs\n", microtime(true)-$t);
}

echo "--- remaining leading-column indexes ---\n";
foreach ($pdo->query("SHOW INDEX FROM ct_transparency_spend") as $r)
  if ($r['Seq_in_index']==1) echo "  ".$r['Key_name']."\n";
