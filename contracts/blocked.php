<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
if (!$is_admin) {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied.');
}

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$REASON_LABELS = [
    'cloudflare'   => '&#9729; Cloudflare-blocked',
    'no-data'      => 'No data available',
    'stale-source' => 'Stale / dead source',
];

// Handle add/delete actions
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name   = trim($_POST['council_name'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');
        $date   = trim($_POST['checked_date'] ?? '') ?: date('Y-m-d');
        if ($name && $reason) {
            $pdo->prepare("INSERT INTO ct_blocked_councils (council_name, reason, notes, checked_date) VALUES (?,?,?,?)")
                ->execute([$name, $reason, $notes ?: null, $date]);
            $success = "Added '$name'.";
        } else {
            $error = 'Council name and reason are required.';
        }
    } elseif ($action === 'delete' && isset($_POST['id'])) {
        $pdo->prepare("DELETE FROM ct_blocked_councils WHERE id=?")->execute([(int)$_POST['id']]);
        $success = 'Entry removed.';
    }
}

$blocked = $pdo->query('SELECT * FROM ct_blocked_councils ORDER BY checked_date DESC, council_name')->fetchAll();

layout_head('Blocked councils', 'sources.php');
?>
<div class="govuk-breadcrumbs govuk-!-margin-bottom-6">
  <ol class="govuk-breadcrumbs__list">
    <li class="govuk-breadcrumbs__list-item"><a class="govuk-breadcrumbs__link" href="transparency.php">Actual payments</a></li>
    <li class="govuk-breadcrumbs__list-item"><a class="govuk-breadcrumbs__link" href="sources.php">Data sources</a></li>
    <li class="govuk-breadcrumbs__list-item" aria-current="page">Blocked councils</li>
  </ol>
</div>

<h1 class="govuk-heading-l">Blocked councils</h1>
<p class="govuk-body">Councils that were attempted but could not be onboarded — no data available from their transparency spend publication.</p>

<?php if ($success): ?>
<div class="govuk-notification-banner govuk-notification-banner--success" role="alert">
  <div class="govuk-notification-banner__header"><h2 class="govuk-notification-banner__title">Success</h2></div>
  <div class="govuk-notification-banner__content"><p class="govuk-body"><?= htmlspecialchars($success) ?></p></div>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="govuk-error-summary"><h2 class="govuk-error-summary__title">Error</h2>
  <div class="govuk-error-summary__body"><p class="govuk-body"><?= htmlspecialchars($error) ?></p></div>
</div>
<?php endif; ?>

<?php if (empty($blocked)): ?>
<p class="govuk-body govuk-hint">No blocked councils recorded.</p>
<?php else: ?>
<table class="govuk-table">
  <thead class="govuk-table__head">
    <tr class="govuk-table__row">
      <th class="govuk-table__header">Council</th>
      <th class="govuk-table__header">Reason</th>
      <th class="govuk-table__header">Checked</th>
      <th class="govuk-table__header">Notes</th>
      <th class="govuk-table__header"></th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
  <?php foreach ($blocked as $bc): ?>
  <tr class="govuk-table__row">
    <td class="govuk-table__cell"><strong><?= htmlspecialchars($bc['council_name']) ?></strong></td>
    <td class="govuk-table__cell"><?= $REASON_LABELS[$bc['reason']] ?? htmlspecialchars($bc['reason']) ?></td>
    <td class="govuk-table__cell" style="white-space:nowrap"><?= htmlspecialchars($bc['checked_date'] ?? '—') ?></td>
    <td class="govuk-table__cell govuk-body-s" style="color:#505a5f"><?= htmlspecialchars($bc['notes'] ?? '') ?></td>
    <td class="govuk-table__cell">
      <form method="post" onsubmit="return confirm('Remove this entry?')">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$bc['id'] ?>">
        <button class="govuk-button govuk-button--warning govuk-!-margin-bottom-0" style="font-size:0.875rem;padding:4px 10px">Remove</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<hr class="govuk-section-break govuk-section-break--m govuk-section-break--visible">
<h2 class="govuk-heading-m">Add blocked council</h2>
<form method="post" style="max-width:600px">
  <input type="hidden" name="action" value="add">
  <div class="govuk-form-group">
    <label class="govuk-label" for="council_name">Council name</label>
    <input class="govuk-input" type="text" id="council_name" name="council_name" required>
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="reason">Reason</label>
    <select class="govuk-select" id="reason" name="reason" required>
      <option value="">— select —</option>
      <option value="cloudflare">Cloudflare-blocked</option>
      <option value="no-data">No data available</option>
      <option value="stale-source">Stale / dead source</option>
    </select>
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="checked_date">Date checked</label>
    <input class="govuk-input govuk-input--width-10" type="date" id="checked_date" name="checked_date" value="<?= date('Y-m-d') ?>">
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="notes">Notes</label>
    <textarea class="govuk-textarea" id="notes" name="notes" rows="3"></textarea>
  </div>
  <button class="govuk-button" type="submit">Add</button>
</form>
<?php
layout_foot();
