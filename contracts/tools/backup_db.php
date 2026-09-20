<?php
// Daily backup of the 'architect' MariaDB database (LG Contracts Dashboard +
// LGAM). Lives outside the web root deliberately -- never put a script that
// can run mysqldump with real credentials inside a publicly servable
// directory (see apply_sql.php precedent: a loaded gun with no business
// being web-accessible).
require_once __DIR__ . '/../config.php';

$backup_dir = getenv('BACKUP_DIR') ?: (__DIR__ . '/../../backups');
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0700, true);
}

$retention_days = 14;

$date = date('Y-m-d_His');
$cnf_path = "/tmp/db_backup_creds_" . uniqid() . ".cnf";
$out_path = "{$backup_dir}/architect_db_{$date}.sql.gz";

$cnf = "[client]\nhost=" . DB_HOST . "\nuser=" . DB_USER . "\npassword=" . DB_PASS . "\n";
file_put_contents($cnf_path, $cnf);
chmod($cnf_path, 0600);

$err_log = tempnam(sys_get_temp_dir(), 'db_backup_err_');
$cmd = "mysqldump --defaults-extra-file=" . escapeshellarg($cnf_path)
     . " --single-transaction --quick --routines --triggers "
     . escapeshellarg(DB_NAME)
     . " 2>" . escapeshellarg($err_log) . " | gzip > " . escapeshellarg($out_path);

exec($cmd, $output, $code);
unlink($cnf_path);

$ok = ($code === 0 && file_exists($out_path) && filesize($out_path) > 1024);

echo date('c') . " backup " . ($ok ? "OK" : "FAILED") . " exit_code={$code} out={$out_path} size=" . (file_exists($out_path) ? filesize($out_path) : 0) . "\n";
$err = @file_get_contents($err_log);
if ($err) echo "stderr: {$err}\n";
@unlink($err_log);

if (!$ok) {
    // Keep the failed/empty file out of the way rather than silently leaving
    // a 0-byte backup that looks valid at a glance.
    @rename($out_path, $out_path . '.failed');
    exit(1);
}

// Retention: delete backups older than $retention_days.
foreach (glob("{$backup_dir}/architect_db_*.sql.gz") as $f) {
    if (filemtime($f) < time() - $retention_days * 86400) {
        unlink($f);
        echo "  pruned old backup: " . basename($f) . "\n";
    }
}
