<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

const TECH_CPVS_T = ['30','32','48','64','72','73'];
const TECH_CPV_LABELS_T = [
    '30' => 'IT Hardware',
    '32' => 'Communications Equipment',
    '48' => 'Software',
    '64' => 'Post & Telecommunications',
    '72' => 'IT Services',
    '73' => 'R&D Services',
];

$cpv_in = implode(',', array_map(fn($c) => "'$c'", TECH_CPVS_T));

// ── Filters ────────────────────────────────────────────────────────────────
$q      = trim($_GET['q']      ?? '');
$cpv    = $_GET['cpv']         ?? '';
$status = $_GET['status']      ?? 'active';  // default to active
$sort   = $_GET['sort']        ?? 'deadline'; // deadline, published, value
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 50;
$offset = ($page - 1) * $per;
// ── Sort URL helper ────────────────────────────────────────────────────────
function sort_url(string $col): string {
    $p = $_GET;
    $p['sort'] = $col;
    unset($p['page']);
    return '/contracts/tech_tenders.php?' . http_build_query($p);
}
function sort_th(string $col, string $label, string $current, string $align = ''): string {
    $active  = $current === $col;
    $arrow   = $active ? ' ↑' : '';
    $numeric = $align === 'numeric' ? ' govuk-table__header--numeric' : '';
    $style   = $active ? 'text-decoration:underline;cursor:pointer' : 'cursor:pointer';
    $url     = htmlspecialchars(sort_url($col));
    return '<th class="govuk-table__header' . $numeric . '">'
         . '<a href="' . $url . '" class="govuk-link govuk-link--no-visited-state" style="' . $style . ';color:inherit;font-weight:700">' . $label . $arrow . '</a>'
         . '</th>';
}


// ── WHERE ──────────────────────────────────────────────────────────────────
$where  = ['cpv_division IN (' . $cpv_in . ')'];
$params = [];

if ($status === 'active') {
    $where[] = "status = 'active' AND (tender_end_date IS NULL OR tender_end_date >= CURDATE())";
} elseif ($status === 'pipeline') {
    $where[] = "status IN ('planned','planning')";
} elseif ($status === 'all') {
    // no filter
}

if ($cpv) {
    $where[]       = 'cpv_division = :cpv';
    $params[':cpv'] = $cpv;
}
if ($q) {
    $where[]       = '(title LIKE :q OR buyer_name LIKE :q2)';
    $params[':q']  = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}

$whereSQL = implode(' AND ', $where);

// ── Sort ───────────────────────────────────────────────────────────────────
$order = match($sort) {
    'published' => 'published_date DESC',
    'value'     => 'value_amount DESC',
    'buyer'     => 'buyer_name ASC',
    'deadline'  => 'CASE WHEN tender_end_date IS NULL THEN 1 ELSE 0 END, tender_end_date ASC',
    default     => 'CASE WHEN tender_end_date IS NULL THEN 1 ELSE 0 END, tender_end_date ASC',
};

