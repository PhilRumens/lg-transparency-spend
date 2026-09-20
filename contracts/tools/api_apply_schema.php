<?php
/**
 * One-shot: apply scripts/api_schema.sql (DDL) using the app PDO. Run once:
 *     php ~/scripts/api_apply_schema.php
 * Idempotent (CREATE TABLE IF NOT EXISTS). Skips the commented GRANT block.
 */
require_once __DIR__ . '/../config.php';

$sqlFile = __DIR__ . '/api_schema.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) { fwrite(STDERR, "cannot read $sqlFile\n"); exit(1); }

$pdo = db();
$done = 0;
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    // Strip comment-only fragments.
    $code = preg_replace('/^\s*--.*$/m', '', $stmt);
    if (trim($code) === '') continue;
    if (!preg_match('/^\s*create\s+table/i', $code)) continue; // only CREATE TABLE
    $pdo->exec($code);
    $done++;
    if (preg_match('/EXISTS\s+(\w+)/i', $code, $m)) echo "applied: {$m[1]}\n";
}
echo "done ($done statements)\n";
