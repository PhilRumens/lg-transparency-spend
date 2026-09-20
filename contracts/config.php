<?php
/**
 * config.php — self-contained bootstrap for the LG Transparency Spend app.
 *
 * This REPLACES the old parent-site dependency on '../lgam/config.php'. It
 * provides everything the app expects: the shared $pdo handle, the db() helper,
 * and the session/auth functions (via lib/auth.php).
 *
 * Credentials are read from environment variables first, then from an optional
 * gitignored config.local.php. NEVER hardcode real credentials in this file.
 * See config.local.example.php and .env.example.
 */
declare(strict_types=1);

// Optional local overrides (copy config.local.example.php -> config.local.php).
$__local = __DIR__ . '/config.local.php';
if (is_file($__local)) { require $__local; }

/** Read a setting from env, falling back to a config.local.php constant, then a default. */
function cfg(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    if (defined($key)) return (string) constant($key);
    return $default;
}

$DB_HOST    = cfg('DB_HOST', '127.0.0.1');
$DB_PORT    = cfg('DB_PORT', '3306');
$DB_NAME    = cfg('DB_NAME', 'lgts');
$DB_USER    = cfg('DB_USER', 'lgts');
$DB_PASS    = cfg('DB_PASS', '');
$DB_CHARSET = cfg('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset={$DB_CHARSET}";
try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "DB connection failed: {$e->getMessage()}\n");
        exit(1);
    }
    http_response_code(500);
    exit('Database connection failed — check config (see config.local.example.php / .env.example).');
}

/** Return the shared PDO handle. */
function db(): PDO { global $pdo; return $pdo; }

// Session + authentication helpers.
require_once __DIR__ . '/lib/auth.php';