// ── Count + fetch ──────────────────────────────────────────────────────────
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ct_contracts WHERE {$whereSQL}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$dataStmt = $pdo->prepare("
    SELECT title, buyer_name, value_amount, published_date, tender_end_date,
           official_url, cpv_division, lgam_sublayer_name, source, status
    FROM ct_contracts
    WHERE {$whereSQL}
    ORDER BY {$order}
    LIMIT {$per} OFFSET {$offset}
");
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll();
$total_pages = max(1, (int)ceil($total / $per));

// ── Stats ──────────────────────────────────────────────────────────────────
$stats = $pdo->query("
    SELECT
        SUM(CASE WHEN status='active' AND (tender_end_date IS NULL OR tender_end_date >= CURDATE()) THEN 1 ELSE 0 END) AS active_n,
        SUM(CASE WHEN status IN ('planned','planning') THEN 1 ELSE 0 END) AS pipeline_n,
        SUM(CASE WHEN status='active' AND tender_end_date IS NOT NULL AND tender_end_date >= CURDATE() AND tender_end_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) AS closing_soon,
        SUM(CASE WHEN status='active' AND value_amount IS NOT NULL THEN value_amount ELSE 0 END) AS active_value
    FROM ct_contracts
    WHERE cpv_division IN ({$cpv_in})
")->fetch();

function page_url_t(int $p): string {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}

layout_head('Active tech tenders');
?>

<style>
.tech-nav { display:flex; gap:0; margin-bottom:24px; border-bottom:2px solid #b1b4b6; }
.tech-nav a { padding:10px 20px; text-decoration:none; color:#0b0c0c; font-size:1rem; font-weight:400; border-bottom:4px solid transparent; margin-bottom:-2px; }
.tech-nav a:hover { border-bottom-color:#b1b4b6; }
.tech-nav a.active { font-weight:700; border-bottom-color:#1d70b8; color:#1d70b8; }
.deadline-urgent { color:#d4351c; font-weight:700; }
.deadline-soon   { color:#f47738; font-weight:600; }
</style>

<nav class="tech-nav">
  <a href="/contracts/tech.php">Overview</a>
  <a href="/contracts/tech.php?section=suppliers">A — Supplier market share</a>
  <a href="/contracts/tech.php?section=buyers">B — Buyer benchmarking</a>
  <a href="/contracts/tech.php?section=categories">C — Category breakdown</a>
  <a href="/contracts/tech_tenders.php" class="active">Active tenders</a>
</nav>

<!-- Stats -->
<div class="govuk-grid-row govuk-!-margin-bottom-6">
  <?php foreach ([
    [number_format((int)$stats['active_n']),   'Active tenders',      'Open now'],
    [number_format((int)$stats['pipeline_n']), 'Pipeline / PME',      'Early market engagement'],
    [number_format((int)$stats['closing_soon']),'Closing within 14 days','Act fast'],
    [fmt_value((float)$stats['active_value']), 'Active tender value', 'Stated value only'],
  ] as [$n,$label,$sub]): ?>
  <div class="govuk-grid-column-one-quarter govuk-!-margin-bottom-4">
    <div class="ct-stat-card">
      <div class="ct-stat-card__number"><?= $n ?></div>
      <div class="ct-stat-card__label"><?= $label ?></div>
      <div class="ct-stat-card__sub"><?= $sub ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<form method="get" action="/contracts/tech_tenders.php" class="app-filter-bar govuk-!-margin-bottom-4">
  <div class="govuk-form-group">
    <label class="govuk-label" for="q">Search</label>
    <input class="govuk-input govuk-input--width-20" type="text" id="q" name="q"
           value="<?= htmlspecialchars($q) ?>" placeholder="Title or buyer…">
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="cpv">Category</label>
    <select class="govuk-select" id="cpv" name="cpv">
      <option value="">All tech</option>
      <?php foreach (TECH_CPV_LABELS_T as $v => $l): ?>
        <option value="<?= $v ?>"<?= $cpv===$v?' selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="status">Status</label>
    <select class="govuk-select" id="status" name="status">
      <option value="active"<?= $status==='active'?' selected':'' ?>>Active tenders</option>
      <option value="pipeline"<?= $status==='pipeline'?' selected':'' ?>>Pipeline / PME</option>
      <option value="all"<?= $status==='all'?' selected':'' ?>>All open</option>
    </select>
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="sort">Sort by</label>
    <select class="govuk-select" id="sort" name="sort">
      <option value="deadline"<?= $sort==='deadline'?' selected':'' ?>>Deadline (soonest first)</option>
      <option value="published"<?= $sort==='published'?' selected':'' ?>>Published (newest first)</option>
      <option value="value"<?= $sort==='value'?' selected':'' ?>>Value (highest first)</option>
      <option value="buyer"<?= $sort==='buyer'?' selected':'' ?>>Buyer (A–Z)</option>
    </select>
  </div>
  <button class="govuk-button govuk-!-margin-bottom-0" type="submit">Filter</button>
  <?php if ($q || $cpv || $status !== 'active' || $sort !== 'deadline'): ?>
    <a href="/contracts/tech_tenders.php" class="govuk-link govuk-!-margin-top-auto govuk-!-margin-bottom-2">Reset</a>
  <?php endif; ?>
</form>

<p class="govuk-body govuk-!-colour-secondary govuk-!-margin-bottom-3">
  <?= number_format($total) ?> tender<?= $total !== 1 ? 's' : '' ?> · page <?= $page ?> of <?= $total_pages ?>
</p>

<?php if (empty($rows)): ?>
  <p class="govuk-body govuk-!-colour-secondary">No tenders match the current filters.</p>
<?php else: ?>

<table class="govuk-table" style="font-size:0.875rem">
  <thead class="govuk-table__head">
    <tr class="govuk-table__row">
      <th class="govuk-table__header" style="width:32%">Title</th>
      <?= sort_th('buyer',     'Buyer',     $sort) ?>
      <th class="govuk-table__header">Category</th>
      <th class="govuk-table__header">LGAM</th>
      <?= sort_th('value',     'Value',     $sort, 'numeric') ?>
      <?= sort_th('published', 'Published', $sort) ?>
      <?= sort_th('deadline',  'Deadline',  $sort) ?>
      <th class="govuk-table__header">Status</th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
    <?php foreach ($rows as $r):
      $days_left = null;
      $deadline_class = '';
      if ($r['tender_end_date']) {
          $days_left = (int)ceil((strtotime($r['tender_end_date']) - time()) / 86400);
          if ($days_left < 7)  $deadline_class = 'deadline-urgent';
          elseif ($days_left < 21) $deadline_class = 'deadline-soon';
      }
    ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell">
        <?php if ($r['official_url']): ?>
          <a href="<?= htmlspecialchars($r['official_url']) ?>" class="govuk-link" target="_blank" rel="noopener">
            <?= htmlspecialchars(mb_strimwidth($r['title'] ?? '—', 0, 75, '…')) ?>
          </a>
        <?php else: ?>
          <?= htmlspecialchars(mb_strimwidth($r['title'] ?? '—', 0, 75, '…')) ?>
        <?php endif; ?>
      </td>
      <td class="govuk-table__cell"><?= htmlspecialchars(mb_strimwidth($r['buyer_name'] ?? '—', 0, 30, '…')) ?></td>
      <td class="govuk-table__cell" style="white-space:nowrap;font-size:0.8rem">
        <?= htmlspecialchars(TECH_CPV_LABELS_T[$r['cpv_division']] ?? $r['cpv_division'] ?? '—') ?>
      </td>
      <td class="govuk-table__cell" style="font-size:0.8rem">
        <?= htmlspecialchars($r['lgam_sublayer_name'] ?? '—') ?>
      </td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= fmt_value($r['value_amount']) ?></td>
      <td class="govuk-table__cell" style="white-space:nowrap"><?= htmlspecialchars($r['published_date'] ?? '—') ?></td>
      <td class="govuk-table__cell" style="white-space:nowrap">
        <?php if ($r['tender_end_date']): ?>
          <span class="<?= $deadline_class ?>"><?= htmlspecialchars($r['tender_end_date']) ?></span>
          <br><span style="color:#6f777b;font-size:0.8rem">
            <?php if ($days_left < 0): ?>Closed<?php
            elseif ($days_left === 0): ?>Today<?php
            elseif ($days_left === 1): ?>Tomorrow<?php
            else: ?><?= $days_left ?>d left<?php endif; ?>
          </span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="govuk-table__cell"><?= status_tag($r['status'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<nav style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:16px">
  <?php if ($page > 1): ?>
    <a href="<?= htmlspecialchars(page_url_t($page - 1)) ?>" class="govuk-link">← Previous</a>
  <?php endif; ?>
  <?php for ($p = max(1,$page-3); $p <= min($total_pages,$page+3); $p++): ?>
    <?php if ($p === $page): ?>
      <strong style="padding:4px 8px;background:#1d70b8;color:#fff;border-radius:3px"><?= $p ?></strong>
    <?php else: ?>
      <a href="<?= htmlspecialchars(page_url_t($p)) ?>" class="govuk-link" style="padding:4px 8px"><?= $p ?></a>
    <?php endif; ?>
  <?php endfor; ?>
  <?php if ($page < $total_pages): ?>
    <a href="<?= htmlspecialchars(page_url_t($page + 1)) ?>" class="govuk-link">Next →</a>
  <?php endif; ?>
  <span class="govuk-body-s govuk-!-colour-secondary" style="margin-left:8px">
    <?= number_format($total) ?> total · <?= $per ?> per page
  </span>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php layout_foot(); ?>
