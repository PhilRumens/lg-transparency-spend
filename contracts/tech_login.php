<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_layout.php';
start_session();

// Safety: only allow relative paths within /contracts/
function tech_login_sanitise_redirect(string $redirect): string {
    $redirect = preg_replace('/[^a-zA-Z0-9\/\-_\.?=&+%]/', '', $redirect);
    if ($redirect === '' || $redirect[0] !== '/') $redirect = '/contracts/council.php';
    return $redirect;
}

if (logged_in()) {
    header('Location: ' . tech_login_sanitise_redirect($_GET['redirect'] ?? '/contracts/council.php'));
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

                    $redirect = tech_login_sanitise_redirect($_GET['redirect'] ?? $_POST['redirect'] ?? '/contracts/council.php');
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

$redirect_qs = isset($_GET['redirect']) ? '&redirect=' . urlencode($_GET['redirect']) : '';
?><!DOCTYPE html>
<html lang="en" class="govuk-template">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in – LG Technology Spend</title>
<link rel="stylesheet" href="/contracts/assets/css/govuk-frontend.min.css">
<style>
*{box-sizing:border-box}
body{font-family:"GDS Transport",arial,sans-serif;color:#0b0c0c;margin:0}
.ct-hdr{background:#0b0c0c;border-bottom:10px solid #1d70b8;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.ct-hdr-title-link{color:#fff;text-decoration:none;font-size:18px;font-weight:700}
.ct-hdr-title-link:hover{text-decoration:underline}
.ct-phase-banner{background:#fff;padding:8px 20px;border-bottom:1px solid #b1b4b6;font-size:14px;display:flex;align-items:center;gap:10px}
.ct-phase-tag{background:#4c2c92;color:#fff;font-size:14px;font-weight:700;padding:2px 8px;text-transform:uppercase;letter-spacing:1px;white-space:nowrap}
.govuk-width-container{max-width:1170px;margin:0 auto;padding:0 20px}
.govuk-main-wrapper{padding:30px 0 60px}
.ct-signin-card{background:#fff;border:1px solid #b1b4b6;padding:30px;text-align:center}
.ct-signin-card form{max-width:420px;margin:0 auto;text-align:left}
.govuk-warning-text{display:flex;align-items:flex-start;gap:10px;margin:0 auto 24px;max-width:100%;width:max-content;justify-content:center}
.govuk-warning-text__icon{font-size:18px;font-weight:700;color:#fff;background:#0b0c0c;width:24px;height:24px;display:flex;align-items:center;justify-content:center;border-radius:50%;flex-shrink:0;margin-top:2px}
.govuk-warning-text__text{font-size:16px;font-weight:700;line-height:1.5;text-align:left}
.govuk-error-message{font-size:14px;font-weight:700;color:#d4351c;margin-bottom:6px;display:block}
.govuk-form-group--error{padding-left:15px;border-left:5px solid #d4351c}
.govuk-input--error{border-color:#d4351c}
</style>
</head>
<body class="govuk-template__body">

<div class="ct-hdr">
  <span><a href="/contracts/tech.php" class="ct-hdr-title-link">LG Technology Spend</a></span>
</div>

<div class="ct-phase-banner">
  <span class="ct-phase-tag">Prototype</span>
  <span>This is a new service — your <a href="mailto:phil@rumens.uk" class="govuk-link">feedback</a> will help us improve it.</span>
</div>

<div class="govuk-width-container">
  <main class="govuk-main-wrapper" id="main-content">

    <h1 class="govuk-heading-xl">LG Technology Spend</h1>

    <div class="ct-signin-card">
      <h2 class="govuk-heading-l">Sign in</h2>

      <div class="govuk-warning-text">
        <span class="govuk-warning-text__text">
        </span>
      </div>

      <?php if ($error !== ''): ?>
      <div class="govuk-error-summary" role="alert">
        <p class="govuk-body" style="color:#d4351c"><?= htmlspecialchars($error) ?></p>
      </div>
      <?php endif; ?>

      <form method="post" action="?<?= $redirect_qs ? ltrim($redirect_qs, '&') : '' ?>" novalidate>
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

    </div>

  </main>
</div>
</body>
</html>
