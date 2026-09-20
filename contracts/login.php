<?php
require_once __DIR__ . '/config.php';
start_session();

if (logged_in()) {
    header('Location: editor.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Enter your email address and password.';
    } else {
        try {
            $stmt = db()->prepare('SELECT id, name, email, password, role, verified FROM lgam_users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'] ?? '')) {
                if (!$user['verified']) {
                    $error = 'Your account has not been verified yet. Please check your email for the invitation link.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_name']  = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role']  = $user['role'];

                    db()->prepare('UPDATE lgam_users SET last_login = NOW() WHERE id = ?')
                         ->execute([$user['id']]);

                    $redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? 'sources.php';
                    // Safety: only allow relative paths — preserve + for URL-encoded spaces
                    $redirect = preg_replace('/[^a-zA-Z0-9\/\-_\.?=&+%]/', '', $redirect);
                    if (empty($redirect)) $redirect = 'sources.php';
                    header('Location: ' . $redirect);
                    exit;
                }
            } else {
                $error = 'Email address or password is incorrect.';
            }
        } catch (PDOException $e) {
            $error = 'A database error occurred. Please try again.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — LGAM Connection Editor</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:"GDS Transport",Arial,sans-serif;font-size:16px;color:#0b0c0c;background:#f3f2f1;min-height:100vh;}
/* ── GDS Header ─────────────────────────────────── */
.govuk-header{background:#0b0c0c;border-bottom:5px solid #1d70b8;}
.govuk-header__container{padding:0 20px;display:flex;align-items:stretch;min-height:50px;}
.govuk-header__service-name{font-size:16px;font-weight:700;color:#fff;text-decoration:none;display:flex;align-items:center;padding-right:20px;}
.govuk-header__service-name:hover{text-decoration:underline;text-decoration-thickness:3px;}
.govuk-header__spacer{flex:1;}
.govuk-header__nav{display:flex;align-items:stretch;}
.govuk-header__nav-link{color:rgba(255,255,255,.85);text-decoration:none;font-size:14px;padding:0 14px;display:flex;align-items:center;}
.govuk-header__nav-link:hover{text-decoration:underline;color:#fff;}
.govuk-header__user{display:flex;align-items:center;gap:12px;padding-left:16px;border-left:1px solid rgba(255,255,255,.2);margin-left:4px;}
.govuk-header__user-name{color:rgba(255,255,255,.7);font-size:13px;}
.govuk-header__sign-out{color:#fff;font-size:13px;font-weight:700;text-decoration:underline;}
/* ── Phase banner ───────────────────────────────── */
.govuk-phase-banner{background:#fff;border-bottom:1px solid #b1b4b6;padding:8px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.govuk-phase-tag{display:inline-block;font-size:14px;font-weight:700;color:#fff;background:#1d70b8;padding:2px 8px;letter-spacing:.5px;text-transform:uppercase;}
.govuk-phase-text{font-size:14px;color:#0b0c0c;}
.govuk-phase-text a{color:#1d70b8;}
.govuk-phase-text a:visited{color:#4c2c92;}
.govuk-visually-hidden{position:absolute;width:1px;height:1px;margin:0;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;}
.govuk-width-container{max-width:640px;margin:0 auto;padding:0 20px;}
.govuk-main-wrapper{padding:40px 0 60px;}
.govuk-heading-l{font-size:36px;font-weight:700;margin-bottom:20px;line-height:1.1;}
.govuk-body{font-size:16px;line-height:1.6;margin-bottom:20px;}
.govuk-body a{color:#1d70b8;}
.govuk-inset-text{border-left:10px solid #d4351c;padding:15px;margin-bottom:20px;background:#fff;}
.govuk-inset-text p{margin:0;font-size:16px;line-height:1.6;}
.govuk-inset-text p+p{margin-top:10px;}
.govuk-inset-text a{color:#1d70b8;font-weight:700;}
.govuk-warning-text{display:flex;align-items:flex-start;gap:10px;margin-bottom:24px;}
.govuk-warning-text__icon{font-size:28px;font-weight:700;color:#fff;background:#0b0c0c;width:35px;height:35px;display:flex;align-items:center;justify-content:center;border-radius:50%;flex-shrink:0;margin-top:2px;}
.govuk-warning-text__text{font-size:16px;font-weight:700;line-height:1.5;}
.govuk-error-message{font-size:14px;font-weight:700;color:#d4351c;margin-bottom:6px;display:block;}
.govuk-form-group{margin-bottom:24px;}
.govuk-form-group--error{padding-left:15px;border-left:5px solid #d4351c;}
.govuk-label{display:block;font-size:16px;font-weight:700;margin-bottom:5px;}
.govuk-input{font-family:"GDS Transport",Arial,sans-serif;font-size:16px;color:#0b0c0c;border:2px solid #0b0c0c;padding:8px;width:100%;background:#fff;}
.govuk-input:focus{outline:3px solid #ffdd00;outline-offset:0;}
.govuk-input--error{border-color:#d4351c;}
.govuk-button{font-family:"GDS Transport",Arial,sans-serif;font-size:18px;font-weight:700;color:#0b0c0c;background:#ffdd00;border:2px solid transparent;padding:10px 20px;cursor:pointer;box-shadow:0 2px 0 #594d00;}
.govuk-button:hover{background:#e8ce00;}
.govuk-button:focus{outline:3px solid #ffdd00;outline-offset:0;}
.govuk-back-link{font-size:14px;color:#1d70b8;display:inline-block;margin-bottom:20px;text-decoration:underline;}
</style>
</head>
<body>
<header class="govuk-header" role="banner">
  <div class="govuk-header__container">
    <a href="editor.php" class="govuk-header__service-name">LGAM — Connection Editor</a>
    <div class="govuk-header__spacer"></div>
  </div>
</header>

<div class="govuk-phase-banner" role="complementary">
  <strong class="govuk-phase-tag">Prototype</strong>
  <span class="govuk-phase-text">This is a prototype tool, to map the connections between elements in the <a href="https://architecture.cddo.cabinetoffice.gov.uk/gds-local/" target="_blank">Local Government Architecture Model</a></span>
</div>
<div class="govuk-width-container">
  <main class="govuk-main-wrapper" id="main-content">

    <h1 class="govuk-heading-l">Sign in</h1>

    <div class="govuk-warning-text">
      <span class="govuk-warning-text__icon" aria-hidden="true">!</span>
      <span class="govuk-warning-text__text">
        <strong>Access to extended data is by invitation only.</strong>
      </span>
    </div>

    <?php if ($error !== ''): ?>
    <div class="govuk-inset-text" role="alert">
      <p><?= htmlspecialchars($error) ?></p>
    </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?php if (!empty($_GET['redirect'])): ?>
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect']) ?>">
      <?php endif; ?>
      <div class="govuk-form-group<?= $error ? ' govuk-form-group--error' : '' ?>">
        <label class="govuk-label" for="email">Email address</label>
        <?php if ($error !== ''): ?><span class="govuk-error-message"><span class="govuk-visually-hidden">Error:</span> <?= htmlspecialchars($error) ?></span><?php endif; ?>
        <input class="govuk-input<?= $error ? ' govuk-input--error' : '' ?>"
               id="email" name="email" type="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               autocomplete="email" spellcheck="false">
      </div>
      <div class="govuk-form-group<?= $error ? ' govuk-form-group--error' : '' ?>">
        <label class="govuk-label" for="password">Password</label>
        <input class="govuk-input<?= $error ? ' govuk-input--error' : '' ?>"
               id="password" name="password" type="password"
               autocomplete="current-password">
      </div>
      <button type="submit" class="govuk-button">Sign in</button>
    </form>


    <p class="govuk-body"><a href="index.html">← Back to the map</a></p>

  </main>
</div>
</body>
</html>
