<?php
/**
 * Maintenance cron: prune old API request logs. APCu rate-limit windows expire
 * themselves, so there is nothing else to clean.
 *
 *   php api_maint.php            # prune ct_api_log older than 30 days
 */
require_once __DIR__ . '/../config.php';

$days = isset($argv[1]) ? max(1, (int) $argv[1]) : 30;
$st = db()->prepare('DELETE FROM ct_api_log WHERE ts < (NOW() - INTERVAL ? DAY)');
$st->execute([$days]);
echo "pruned {$st->rowCount()} log rows older than {$days} days\n";
