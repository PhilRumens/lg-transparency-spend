<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$show_all = isset($_GET['all']);
$section = $_GET['section'] ?? 'overview';
if (!in_array($section, ['overview', 'suppliers', 'expiring', 'sources'], true)) $section = 'overview';
$council_filter = trim($_GET['council'] ?? '');
$council_search = trim($_GET['q'] ?? '');
$supplier_filter = trim($_GET['supplier'] ?? '');
if ($supplier_filter && $section !== 'suppliers') $section = 'suppliers';
$ctype_selected = trim($_GET['ctype'] ?? '');
// Default to England when the region param is entirely absent (first visit),
// matching council.php/supplier.php. An explicitly-empty region (region=) means
// the user chose "All", so devolved nations only appear when explicitly picked.
$region_selected = array_key_exists('region', $_GET) ? trim($_GET['region']) : 'England';
$awarded_after = trim($_GET['awarded_after'] ?? '');
$awarded_before = trim($_GET['awarded_before'] ?? '');

// Filter options from council_config
$ctype_options = $pdo->query("SELECT DISTINCT council_type FROM ct_council_config WHERE council_type != '' ORDER BY council_type")->fetchAll(PDO::FETCH_COLUMN);
$region_options = $pdo->query("SELECT DISTINCT region FROM ct_council_config WHERE region != '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

// Build council filter clause — JOIN-based to avoid correlated subquery cost
$council_join = '';
$council_where = '';
$council_params = [];
$_country_map = ['England' => 'E%', 'Scotland' => 'S%', 'Wales' => 'W%', 'Northern Ireland' => 'N%'];
if ($ctype_selected || $region_selected || $council_search) {
    $conds = [];
    if ($ctype_selected) { $conds[] = "cf.council_type = ?"; $council_params[] = $ctype_selected; }
    if ($region_selected) {
        if (isset($_country_map[$region_selected])) {
            $conds[] = "cf.lad_code LIKE ?"; $council_params[] = $_country_map[$region_selected];
        } else {
            $conds[] = "cf.region = ?"; $council_params[] = $region_selected;
        }
    }
    if ($council_search) { $conds[] = "cf.council LIKE ?"; $council_params[] = '%' . $council_search . '%'; }
    $council_join  = " JOIN ct_council_config cf ON cf.council = e.council";
    $council_where = " AND " . implode(' AND ', $conds);
}

// Awarded date filter
$date_where = '';
$date_params = [];
if ($awarded_after) { $date_where .= " AND e.start_date >= ?"; $date_params[] = $awarded_after; }
if ($awarded_before) { $date_where .= " AND e.start_date <= ?"; $date_params[] = $awarded_before; }

// Stats — use fast summary table when no filters active
$use_summary = !$council_join && !$date_where;
if ($use_summary) {
    $stats = $pdo->query("SELECT COUNT(*) AS councils, SUM(total) AS total, SUM(tech) AS tech, SUM(tech_value) AS tech_value FROM ct_cr_council_summary")->fetch(PDO::FETCH_ASSOC);
    // Summary table has no tech_suppliers column, and a distinct-supplier count
    // can't be summed across councils (suppliers span multiple councils). Fetch
    // it directly — cheap COUNT(DISTINCT) on the indexed is_tech column.
    $stats['tech_suppliers'] = $pdo->query("SELECT COUNT(DISTINCT supplier_canon) FROM ct_contract_register_entries WHERE is_tech = 1")->fetchColumn();
} else {
    $stats_sql = "SELECT
        COUNT(DISTINCT e.council) AS councils,
        COUNT(*) AS total,
        SUM(e.is_tech) AS tech,
        SUM(CASE WHEN e.is_tech = 1 THEN e.value_amount END) AS tech_value,
        COUNT(DISTINCT CASE WHEN e.is_tech = 1 THEN e.supplier_canon END) AS tech_suppliers
    FROM ct_contract_register_entries e $council_join
    WHERE 1=1 $council_where $date_where";
    $stmt = $pdo->prepare($stats_sql);
    $stmt->execute(array_merge($council_params, $date_params));
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
}

function fmt_cr_value(?float $v): string {
    if ($v === null) return '—';
    if ($v >= 1e9) return '£' . number_format($v / 1e9, 1) . 'bn';
    if ($v >= 1e6) return '£' . number_format($v / 1e6, 1) . 'M';
    if ($v >= 1e3) return '£' . number_format($v / 1e3, 0) . 'k';
    return '£' . number_format($v, 0);
}

layout_head('Contract registers');
?>

<style>
.tech-nav { display:flex; gap:0; margin-bottom:24px; border-bottom:2px solid #b1b4b6; flex-wrap:wrap; }
.tech-nav a { padding:10px 20px; text-decoration:none; color:#0b0c0c; font-size:1rem; font-weight:400; border-bottom:4px solid transparent; margin-bottom:-2px; }
.tech-nav a:hover { border-bottom-color:#b1b4b6; }
.tech-nav a.active { font-weight:700; border-bottom-color:#1d70b8; color:#1d70b8; }
.ct-spender-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:16px; margin-bottom:24px; }
@media (max-width:900px) { .ct-spender-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:480px) { .ct-spender-grid { grid-template-columns:minmax(0,1fr); } }
.badge-tech { display:inline-block; padding:2px 8px; border-radius:3px; font-size:0.75rem; font-weight:600; background:#cce2d8; color:#005a30; }
.badge-expiring { display:inline-block; padding:2px 8px; border-radius:3px; font-size:0.75rem; font-weight:600; background:#f3e8d2; color:#6e3619; }
.badge-expired { display:inline-block; padding:2px 8px; border-radius:3px; font-size:0.75rem; font-weight:600; background:#f8d7da; color:#721c24; }
</style>

<nav class="tech-nav">
  <a href="?section=overview" class="<?= $section==='overview' && !$council_filter ? 'active' : '' ?>">LA overview</a>
  <a href="?section=suppliers" class="<?= $section==='suppliers' ? 'active' : '' ?>">Supplier payments</a>
  <a href="?section=expiring" class="<?= $section==='expiring' ? 'active' : '' ?>">Expiring soon</a>
  <a href="?section=sources" class="<?= $section==='sources' ? 'active' : '' ?>">Data sources</a>
</nav>

<?php if ($council_filter): ?>

<!-- Council detail view -->
<?php
$stmt = $pdo->prepare("SELECT * FROM ct_contract_register_entries WHERE council = ? " . ($show_all ? "" : "AND is_tech = 1 ") . "ORDER BY value_amount DESC");
$stmt->execute([$council_filter]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt2 = $pdo->prepare("SELECT COUNT(*) as total, SUM(is_tech) as tech, SUM(CASE WHEN is_tech=1 THEN value_amount END) as tv FROM ct_contract_register_entries WHERE council = ?");
$stmt2->execute([$council_filter]);
$csummary = $stmt2->fetch(PDO::FETCH_ASSOC);
?>

<p class="govuk-body"><a href="/contracts/contracts_reg.php" class="govuk-link">← All councils</a></p>
<h2 class="govuk-heading-m"><?= htmlspecialchars($council_filter) ?></h2>

<div class="govuk-grid-row govuk-!-margin-bottom-4">
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= (int)$csummary['total'] ?></div><div class="ct-stat-card__label">Total contracts</div></div></div>
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= (int)$csummary['tech'] ?></div><div class="ct-stat-card__label">Tech contracts</div></div></div>
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= fmt_cr_value(isset($csummary['tv']) && $csummary['tv'] !== null ? (float)$csummary['tv'] : null) ?></div><div class="ct-stat-card__label">Tech value</div></div></div>
</div>

<p class="govuk-body">
  <?php if (!$show_all): ?>
    Showing <strong><?= count($entries) ?></strong> tech contracts.
    <a href="?council=<?= urlencode($council_filter) ?>&all=1" class="govuk-link">Show all contracts</a>
  <?php else: ?>
    Showing <strong>all <?= count($entries) ?></strong> contracts.
    <a href="?council=<?= urlencode($council_filter) ?>" class="govuk-link">Show tech only</a>
  <?php endif; ?>
</p>

<?php if ($entries): ?>
<table class="govuk-table">
  <thead class="govuk-table__head"><tr class="govuk-table__row">
    <th class="govuk-table__header">Supplier</th>
    <th class="govuk-table__header">Contract</th>
    <th class="govuk-table__header" style="text-align:right">Value</th>
    <th class="govuk-table__header">Start</th>
    <th class="govuk-table__header">End</th>
    <th class="govuk-table__header">Tender process</th>
    <?php if ($show_all): ?><th class="govuk-table__header">Tech</th><?php endif; ?>
  </tr></thead>
  <tbody class="govuk-table__body">
  <?php foreach ($entries as $e): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><strong><?php $sup_display = $e['supplier_canon'] ?: $e['supplier']; ?><a href="supplier.php?supplier=<?= urlencode($sup_display) ?>" class="govuk-link"><?= htmlspecialchars($sup_display) ?></a></strong></td>
      <td class="govuk-table__cell" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($e['contract_title'] ?? '') ?></td>
      <td class="govuk-table__cell" style="text-align:right;font-size:0.8em"><?= $e['value_amount'] !== null ? '£' . number_format((float)$e['value_amount'], 0) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8em"><?= $e['start_date'] ? date('M Y', strtotime($e['start_date'])) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8em"><?= $e['end_date'] ? date('M Y', strtotime($e['end_date'])) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8em"><?= htmlspecialchars($e['tender_process'] ?? '—') ?></td>
      <?php if ($show_all): ?><td class="govuk-table__cell"><?= $e['is_tech'] ? '<span class="badge-tech">Tech</span>' : '' ?></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
  <p class="govuk-body govuk-!-colour-secondary">No contracts match the current filter.</p>
<?php endif; ?>

<?php elseif ($section === 'expiring'): ?>

<!-- Expiring soon section -->
<?php
$today = date('Y-m-d');
$six_months = date('Y-m-d', strtotime('+6 months'));
$expiring_stmt = $pdo->prepare("
    SELECT e.council, e.supplier, e.supplier_canon, e.contract_title, e.value_amount,
           e.start_date, e.end_date, e.is_tech, e.department
    FROM ct_contract_register_entries e
    WHERE e.is_tech = 1
      AND e.end_date IS NOT NULL
      AND e.end_date >= ?
      AND e.end_date <= ?
    ORDER BY e.end_date ASC
");
$expiring_stmt->execute([$today, $six_months]);
$expiring = $expiring_stmt->fetchAll(PDO::FETCH_ASSOC);

$expired_stmt = $pdo->prepare("
    SELECT e.council, e.supplier, e.supplier_canon, e.contract_title, e.value_amount,
           e.start_date, e.end_date, e.is_tech, e.department
    FROM ct_contract_register_entries e
    WHERE e.is_tech = 1
      AND e.end_date IS NOT NULL
      AND e.end_date < ?
      AND e.end_date >= ?
    ORDER BY e.end_date DESC
");
$expired_stmt->execute([$today, date('Y-m-d', strtotime('-3 months'))]);
$recently_expired = $expired_stmt->fetchAll(PDO::FETCH_ASSOC);

$exp_per_page = 50;
$exp_total_pages = max(1, (int)ceil(count($expiring) / $exp_per_page));
$exp_page = max(1, min($exp_total_pages, (int)($_GET['page'] ?? 1)));
$exp_slice = array_slice($expiring, ($exp_page - 1) * $exp_per_page, $exp_per_page);
?>

<h2 class="govuk-heading-m">Tech contracts expiring within 6 months (<?= count($expiring) ?>)</h2>
<p class="govuk-body-s" style="color:#505a5f">Contracts with end dates between today and <?= date('d M Y', strtotime($six_months)) ?>. These represent procurement opportunities.</p>

<?php if ($expiring): ?>
<table class="govuk-table" style="font-size:0.875rem">
  <thead class="govuk-table__head"><tr class="govuk-table__row">
    <th class="govuk-table__header">End date</th>
    <th class="govuk-table__header">Council</th>
    <th class="govuk-table__header">Supplier</th>
    <th class="govuk-table__header">Contract</th>
    <th class="govuk-table__header govuk-table__header--numeric">Value</th>
  </tr></thead>
  <tbody class="govuk-table__body">
  <?php foreach ($exp_slice as $e):
    $days_left = (int)((strtotime($e['end_date']) - time()) / 86400);
    $urgency = $days_left < 30 ? 'badge-expired' : ($days_left < 90 ? 'badge-expiring' : '');
  ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell">
        <?= date('d M Y', strtotime($e['end_date'])) ?>
        <?php if ($urgency): ?><span class="<?= $urgency ?>"><?= $days_left ?>d</span><?php endif; ?>
      </td>
      <td class="govuk-table__cell"><a href="?council=<?= urlencode($e['council']) ?>" class="govuk-link"><?= htmlspecialchars($e['council']) ?></a></td>
      <td class="govuk-table__cell"><strong><?php $sup_name = $e['supplier_canon'] ?: $e['supplier']; ?><a href="supplier.php?supplier=<?= urlencode($sup_name) ?>" class="govuk-link"><?= htmlspecialchars($sup_name) ?></a></strong></td>
      <td class="govuk-table__cell" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($e['contract_title'] ?? '') ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= $e['value_amount'] ? '£' . number_format((float)$e['value_amount'], 0) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if ($exp_total_pages > 1): ?>
<nav class="govuk-pagination" role="navigation" aria-label="Expiring contracts pagination">
  <?php if ($exp_page > 1): ?>
  <div class="govuk-pagination__prev">
    <a class="govuk-link govuk-pagination__link" href="?section=expiring&page=<?= $exp_page-1 ?>" rel="prev"><span class="govuk-pagination__link-title">Previous</span></a>
  </div>
  <?php endif; ?>
  <ul class="govuk-pagination__list">
    <?php for ($p = 1; $p <= $exp_total_pages; $p++): ?>
      <?php if ($p === 1 || $p === $exp_total_pages || abs($p - $exp_page) <= 2): ?>
      <li class="govuk-pagination__item<?= $p===$exp_page?' govuk-pagination__item--current':'' ?>">
        <a class="govuk-link govuk-pagination__link" href="?section=expiring&page=<?= $p ?>"<?= $p===$exp_page?' aria-current="page"':'' ?>><?= $p ?></a>
      </li>
      <?php elseif ($p === 2 || $p === $exp_total_pages - 1): ?>
      <li class="govuk-pagination__item govuk-pagination__item--ellipses">&ctdot;</li>
      <?php endif; ?>
    <?php endfor; ?>
  </ul>
  <?php if ($exp_page < $exp_total_pages): ?>
  <div class="govuk-pagination__next">
    <a class="govuk-link govuk-pagination__link" href="?section=expiring&page=<?= $exp_page+1 ?>" rel="next"><span class="govuk-pagination__link-title">Next</span></a>
  </div>
  <?php endif; ?>
</nav>
<?php endif; ?>
<?php else: ?>
  <p class="govuk-body">No tech contracts expiring in the next 6 months.</p>
<?php endif; ?>

<?php if ($recently_expired): ?>
<h3 class="govuk-heading-s govuk-!-margin-top-6">Recently expired (last 3 months) — <?= count($recently_expired) ?></h3>
<p class="govuk-body-s" style="color:#505a5f">These may have been renewed or are currently being re-procured.</p>
<table class="govuk-table" style="font-size:0.875rem">
  <thead class="govuk-table__head"><tr class="govuk-table__row">
    <th class="govuk-table__header">Expired</th>
    <th class="govuk-table__header">Council</th>
    <th class="govuk-table__header">Supplier</th>
    <th class="govuk-table__header">Contract</th>
    <th class="govuk-table__header govuk-table__header--numeric">Value</th>
  </tr></thead>
  <tbody class="govuk-table__body">
  <?php foreach ($recently_expired as $e): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><?= date('d M Y', strtotime($e['end_date'])) ?> <span class="badge-expired">expired</span></td>
      <td class="govuk-table__cell"><a href="?council=<?= urlencode($e['council']) ?>" class="govuk-link"><?= htmlspecialchars($e['council']) ?></a></td>
      <td class="govuk-table__cell"><strong><?php $sup_name = $e['supplier_canon'] ?: $e['supplier']; ?><a href="supplier.php?supplier=<?= urlencode($sup_name) ?>" class="govuk-link"><?= htmlspecialchars($sup_name) ?></a></strong></td>
      <td class="govuk-table__cell" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($e['contract_title'] ?? '') ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= $e['value_amount'] ? '£' . number_format((float)$e['value_amount'], 0) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php elseif ($section === 'overview'): ?>

<!-- Filters -->
<form method="get" class="govuk-!-margin-bottom-4" style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap">
  <input type="hidden" name="section" value="overview">
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="q">Search council</label>
    <input class="govuk-input govuk-input--width-20" type="text" id="q" name="q" value="<?= htmlspecialchars($council_search) ?>" placeholder="e.g. Leeds">
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="ctype">Council type</label>
    <select class="govuk-select" id="ctype" name="ctype" onchange="this.form.submit()">
      <option value="">All types</option>
      <?php foreach ($ctype_options as $opt): ?>
        <option value="<?= htmlspecialchars($opt) ?>"<?= $ctype_selected===$opt?' selected':'' ?>><?= htmlspecialchars($opt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="region">Region</label>
    <select class="govuk-select" id="region" name="region" onchange="this.form.submit()">
      <option value="">All countries</option>
      <option value="England"<?= $region_selected==='England'?' selected':'' ?>>England</option>
      <option value="Scotland"<?= $region_selected==='Scotland'?' selected':'' ?>>Scotland</option>
      <option value="Wales"<?= $region_selected==='Wales'?' selected':'' ?>>Wales</option>
      <option value="Northern Ireland"<?= $region_selected==='Northern Ireland'?' selected':'' ?>>Northern Ireland</option>
      <?php foreach ($region_options as $opt): if (in_array($opt, ['England','Scotland','Wales','Northern Ireland'])) continue; ?>
        <option value="<?= htmlspecialchars($opt) ?>"<?= $region_selected===$opt?' selected':'' ?>><?= htmlspecialchars($opt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="awarded_after">Awarded after</label>
    <input class="govuk-input govuk-input--width-10" type="date" id="awarded_after" name="awarded_after" value="<?= htmlspecialchars($awarded_after) ?>" onchange="this.form.submit()">
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="awarded_before">Awarded before</label>
    <input class="govuk-input govuk-input--width-10" type="date" id="awarded_before" name="awarded_before" value="<?= htmlspecialchars($awarded_before) ?>" onchange="this.form.submit()">
  </div>
  <button class="govuk-button govuk-!-margin-bottom-0" type="submit">Search</button>
  <?php if ($ctype_selected || $region_selected !== 'England' || $awarded_after || $awarded_before || $council_search): ?>
    <a href="?section=overview" class="govuk-link" style="align-self:center">Clear</a>
  <?php endif; ?>
</form>

<!-- Stats -->
<div class="govuk-grid-row govuk-!-margin-bottom-6">
  <?php foreach ([
    [fmt_cr_value(isset($stats['tech_value']) && $stats['tech_value'] !== null ? (float)$stats['tech_value'] : null),  'Total tech contract value', 'Active contract values'],
    [number_format((int)$stats['tech']),         'Tech contracts',            'Matching supplier patterns'],
    [number_format((int)$stats['tech_suppliers']),'Tech suppliers',           'Named in contract registers'],
    [number_format((int)$stats['councils']),     'Councils',                  'With contract registers'],
  ] as [$n,$l,$s]): ?>
  <div class="govuk-grid-column-one-quarter govuk-!-margin-bottom-4" style="flex:1;min-width:160px">
    <div class="ct-stat-card">
      <div class="ct-stat-card__number"><?= $n ?></div>
      <div class="ct-stat-card__label"><?= $l ?></div>
      <div class="ct-stat-card__sub"><?= $s ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Council grid -->
<?php
if ($use_summary) {
    $councils = $pdo->query("SELECT s.council, s.total, s.tech, s.tech_value, s.from_date, s.to_date,
        cc.council_type, cc.region
        FROM ct_cr_council_summary s
        LEFT JOIN ct_council_config cc ON cc.council = s.council
        ORDER BY s.tech_value DESC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $grid_council_where = $council_where ? str_replace('cf.', 'cc.', $council_where) : '';
    $grid_sql = "SELECT e.council,
           COUNT(*) AS total,
           SUM(e.is_tech) AS tech,
           SUM(CASE WHEN e.is_tech = 1 THEN e.value_amount END) AS tech_value,
           MIN(e.start_date) AS from_date,
           MAX(e.end_date) AS to_date,
           cc.council_type,
           cc.region
    FROM ct_contract_register_entries e
    LEFT JOIN ct_council_config cc ON cc.council = e.council
    WHERE 1=1 $grid_council_where $date_where
    GROUP BY e.council, cc.council_type, cc.region
    ORDER BY tech_value DESC";
    $stmt = $pdo->prepare($grid_sql);
    $stmt->execute(array_merge($council_params, $date_params));
    $councils = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$overview_per_page = 15;
$overview_total_pages = max(1, (int)ceil(count($councils) / $overview_per_page));
$overview_page = max(1, min($overview_total_pages, (int)($_GET['page'] ?? 1)));
$page_councils = array_slice($councils, ($overview_page - 1) * $overview_per_page, $overview_per_page);
$overview_qs = 'section=overview&q=' . urlencode($council_search) . '&ctype=' . urlencode($ctype_selected) . '&region=' . urlencode($region_selected) . '&awarded_after=' . urlencode($awarded_after) . '&awarded_before=' . urlencode($awarded_before);
?>

<?php if ($councils): ?>
<div class="ct-spender-grid">
  <?php foreach ($page_councils as $c): ?>
  <div class="ct-stat-card">
    <div style="font-size:1rem;font-weight:700;margin-bottom:4px"><?= htmlspecialchars($c['council']) ?></div>
    <?php if ($c['council_type'] || $c['region']): ?>
      <div style="font-size:0.75rem;color:#505a5f;margin-bottom:4px">
        <?= htmlspecialchars($c['council_type'] ?? '') ?><?= ($c['council_type'] && $c['region']) ? ' &middot; ' : '' ?><?= htmlspecialchars($c['region'] ?? '') ?>
      </div>
    <?php endif; ?>
    <div style="font-size:1.75rem;font-weight:700;color:#1d70b8;margin:6px 0"><?= fmt_cr_value(isset($c['tech_value']) && $c['tech_value'] !== null ? (float)$c['tech_value'] : null) ?></div>
    <div style="font-size:0.8rem;color:#505a5f">
      <?= (int)$c['tech'] ?> tech / <?= (int)$c['total'] ?> total
      <?php if (!empty($c['from_date'])): ?>
        &middot; <?= date('d/m/Y', strtotime($c['from_date'])) ?> – <?= !empty($c['to_date']) ? date('d/m/Y', strtotime($c['to_date'])) : '?' ?>
      <?php endif; ?>
    </div>
    <div style="margin-top:8px">
      <a href="?council=<?= urlencode($c['council']) ?>" class="govuk-link" style="font-size:0.875rem">View contracts →</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($overview_total_pages > 1): ?>
<nav class="govuk-pagination" role="navigation" aria-label="Council overview pagination">
  <?php if ($overview_page > 1): ?>
  <div class="govuk-pagination__prev">
    <a class="govuk-link govuk-pagination__link" href="?<?= $overview_qs ?>&page=<?= $overview_page-1 ?>" rel="prev"><span class="govuk-pagination__link-title">Previous</span></a>
  </div>
  <?php endif; ?>
  <ul class="govuk-pagination__list">
    <?php for ($p = 1; $p <= $overview_total_pages; $p++): ?>
      <?php if ($p === 1 || $p === $overview_total_pages || abs($p - $overview_page) <= 2): ?>
      <li class="govuk-pagination__item<?= $p===$overview_page?' govuk-pagination__item--current':'' ?>">
        <a class="govuk-link govuk-pagination__link" href="?<?= $overview_qs ?>&page=<?= $p ?>"<?= $p===$overview_page?' aria-current="page"':'' ?>><?= $p ?></a>
      </li>
      <?php elseif ($p === 2 || $p === $overview_total_pages - 1): ?>
      <li class="govuk-pagination__item govuk-pagination__item--ellipses">&ctdot;</li>
      <?php endif; ?>
    <?php endfor; ?>
  </ul>
  <?php if ($overview_page < $overview_total_pages): ?>
  <div class="govuk-pagination__next">
    <a class="govuk-link govuk-pagination__link" href="?<?= $overview_qs ?>&page=<?= $overview_page+1 ?>" rel="next"><span class="govuk-pagination__link-title">Next</span></a>
  </div>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php else: ?>
  <div class="govuk-inset-text">
    <p class="govuk-body">No contract registers imported yet.</p>
    <p class="govuk-body-s">Register council URLs in <code>ct_contract_registers</code> and run <code>php8.1 import_contract_registers.php</code>.</p>
  </div>
<?php endif; ?>

<?php elseif ($section === 'suppliers'): ?>

<?php
$per_page = 40;
$page = max(1, (int)($_GET['page'] ?? 1));

// Build supplier query with optional filter
$sup_where = ['e.is_tech = 1'];
$sup_params = [];
// Check if this is an exact supplier match (for drill-down) or a partial search (for table)
$supplier_exact = false;
if ($supplier_filter) {
    $exact_check = $pdo->prepare("SELECT 1 FROM ct_contract_register_entries WHERE supplier_canon = ? AND is_tech = 1 LIMIT 1");
    $exact_check->execute([$supplier_filter]);
    $supplier_exact = (bool)$exact_check->fetchColumn();
    $sup_where[] = 'e.supplier_canon LIKE ?';
    $sup_params[] = '%' . $supplier_filter . '%';
}
if ($ctype_selected || $region_selected) {
    $cc_conds = [];
    if ($ctype_selected) { $cc_conds[] = "supcc.council_type = ?"; $sup_params[] = $ctype_selected; }
    if ($region_selected) {
        if (isset($_country_map[$region_selected])) {
            $cc_conds[] = "supcc.lad_code LIKE ?"; $sup_params[] = $_country_map[$region_selected];
        } else {
            $cc_conds[] = "supcc.region = ?"; $sup_params[] = $region_selected;
        }
    }
    $sup_where[] = "EXISTS (SELECT 1 FROM ct_council_config supcc WHERE supcc.council = e.council AND " . implode(' AND ', $cc_conds) . ")";
}
$sup_where_sql = implode(' AND ', $sup_where);

// Count distinct suppliers
$count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT supplier_canon) FROM ct_contract_register_entries e WHERE $sup_where_sql");
$count_stmt->execute($sup_params);
$total_suppliers = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_suppliers / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Paginated supplier list
$list_stmt = $pdo->prepare("
    SELECT e.supplier_canon,
           COUNT(*) AS contracts,
           SUM(e.value_amount) AS total_value,
           COUNT(DISTINCT e.council) AS councils,
           MAX(e.end_date) AS latest_end
    FROM ct_contract_register_entries e
    WHERE $sup_where_sql
    GROUP BY e.supplier_canon
    ORDER BY total_value DESC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($sup_params);
$suppliers = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<p class="govuk-body govuk-!-colour-secondary">
  Tech suppliers from published contract registers. Click a supplier to see their contracts by council.
</p>

<form method="get" action="/contracts/contracts_reg.php"
      style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;align-items:flex-end">
  <input type="hidden" name="section" value="suppliers">
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label" for="s_supplier">Supplier</label>
    <input class="govuk-input govuk-input--width-20" type="text" id="s_supplier" name="supplier"
           value="<?= htmlspecialchars($supplier_filter) ?>" placeholder="e.g. Civica">
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label" for="s_ctype">Council type</label>
    <select class="govuk-select" id="s_ctype" name="ctype">
      <option value="">All types</option>
      <?php foreach ($ctype_options as $opt): ?>
        <option value="<?= htmlspecialchars($opt) ?>"<?= $ctype_selected===$opt?' selected':'' ?>><?= htmlspecialchars($opt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label" for="s_region">Region</label>
    <select class="govuk-select" id="s_region" name="region">
      <option value="">All countries</option>
      <option value="England"<?= $region_selected==='England'?' selected':'' ?>>England</option>
      <option value="Scotland"<?= $region_selected==='Scotland'?' selected':'' ?>>Scotland</option>
      <option value="Wales"<?= $region_selected==='Wales'?' selected':'' ?>>Wales</option>
      <option value="Northern Ireland"<?= $region_selected==='Northern Ireland'?' selected':'' ?>>Northern Ireland</option>
      <?php foreach ($region_options as $opt): if (in_array($opt, ['England','Scotland','Wales','Northern Ireland'])) continue; ?>
        <option value="<?= htmlspecialchars($opt) ?>"<?= $region_selected===$opt?' selected':'' ?>><?= htmlspecialchars($opt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="govuk-button govuk-!-margin-bottom-0" type="submit">Filter</button>
  <?php if ($supplier_filter || $ctype_selected || $region_selected !== 'England'): ?>
    <a href="?section=suppliers" class="govuk-link" style="align-self:center">Clear</a>
  <?php endif; ?>
</form>

<?php if (!$supplier_filter || !$supplier_exact): ?>
<table class="govuk-table sortable-table" style="font-size:0.875rem">
  <thead class="govuk-table__head">
    <tr class="govuk-table__row">
      <th class="govuk-table__header sortable" data-col="0" data-type="str">Supplier <span class="sort-icon">⇅</span></th>
      <th class="govuk-table__header govuk-table__header--numeric sortable sort-desc" data-col="1" data-type="num">Total value <span class="sort-icon">↓</span></th>
      <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="2" data-type="num">Contracts <span class="sort-icon">⇅</span></th>
      <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="3" data-type="num">Councils <span class="sort-icon">⇅</span></th>
      <th class="govuk-table__header">Latest end date</th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
  <?php foreach ($suppliers as $s): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell">
        <a href="?section=suppliers&supplier=<?= urlencode($s['supplier_canon']) ?>" class="govuk-link"><?= htmlspecialchars($s['supplier_canon']) ?></a>
      </td>
      <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= (int)($s['total_value'] ?? 0) ?>"><?= $s['total_value'] ? fmt_cr_value((float)$s['total_value']) : '—' ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= (int)$s['contracts'] ?>"><?= number_format((int)$s['contracts']) ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= (int)$s['councils'] ?>"><?= (int)$s['councils'] ?></td>
      <td class="govuk-table__cell"><?= $s['latest_end'] ? date('M Y', strtotime($s['latest_end'])) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($total_pages > 1): ?>
<nav class="govuk-pagination" role="navigation" aria-label="Supplier list pagination">
  <?php $pqs = 'section=suppliers&supplier=' . urlencode($supplier_filter) . '&ctype=' . urlencode($ctype_selected) . '&region=' . urlencode($region_selected); ?>
  <?php if ($page > 1): ?>
  <div class="govuk-pagination__prev">
    <a class="govuk-link govuk-pagination__link" href="?<?= $pqs ?>&page=<?= $page-1 ?>" rel="prev"><span class="govuk-pagination__link-title">Previous</span></a>
  </div>
  <?php endif; ?>
  <ul class="govuk-pagination__list">
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
      <?php if ($p === 1 || $p === $total_pages || abs($p - $page) <= 2): ?>
      <li class="govuk-pagination__item<?= $p===$page?' govuk-pagination__item--current':'' ?>">
        <a class="govuk-link govuk-pagination__link" href="?<?= $pqs ?>&page=<?= $p ?>"<?= $p===$page?' aria-current="page"':'' ?>><?= $p ?></a>
      </li>
      <?php elseif ($p === 2 || $p === $total_pages - 1): ?>
      <li class="govuk-pagination__item govuk-pagination__item--ellipses">&ctdot;</li>
      <?php endif; ?>
    <?php endfor; ?>
  </ul>
  <?php if ($page < $total_pages): ?>
  <div class="govuk-pagination__next">
    <a class="govuk-link govuk-pagination__link" href="?<?= $pqs ?>&page=<?= $page+1 ?>" rel="next"><span class="govuk-pagination__link-title">Next</span></a>
  </div>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php if ($supplier_filter && $supplier_exact): ?>
<!-- Drill-down: show this supplier's contracts grouped by council -->
<?php
$detail_tab = ($_GET['tab'] ?? 'councils') === 'contracts' ? 'contracts' : 'councils';

// Build region/ctype sub-filter for drill-down (matches table filter)
$dd_where = ["e.supplier_canon = ?", "e.is_tech = 1"];
$dd_params = [$supplier_filter];
if ($ctype_selected || $region_selected) {
    $dd_cc = [];
    if ($ctype_selected) { $dd_cc[] = "ddcc.council_type = ?"; $dd_params[] = $ctype_selected; }
    if ($region_selected) {
        if (isset($_country_map[$region_selected])) { $dd_cc[] = "ddcc.lad_code LIKE ?"; $dd_params[] = $_country_map[$region_selected]; }
        else { $dd_cc[] = "ddcc.region = ?"; $dd_params[] = $region_selected; }
    }
    $dd_where[] = "EXISTS (SELECT 1 FROM ct_council_config ddcc WHERE ddcc.council = e.council AND " . implode(" AND ", $dd_cc) . ")";
}
$dd_where_sql = implode(" AND ", $dd_where);

$detail_stmt = $pdo->prepare("
    SELECT e.council, COUNT(*) AS contracts, SUM(e.value_amount) AS total_value,
           MIN(e.start_date) AS earliest_start, MAX(e.end_date) AS latest_end
    FROM ct_contract_register_entries e
    WHERE $dd_where_sql
    GROUP BY e.council
    ORDER BY total_value DESC
");
$detail_stmt->execute($dd_params);
$council_breakdown = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);

$all_contracts_stmt = $pdo->prepare("
    SELECT e.id, e.council, e.contract_title, e.value_amount, e.start_date, e.end_date, e.tender_process, e.department
    FROM ct_contract_register_entries e
    WHERE $dd_where_sql
    ORDER BY e.value_amount DESC
");
$all_contracts_stmt->execute($dd_params);
$all_contracts = $all_contracts_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h3 class="govuk-heading-m govuk-!-margin-top-6"><?= htmlspecialchars($supplier_filter) ?></h3>
<p class="govuk-body-s" style="color:#505a5f">
  <?= count($all_contracts) ?> tech contract<?= count($all_contracts) !== 1 ? 's' : '' ?> across <?= count($council_breakdown) ?> council<?= count($council_breakdown) !== 1 ? 's' : '' ?>
</p>
<?php
// LGAM products for this supplier (matched via ct_suppliers canonical_name → company_number)
$lgam_prod_stmt = $pdo->prepare("
    SELECT p.id, p.display_name, p.description,
           GROUP_CONCAT(DISTINCT pa.node_id ORDER BY pa.node_id SEPARATOR ', ') as areas
    FROM ct_lgam_products p
    JOIN ct_suppliers s ON s.company_number = p.company_number
    LEFT JOIN ct_lgam_product_areas pa ON pa.product_id = p.id
    WHERE s.canonical_name = ?
    GROUP BY p.id
    ORDER BY p.product_name
");
$lgam_prod_stmt->execute([$supplier_filter]);
$lgam_prods = $lgam_prod_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php if ($lgam_prods): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:20px">
  <?php foreach ($lgam_prods as $lp): ?>
  <a href="/contracts/lgam_classify.php?product=<?= $lp['id'] ?>" class="govuk-link" style="text-decoration:none">
    <div style="border:1px solid #b1b4b6;border-radius:4px;padding:10px 14px;background:#fff;height:100%;box-sizing:border-box">
      <div style="font-weight:600;font-size:0.9rem;color:#1d70b8"><?= htmlspecialchars($lp['display_name']) ?></div>
      <?php if ($lp['description']): ?><div style="font-size:0.75rem;color:#505a5f;margin-top:3px"><?= htmlspecialchars($lp['description']) ?></div><?php endif; ?>
      <?php if ($lp['areas']): ?><div style="font-size:0.7rem;color:#505a5f;margin-top:4px"><?= htmlspecialchars($lp['areas']) ?></div><?php endif; ?>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>


<div class="tech-nav" style="margin-bottom:16px">
  <a href="?section=suppliers&supplier=<?= urlencode($supplier_filter) ?>&tab=councils" class="<?= $detail_tab==='councils' ? 'active' : '' ?>">By council (<?= count($council_breakdown) ?>)</a>
  <a href="?section=suppliers&supplier=<?= urlencode($supplier_filter) ?>&tab=contracts" class="<?= $detail_tab==='contracts' ? 'active' : '' ?>">All contracts (<?= count($all_contracts) ?>)</a>
</div>

<?php if ($detail_tab === 'councils'): ?>
<table class="govuk-table" style="font-size:0.875rem">
  <thead class="govuk-table__head"><tr class="govuk-table__row">
    <th class="govuk-table__header">Council</th>
    <th class="govuk-table__header govuk-table__header--numeric">Total value</th>
    <th class="govuk-table__header govuk-table__header--numeric">Contracts</th>
    <th class="govuk-table__header">Earliest start</th>
    <th class="govuk-table__header">Latest end</th>
  </tr></thead>
  <tbody class="govuk-table__body">
  <?php foreach ($council_breakdown as $cb): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><a href="?council=<?= urlencode($cb['council']) ?>" class="govuk-link"><?= htmlspecialchars($cb['council']) ?></a></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= $cb['total_value'] ? fmt_cr_value((float)$cb['total_value']) : '—' ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= (int)$cb['contracts'] ?></td>
      <td class="govuk-table__cell"><?= $cb['earliest_start'] ? date('M Y', strtotime($cb['earliest_start'])) : '—' ?></td>
      <td class="govuk-table__cell"><?= $cb['latest_end'] ? date('M Y', strtotime($cb['latest_end'])) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
<table class="govuk-table" style="font-size:0.875rem">
  <thead class="govuk-table__head"><tr class="govuk-table__row">
    <th class="govuk-table__header">Council</th>
    <th class="govuk-table__header">Contract</th>
    <th class="govuk-table__header govuk-table__header--numeric">Value</th>
    <th class="govuk-table__header">Start</th>
    <th class="govuk-table__header">End</th>
    <th class="govuk-table__header">Tender process</th>
    <th class="govuk-table__header"></th>
  </tr></thead>
  <tbody class="govuk-table__body">
  <?php foreach ($all_contracts as $c): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><a href="?council=<?= urlencode($c['council']) ?>" class="govuk-link"><?= htmlspecialchars($c['council']) ?></a></td>
      <td class="govuk-table__cell" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($c['contract_title'] ?? '') ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric" style="font-size:0.8em"><?= $c['value_amount'] ? fmt_cr_value((float)$c['value_amount']) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8em"><?= $c['start_date'] ? date('M Y', strtotime($c['start_date'])) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8em"><?= $c['end_date'] ? date('M Y', strtotime($c['end_date'])) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8em"><?= htmlspecialchars($c['tender_process'] ?? '—') ?></td>
      <td class="govuk-table__cell" style="font-size:0.8em;white-space:nowrap"><a href="/contracts/contract_spend.php?id=<?= (int)$c['id'] ?>" class="govuk-link">View spend</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php endif; ?>

<script>
document.querySelectorAll('.sortable-table').forEach(function(table) {
  var tbody = table.querySelector('tbody');
  var headers = table.querySelectorAll('th.sortable');
  var sortCol = -1, sortAsc = true;
  headers.forEach(function(th, i) {
    if (th.classList.contains('sort-desc')) { sortCol = i; sortAsc = false; }
    th.style.cursor = 'pointer';
    th.addEventListener('click', function() {
      sortAsc = (sortCol === i) ? !sortAsc : (th.dataset.type === 'str');
      sortCol = i;
      headers.forEach(function(h) { h.querySelector('.sort-icon').textContent = '⇅'; });
      th.querySelector('.sort-icon').textContent = sortAsc ? '↑' : '↓';
      var rows = Array.from(tbody.querySelectorAll('tr'));
      rows.sort(function(a, b) {
        var ac = a.querySelectorAll('td')[i], bc = b.querySelectorAll('td')[i];
        var av = ac.dataset.val !== undefined ? parseFloat(ac.dataset.val) : ac.textContent.trim();
        var bv = bc.dataset.val !== undefined ? parseFloat(bc.dataset.val) : bc.textContent.trim();
        return typeof av === 'string'
          ? (sortAsc ? av.localeCompare(bv) : bv.localeCompare(av))
          : (sortAsc ? av - bv : bv - av);
      });
      rows.forEach(function(r) { tbody.appendChild(r); });
    });
  });
});
</script>


<?php elseif ($section === 'sources'): ?>

<?php
$registers = $pdo->query("SELECT * FROM ct_contract_registers ORDER BY council")->fetchAll(PDO::FETCH_ASSOC);
?>

<h2 class="govuk-heading-m">Registered contract register sources</h2>
<p class="govuk-body"><?= count($registers) ?> council<?= count($registers) !== 1 ? 's' : '' ?> registered.</p>

<?php if ($registers): ?>
<table class="govuk-table">
  <thead class="govuk-table__head"><tr class="govuk-table__row">
    <th class="govuk-table__header">Council</th>
    <th class="govuk-table__header">Format</th>
    <th class="govuk-table__header" style="text-align:right">Contracts</th>
    <th class="govuk-table__header">Last fetched</th>
    <th class="govuk-table__header">Source</th>
  </tr></thead>
  <tbody class="govuk-table__body">
  <?php foreach ($registers as $r): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><strong><?= htmlspecialchars($r['council']) ?></strong></td>
      <td class="govuk-table__cell"><?= strtoupper($r['format']) ?></td>
      <td class="govuk-table__cell" style="text-align:right"><?= $r['last_count'] !== null ? number_format((int)$r['last_count']) : '—' ?></td>
      <td class="govuk-table__cell"><?= $r['last_fetched'] ? date('d M Y', strtotime($r['last_fetched'])) : 'Never' ?></td>
      <td class="govuk-table__cell">
        <?php if ($r['listing_url']): ?>
          <a href="<?= htmlspecialchars($r['listing_url']) ?>" class="govuk-link" target="_blank" rel="noopener">Listing page</a>
        <?php else: ?>
          —
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
  <div class="govuk-inset-text">
    <p class="govuk-body">No contract registers registered yet.</p>
  </div>
<?php endif; ?>

<?php endif; ?>

<?php layout_foot(); ?>
