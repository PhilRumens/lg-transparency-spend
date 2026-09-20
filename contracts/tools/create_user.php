<?php
/**
 * create_user.php — CLI to create or update an app user (bcrypt password).
 * Replaces the parent site's /lgam/add_user.php for standalone deployments.
 *
 * Usage: php tools/create_user.php <email> <name> [role: editor|admin]
 * Prompts for the password (hidden). Re-running with the same email updates it.
 */
require_once __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

$email = $argv[1] ?? '';
$name  = $argv[2] ?? '';
$role  = $argv[3] ?? 'editor';

if ($email === '' || $name === '' || !in_array($role, ['editor', 'admin'], true)) {
    fwrite(STDERR, "usage: php tools/create_user.php <email> <name> [role: editor|admin]\n");
    exit(1);
}

fwrite(STDERR, 'Password (min 8 chars): ');
@system('stty -echo 2>/dev/null');
$pw = trim((string) fgets(STDIN));
@system('stty echo 2>/dev/null');
fwrite(STDERR, "\n");

if (strlen($pw) < 8) { fwrite(STDERR, "password must be at least 8 characters\n"); exit(1); }

$hash = password_hash($pw, PASSWORD_DEFAULT);
$sql  = "INSERT INTO lgam_users (email, name, password, role, verified, created_at)
         VALUES (?, ?, ?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE name = VALUES(name), password = VALUES(password),
                                 role = VALUES(role), verified = 1";
db()->prepare($sql)->execute([$email, $name, $hash, $role]);

echo "user {$email} ({$role}) saved\n";
