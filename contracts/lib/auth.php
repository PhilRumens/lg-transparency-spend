<?php
/**
 * auth.php — session + authentication for the LG Transparency Spend app.
 *
 * Access model: public pages are open and call nothing here. Admin/editor
 * pages call require_login() and gate write actions on is_admin(). Users live
 * in the `lgam_users` table (db/schema.sql); create one with
 * tools/create_user.php. Login/logout are handled by login.php / logout.php.
 *
 * This reconstructs the small surface the app previously got from the parent
 * site's config.php: start_session(), logged_in(), require_login(),
 * current_user(), is_admin().
 */

if (!defined('LGTS_SESSION_NAME')) {
    define('LGTS_SESSION_NAME', 'lgts_session');
}
// Where login.php lives, relative to the web root. Change if you mount the app
// somewhere other than /contracts/.
if (!defined('LGTS_BASE')) {
    define('LGTS_BASE', '/contracts');
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (PHP_SAPI === 'cli') return; // no sessions in CLI importers
    session_name(LGTS_SESSION_NAME);
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

function logged_in(): bool {
    start_session();
    return !empty($_SESSION['user_id']);
}

/** Redirect unauthenticated users to the login page, preserving their destination. */
function require_login(): void {
    if (logged_in()) return;
    $redirect = $_SERVER['REQUEST_URI'] ?? (LGTS_BASE . '/');
    header('Location: ' . LGTS_BASE . '/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

/** The signed-in user as an assoc array (empty strings / null when logged out). */
function current_user(): array {
    start_session();
    return [
        'id'    => $_SESSION['user_id']    ?? null,
        'name'  => $_SESSION['user_name']  ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['user_role']  ?? '',
    ];
}

function is_admin(): bool {
    start_session();
    return (($_SESSION['user_role'] ?? '') === 'admin');
}
