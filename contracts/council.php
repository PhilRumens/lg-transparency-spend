<?php
/**
 * council.php — Unified council page keyed by lad_code.
 * URL: /contracts/council.php?id=E08000025
 */
session_cache_limiter("");
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_layout.php';

header_remove("Expires"); header_remove("Pragma"); header("Cache-Control: public, max-age=300", true);
$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$lad_code = $_GET['id'] ?? '';
if (!preg_match('/^[A-Z]\d{8}$/', $lad_code)) {
    // Show council index
    $all_councils = $pdo->query("
        SELECT cc.council, cc.lad_code, cc.council_type, cc.region, cc.population,
               COALESCE(cs.total_spend, 0) as total_spend,
               COALESCE(cs.payment_count, 0) as payment_count
        FROM ct_council_config cc
        LEFT JOIN ct_council_summary cs ON cs.lad_code = cc.lad_code
        WHERE cc.lad_code IS NOT NULL AND cc.lad_code != ''
        ORDER BY cs.total_spend DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Filters
    $fy = $_GET['fy'] ?? '2025';
    $fy_options = ['all' => 'All years', '2025' => '2025/26', '2024' => '2024/25', '2023' => '2023/24', '2022' => '2022/23'];
    $ctype_filter = $_GET['ctype'] ?? '';
    // Default to England only when the region param is entirely absent (first
    // visit). An explicitly-empty region (region=) means the user chose "All
    // areas", so honour it rather than collapsing back to England.
    $region_filter = array_key_exists('region', $_GET) ? $_GET['region'] : 'England';

    // If FY filter is active, re-query with date range
    if ($fy !== 'all') {
        $fy_stmt = $pdo->prepare("
            SELECT cc.council, cc.lad_code, cc.council_type, cc.region, cc.population,
                   COALESCE(csf.total_spend, 0) as total_spend,
                   COALESCE(csf.payment_count, 0) as payment_count
            FROM ct_council_config cc
            LEFT JOIN ct_council_summary_fy csf
              ON csf.lad_code = cc.lad_code AND csf.fy_year = ?
            WHERE cc.lad_code IS NOT NULL AND cc.lad_code != ''
            ORDER BY csf.total_spend DESC
        ");
        $fy_stmt->execute([(int)$fy]);
        $all_councils = $fy_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $devolved_regions = ['Scotland', 'Wales', 'Northern Ireland'];
    // Get unique types and regions for filter dropdowns
    $all_types = array_values(array_unique(array_filter(array_column($all_councils, 'council_type'),
        fn($t) => $t !== 'Strategic Authority' && $t !== 'Unitary Authority (proposed)'
    )));
    $all_regions = array_values(array_unique(array_filter(array_column($all_councils, 'region'))));
    // Northern Ireland is a hardcoded nation option (rendered beside England/
    // Scotland/Wales below), so exclude it from the English-regions loop too.
    $all_regions = array_values(array_filter($all_regions, fn($r) => !in_array($r, ['England', 'Scotland', 'Wales', 'Northern Ireland'])));
    sort($all_types);
    sort($all_regions);

    // Filter to councils with spend data only
    $visible_councils = array_values(array_filter($all_councils, fn($c) => (float)$c['total_spend'] > 0));
    if ($ctype_filter) $visible_councils = array_values(array_filter($visible_councils, fn($c) => ($c['council_type'] ?? '') === $ctype_filter));
    if ($region_filter === 'England') $visible_councils = array_values(array_filter($visible_councils, fn($c) => ($c['lad_code'][0] ?? '') === 'E'));
    elseif ($region_filter) $visible_councils = array_values(array_filter($visible_councils, fn($c) => ($c['region'] ?? '') === $region_filter && ($c['council_type'] ?? '') !== 'Strategic Authority'));
    else $visible_councils = array_values(array_filter($visible_councils, fn($c) => !(($c['council_type'] ?? '') === 'Strategic Authority' && in_array($c['region'] ?? '', $devolved_regions))));

    $sort_col = $_GET['sort'] ?? 'spend';
    $sort_dir = $_GET['dir'] ?? 'desc';
    usort($visible_councils, function($a, $b) use ($sort_col, $sort_dir) {
        $cmp = match($sort_col) {
            'name' => strcasecmp($a['council'], $b['council']),
            'type' => strcasecmp($a['council_type'] ?? '', $b['council_type'] ?? ''),
            'region' => strcasecmp($a['region'] ?? '', $b['region'] ?? ''),
            'spend' => (float)$b['total_spend'] <=> (float)$a['total_spend'],
            'payments' => (int)$b['payment_count'] <=> (int)$a['payment_count'],
            default => (float)$b['total_spend'] <=> (float)$a['total_spend'],
        };
        return $sort_dir === 'asc' ? -$cmp : $cmp;
    });

    $search_q = trim($_GET['q'] ?? '');
    if ($search_q) {
        $search_lower = strtolower($search_q);
        $visible_councils = array_values(array_filter($visible_councils, fn($c) =>
            str_contains(strtolower($c['council']), $search_lower) ||
            str_contains(strtolower($c['council_type'] ?? ''), $search_lower)
        ));
    }

    $c_per_page = 50;
    $c_total_pages = max(1, (int)ceil(count($visible_councils) / $c_per_page));
    $c_page = max(1, min($c_total_pages, (int)($_GET['page'] ?? 1)));
    $c_slice = array_slice($visible_councils, ($c_page - 1) * $c_per_page, $c_per_page);
    $qs_base = '&fy=' . urlencode($fy) . ($search_q ? '&q=' . urlencode($search_q) : '') . '&sort=' . $sort_col . '&dir=' . $sort_dir . ($ctype_filter ? '&ctype=' . urlencode($ctype_filter) : '') . '&region=' . urlencode($region_filter);

    $sort_link = function($col, $label) use ($sort_col, $sort_dir, $search_q, $fy) {
        $new_dir = ($sort_col === $col && $sort_dir === 'desc') ? 'asc' : 'desc';
        $arrow = $sort_col === $col ? ($sort_dir === 'desc' ? ' ▾' : ' ▴') : '';
        $fy_qs = $fy !== 'all' ? '&fy=' . urlencode($fy) : '';
        $q = $search_q ? '&q=' . urlencode($search_q) : '';
        return '<a href="?sort=' . $col . '&dir=' . $new_dir . $fy_qs . $q . '" class="govuk-link" style="text-decoration:none;color:inherit">' . $label . $arrow . '</a>';
    };

    layout_head('Councils');

    echo '<form method="get" style="margin-bottom:16px;display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap">';
    echo '<div class="govuk-form-group govuk-!-margin-bottom-0"><label class="govuk-label govuk-!-font-size-16" for="fy">Financial year</label>';
    echo '<select class="govuk-select" id="fy" name="fy" onchange="this.form.submit()">';
    foreach ($fy_options as $val => $label) {
        echo '<option value="' . $val . '"' . ($fy == $val ? ' selected' : '') . '>' . $label . '</option>';
    }
    echo '</select></div>';
    echo '<div class="govuk-form-group govuk-!-margin-bottom-0"><label class="govuk-label govuk-!-font-size-16" for="ctype">Council type</label>';
    echo '<select class="govuk-select" id="ctype" name="ctype" onchange="this.form.submit()">';
    echo '<option value="">All types</option>';
    foreach ($all_types as $t) echo '<option value="' . htmlspecialchars($t) . '"' . ($ctype_filter === $t ? ' selected' : '') . '>' . htmlspecialchars($t) . '</option>';
    echo '</select></div>';
    echo '<div class="govuk-form-group govuk-!-margin-bottom-0"><label class="govuk-label govuk-!-font-size-16" for="region">Area</label>';
    echo '<select class="govuk-select" id="region" name="region" onchange="this.form.submit()">';
    echo '<option value="">All areas</option>';
    echo '<option value="England"' . ($region_filter === 'England' ? ' selected' : '') . '>England</option>';
    echo '<option value="Scotland"' . ($region_filter === 'Scotland' ? ' selected' : '') . '>Scotland</option>';
    echo '<option value="Wales"' . ($region_filter === 'Wales' ? ' selected' : '') . '>Wales</option>';
    echo '<option value="Northern Ireland"' . ($region_filter === 'Northern Ireland' ? ' selected' : '') . '>Northern Ireland</option>';
    foreach ($all_regions as $r) echo '<option value="' . htmlspecialchars($r) . '"' . ($region_filter === $r ? ' selected' : '') . '>' . htmlspecialchars($r) . '</option>';
    echo '</select></div>';
    echo '<div class="govuk-form-group govuk-!-margin-bottom-0"><label class="govuk-label govuk-!-font-size-16" for="q">Search</label>';
    echo '<input class="govuk-input govuk-input--width-10" id="q" name="q" type="text" value="' . htmlspecialchars($search_q) . '"></div>';
    echo '<button class="govuk-button govuk-!-margin-bottom-0" type="submit">Filter</button>';
    if ($search_q || $fy != '2025' || $ctype_filter || $region_filter !== 'England') echo ' <a href="/contracts/council.php" class="govuk-link" style="line-height:40px">Clear</a>';
    echo '</form>';

    $list_total_spend    = array_sum(array_column($visible_councils, 'total_spend'));
    $list_total_payments = array_sum(array_column($visible_councils, 'payment_count'));
    $spend_fmt = $list_total_spend >= 1e9 ? '£' . number_format($list_total_spend/1e9,1) . 'bn'
               : ($list_total_spend >= 1e6 ? '£' . number_format($list_total_spend/1e6,1) . 'm' : '£' . number_format($list_total_spend/1e3,0) . 'k');
    echo '<div class="govuk-grid-row govuk-!-margin-bottom-4">';
    foreach ([
        [count($visible_councils),            'Councils',    $search_q ? 'Matching search' : 'With spend data'],
        [$spend_fmt,                           'Total spend', 'Across all filtered councils'],
        [number_format($list_total_payments), 'Payments',    'Transaction count'],
    ] as [$n, $l, $s]) {
        echo '<div class="govuk-grid-column-one-third govuk-!-margin-bottom-4"><div class="ct-stat-card"><div class="ct-stat-card__number">' . $n . '</div><div class="ct-stat-card__label">' . $l . '</div><div class="ct-stat-card__sub">' . $s . '</div></div></div>';
    }
    echo '</div>';
    echo '<table class="govuk-table"><thead class="govuk-table__head"><tr>';
    echo '<th class="govuk-table__header">' . $sort_link('name', 'Council') . '</th>';
    echo '<th class="govuk-table__header">' . $sort_link('type', 'Type') . '</th>';
    echo '<th class="govuk-table__header">' . $sort_link('region', 'Region') . '</th>';
    echo '<th class="govuk-table__header govuk-table__header--numeric">' . $sort_link('spend', 'Tech spend') . '</th>';
    echo '<th class="govuk-table__header govuk-table__header--numeric">' . $sort_link('payments', 'Payments') . '</th>';
    echo '</tr></thead><tbody class="govuk-table__body">';
    foreach ($c_slice as $c) {
        $spend = $c['total_spend'] ? '£' . number_format((float)$c['total_spend'] / 1e6, 1) . 'M' : '—';
        echo '<tr class="govuk-table__row">';
        $council_url = '/contracts/council.php?id=' . htmlspecialchars($c['lad_code']) . ($fy !== 'all' ? '&fy=' . urlencode($fy) : '');
        echo '<td class="govuk-table__cell"><a class="govuk-link" href="' . $council_url . '">' . htmlspecialchars($c['council']) . '</a></td>';
        $ctype_display = ($c['council_type'] === 'Strategic Authority' && in_array($c['region'] ?? '', $devolved_regions))
            ? 'National Government' : ($c['council_type'] ?? '');
        echo '<td class="govuk-table__cell" style="font-size:0.85rem">' . htmlspecialchars($ctype_display) . '</td>';
        echo '<td class="govuk-table__cell" style="font-size:0.85rem">' . htmlspecialchars($c['region'] ?? '') . '</td>';
        echo '<td class="govuk-table__cell govuk-table__cell--numeric">' . $spend . '</td>';
        echo '<td class="govuk-table__cell govuk-table__cell--numeric">' . number_format((int)$c['payment_count']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    if ($c_total_pages > 1) {
        echo '<nav class="govuk-pagination" role="navigation" aria-label="Councils pagination">';
        if ($c_page > 1) echo '<div class="govuk-pagination__prev"><a class="govuk-link govuk-pagination__link" href="?' . $qs_base . '&page=' . ($c_page-1) . '" rel="prev"><span class="govuk-pagination__link-title">Previous</span></a></div>';
        echo '<ul class="govuk-pagination__list">';
        for ($p = 1; $p <= $c_total_pages; $p++) {
            if ($p === 1 || $p === $c_total_pages || abs($p - $c_page) <= 2) {
                echo '<li class="govuk-pagination__item' . ($p===$c_page?' govuk-pagination__item--current':'') . '"><a class="govuk-link govuk-pagination__link" href="?' . $qs_base . '&page=' . $p . '"' . ($p===$c_page?' aria-current="page"':'') . '>' . $p . '</a></li>';
            } elseif ($p === 2 || $p === $c_total_pages - 1) {
                echo '<li class="govuk-pagination__item govuk-pagination__item--ellipses">&ctdot;</li>';
            }
        }
        echo '</ul>';
        if ($c_page < $c_total_pages) echo '<div class="govuk-pagination__next"><a class="govuk-link govuk-pagination__link" href="?' . $qs_base . '&page=' . ($c_page+1) . '" rel="next"><span class="govuk-pagination__link-title">Next</span></a></div>';
        echo '</nav>';
    }
    layout_foot(true);
    exit;
}

// Resolve council config
$cfg_stmt = $pdo->prepare("
    SELECT cc.*, r.net_revenue_exp, r.budget_year, r.imd_avg_score, r.imd_rank, r.imd_year
    FROM ct_council_config cc
    LEFT JOIN ct_la_reference r ON r.lad_code = cc.lad_code
    WHERE cc.lad_code = ?
");
$cfg_stmt->execute([$lad_code]);
$council_cfg = $cfg_stmt->fetch(PDO::FETCH_ASSOC);
if (!$council_cfg) {
    layout_head('Council not found');
    echo '<p class="govuk-body">No council found for code ' . htmlspecialchars($lad_code) . '</p>';
    layout_foot(true);
    exit;
}
$council_name = $council_cfg['council'];

// Internal joint/shared-service entities (empty lad_code) are not real councils
// and must never render as their own page — their spend is attributed 50/50 to
// the real councils below. (Guards ?id= with an empty/joint lad_code.)
if (is_joint_council($council_name)) {
    layout_head('Council not found');
    echo '<p class="govuk-body">No council found for code ' . htmlspecialchars($lad_code) . '</p>';
    layout_foot(true);
    exit;
}

$view = $_GET['view'] ?? 'payments';
$c_fy = $_GET['fy'] ?? '';
$fy_options_c = [''=>'All years','2025'=>'2025/26','2024'=>'2024/25','2023'=>'2023/24','2022'=>'2022/23'];
if ($c_fy && isset($fy_options_c[$c_fy])) {
    $fy_from = (int)$c_fy;
} else {
    $c_fy = '';
    $fy_from = null;
}
$base_url = '?id=' . urlencode($lad_code) . ($c_fy ? '&fy=' . urlencode($c_fy) : '');

// ── Payments summary ─────────────────────────────────────────────────────
// Filter by period using same bucketing as ct_council_summary_fy (Apr = start of FY)
$date_clause = $fy_from ? " AND (CASE WHEN CAST(SUBSTRING(period,6,2) AS UNSIGNED) >= 4 THEN CAST(SUBSTRING(period,1,4) AS UNSIGNED) ELSE CAST(SUBSTRING(period,1,4) AS UNSIGNED)-1 END) = ?" : '';
$pay_stmt = $pdo->prepare("
    SELECT COUNT(*) as payment_count,
           COUNT(DISTINCT supplier_canon) as distinct_suppliers,
           SUM(amount) as total_spend,
           MIN(paid_date) as earliest_payment,
           MAX(paid_date) as latest_payment,
           COUNT(DISTINCT period) as periods_covered
    FROM ct_spend_display WHERE lad_code = ? AND internal_provider = 0 AND (validation_status IS NULL OR validation_status != 'invalid')$date_clause
");
$pay_stmt->execute($fy_from ? [$lad_code, $fy_from] : [$lad_code]);
$payments = $pay_stmt->fetch(PDO::FETCH_ASSOC);

// Monthly trend
$trend_stmt = $pdo->prepare("
    SELECT period, SUM(amount) as total
    FROM ct_spend_display WHERE lad_code = ? AND internal_provider = 0 AND (validation_status IS NULL OR validation_status != 'invalid')$date_clause
    GROUP BY period ORDER BY period
");
$trend_stmt->execute($fy_from ? [$lad_code, $fy_from] : [$lad_code]);
$trend = [];
foreach ($trend_stmt->fetchAll() as $r) $trend[$r['period']] = (float)$r['total'];

// ── Contracts (Contracts Finder / FTS, tech CPV only) ────────────────────
$con_stmt = $pdo->prepare("
    SELECT COUNT(*) as cnt, SUM(value_amount) as total_value,
           MIN(published_date) as earliest_notice, MAX(published_date) as latest_notice
    FROM ct_contracts WHERE buyer_lad_code = ? AND cpv_division IN ('30','32','48','64','72','73')
");
$con_stmt->execute([$lad_code]);
$con_data = $con_stmt->fetch(PDO::FETCH_ASSOC);
$contracts_count = (int)($con_data['cnt'] ?? 0);
$contracts_total = (float)($con_data['total_value'] ?? 0);
$contracts_earliest = $con_data['earliest_notice'] ?? null;
$contracts_latest = $con_data['latest_notice'] ?? null;

// ── Contract Register (tech only) ───────────────────────────────────────
$reg_stmt = $pdo->prepare("SELECT COUNT(*) as cnt, SUM(value_amount) as total_value, MIN(start_date) as earliest_start, MAX(end_date) as latest_end FROM ct_contract_register_entries WHERE council = ? AND is_tech = 1");
$reg_stmt->execute([$council_name]);
$reg_data = $reg_stmt->fetch(PDO::FETCH_ASSOC);
$reg_count = (int)($reg_data['cnt'] ?? 0);
$reg_value = (float)($reg_data['total_value'] ?? 0);
$reg_earliest = $reg_data['earliest_start'] ?? null;
$reg_latest = $reg_data['latest_end'] ?? null;

// ── Unified per-supplier view ─────────────────────────────────────────────
$uni_stmt = $pdo->prepare("
    SELECT
      COALESCE(s.supplier_canon, r.supplier_canon) AS supplier,
      COALESCE(s.total_pay, 0) AS total_pay,
      s.first_pay,
      s.last_pay,
      COALESCE(r.total_contract, 0) AS total_contract,
      r.first_start,
      r.last_end
    FROM (
      SELECT supplier_canon, SUM(amount) AS total_pay,
             MIN(paid_date) AS first_pay, MAX(paid_date) AS last_pay
      FROM ct_spend_display WHERE lad_code = ? AND internal_provider = 0 AND (validation_status IS NULL OR validation_status != 'invalid')" . $date_clause . "
      GROUP BY supplier_canon
    ) s
    LEFT JOIN (
      SELECT supplier_canon, SUM(value_amount) AS total_contract,
             MIN(start_date) AS first_start, MAX(end_date) AS last_end
      FROM ct_contract_register_entries WHERE council = ? AND is_tech = 1
      GROUP BY supplier_canon
    ) r ON r.supplier_canon = s.supplier_canon

    ORDER BY total_pay DESC, total_contract DESC
");
$uni_stmt->execute($fy_from ? [$lad_code, $fy_from, $council_name] : [$lad_code, $council_name]);
$unified_suppliers = $uni_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper: format date as Mon YYYY for card display
function fmt_date_short(?string $d): string {
    if (!$d) return "";
    $ts = strtotime($d);
    return $ts ? date("M Y", $ts) : "";
}

// Helper: format date range concisely
function fmt_date_range(?string $from, ?string $to): string {
    if (!$from && !$to) return '—';
    $f = $from ? fmt_date_short($from) : '?';
    $t = $to   ? fmt_date_short($to)   : '?';
    return $f === $t ? $f : $f . ' – ' . $t;
}

// ── Render ───────────────────────────────────────────────────────────────
layout_head($council_name . ' – Council');
?>

<div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:16px">
  <h1 class="govuk-heading-xl govuk-!-margin-bottom-2"><?= htmlspecialchars($council_name) ?></h1>
  <a href="/contracts/council.php" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0">&larr; All councils</a>
</div>
<p class="govuk-body-s" style="color:#505a5f;margin-bottom:24px">
  <?= htmlspecialchars($council_cfg['council_type']) ?>
  · <?= htmlspecialchars($council_cfg['region']) ?>
  <?php if (!empty($council_cfg['combined_authority']) && strpos($council_cfg['combined_authority'], 'No current ') !== 0): ?>
    · <?= htmlspecialchars($council_cfg['combined_authority']) ?>
  <?php endif; ?>
  · <?= htmlspecialchars($lad_code) ?>
  <?php if ($council_cfg['population']): ?>
    · Pop. <?= number_format($council_cfg['population']) ?>
  <?php endif; ?>
  <?php if (!empty($council_cfg['imd_rank'])): ?>
    · IMD rank <?= number_format($council_cfg['imd_rank']) ?>/317<?php if ($council_cfg['imd_year']): ?> (<?= (int)$council_cfg['imd_year'] ?>)<?php endif; ?>
  <?php endif; ?>
  <?php /* Net budget chip disabled: imported net_revenue_exp is net-of-financing,
           misleadingly small for shire districts. Re-enable with gross/total
           service expenditure. See ct_la_reference. */ ?>
  <?php if (false && !empty($council_cfg['net_revenue_exp']) && (float)$council_cfg['net_revenue_exp'] > 0): // £ thousands ?>
    · Net budget £<?= number_format((float)$council_cfg['net_revenue_exp'] / 1000, 0) ?>m<?php if ($council_cfg['budget_year']): ?> (<?= htmlspecialchars((string)$council_cfg['budget_year']) ?>)<?php endif; ?>
  <?php endif; ?>
  <?php if ($council_cfg['listing_url']): ?>
    · <a class="govuk-link" href="<?= htmlspecialchars($council_cfg['listing_url']) ?>" target="_blank" rel="noopener">Published data →</a>
  <?php endif; ?>
</p>

<!-- Summary cards with view links -->
<style>
  .ct-stat-card--active { border-left:4px solid #1d70b8; background:#f0f4f8; }
  .ct-cards-row { display:flex; flex-wrap:wrap; gap:0; margin-bottom:30px; }
  .ct-cards-row .govuk-grid-column-one-third { display:flex; min-width:200px; }
  .ct-cards-row .ct-stat-card { flex:1; display:flex; flex-direction:column; }
</style>
<div class="govuk-grid-row ct-cards-row">
  <div class="govuk-grid-column-one-third">
    <div class="ct-stat-card<?= $view==='payments'?' ct-stat-card--active':'' ?>" style="cursor:default">
      <a href="<?= $base_url ?>&view=payments" style="display:block;text-decoration:none;color:inherit">
        <div class="ct-stat-card__number"><?= fmt_value((float)$payments['total_spend']) ?></div>
        <div class="ct-stat-card__label">Tech payments</div>
        <div class="ct-stat-card__sub"><?= number_format((int)$payments['payment_count']) ?> transactions · <?= (int)$payments['distinct_suppliers'] ?> suppliers</div>
      </a>
      <form method="get" style="margin-top:12px">
        <input type="hidden" name="id" value="<?= htmlspecialchars($lad_code) ?>">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
        <select class="govuk-select" name="fy" onchange="this.form.submit()" style="font-size:0.875rem;height:32px;padding:2px 8px;width:100%">
          <?php foreach ($fy_options_c as $val => $label): ?>
            <option value="<?= $val ?>"<?= (string)$val === $c_fy ? ' selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>
  <div class="govuk-grid-column-one-third">
    <a href="<?= $base_url ?>&view=contracts" class="ct-stat-card<?= $view==='contracts'?' ct-stat-card--active':'' ?>" style="display:flex;flex-direction:column;text-decoration:none;color:inherit">
      <div class="ct-stat-card__number"><?= $reg_value ? fmt_value($reg_value) : number_format($reg_count) ?></div>
      <div class="ct-stat-card__label">Contracts</div>
      <div class="ct-stat-card__sub"><?= number_format($reg_count) ?> tech contracts</div>
      <?php if ($reg_earliest && $reg_latest): ?><div style="margin-top:6px;font-size:0.875rem"><?= fmt_date_short($reg_earliest) ?> – <?= fmt_date_short($reg_latest) ?></div><?php else: ?><div style="margin-top:6px;font-size:0.875rem">&nbsp;</div><?php endif; ?>
    </a>
  </div>
  <div class="govuk-grid-column-one-third">
    <a href="<?= $base_url ?>&view=notices" class="ct-stat-card<?= $view==='notices'?' ct-stat-card--active':'' ?>" style="display:flex;flex-direction:column;text-decoration:none;color:inherit">
      <div class="ct-stat-card__number"><?= fmt_value($contracts_total) ?></div>
      <div class="ct-stat-card__label">Contract notices</div>
      <div class="ct-stat-card__sub"><?= number_format($contracts_count) ?> tech notices on CF/FTS</div>
      <?php if ($contracts_earliest && $contracts_latest): ?><div style="margin-top:6px;font-size:0.875rem"><?= fmt_date_short($contracts_earliest) ?> – <?= fmt_date_short($contracts_latest) ?></div><?php else: ?><div style="margin-top:6px;font-size:0.875rem">&nbsp;</div><?php endif; ?>
    </a>
  </div>
</div>

<?php if ($view === 'payments'): ?>
<!-- Monthly spend trend -->
<?php if ($trend): ?>
<h2 class="govuk-heading-m">Monthly tech spend</h2>
<?php
$tmax = max($trend);
$y_top = $tmax >= 1e6 ? '£' . number_format($tmax / 1e6, 1) . 'M' : ($tmax >= 1e3 ? '£' . number_format($tmax / 1e3, 0) . 'k' : '£' . number_format($tmax, 0));
$y_mid_val = $tmax / 2;
$y_mid = $y_mid_val >= 1e6 ? '£' . number_format($y_mid_val / 1e6, 1) . 'M' : ($y_mid_val >= 1e3 ? '£' . number_format($y_mid_val / 1e3, 0) . 'k' : '£' . number_format($y_mid_val, 0));
?>
<div style="position:relative;margin-bottom:32px">
  <div style="display:flex;gap:0">
    <div style="width:50px;height:100px;display:flex;flex-direction:column;justify-content:space-between;font-size:0.65rem;color:#505a5f;text-align:right;padding-right:6px">
      <span><?= $y_top ?></span>
      <span><?= $y_mid ?></span>
      <span>£0</span>
    </div>
    <div style="flex:1;display:flex;align-items:flex-end;gap:2px;height:100px;background:#f3f2f1;padding:8px 8px 0;border-radius:4px">
      <?php foreach ($trend as $p => $v): ?>
      <div style="flex:1;background:#1d70b8;min-width:3px;height:<?= $tmax ? round(100 * $v / $tmax) : 0 ?>%" title="<?= $p ?>: £<?= number_format($v, 0) ?>"></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div style="display:flex;gap:2px;padding:4px 0 0 50px;font-size:0.7rem;color:#505a5f">
    <?php
    $last_year = '';
    $mo_labels = ['01'=>'Jan','04'=>'Apr','07'=>'Jul','10'=>'Oct'];
    foreach ($trend as $p => $v):
        $yr = substr($p,0,4); $mo = substr($p,5,2);
        if ($mo === '04') { $lbl = $yr; }
        elseif (isset($mo_labels[$mo])) { $lbl = $mo_labels[$mo]; }
        else { $lbl = ''; }
    ?>
    <div style="flex:1;text-align:center;min-width:3px;overflow:hidden;font-size:0.6rem"><?= $lbl ?></div>
    <?php $last_year = $yr; endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Unified supplier table -->
<?php if ($unified_suppliers): ?>
<?php
$type_overrides = [
    'Dell' => 'Hardware', 'Xerox' => 'Hardware', 'HPE' => 'Hardware',
    'Capita' => 'Outsourcer', 'Serco' => 'Outsourcer', 'Agilisys' => 'Outsourcer', 'Liberata' => 'Outsourcer',
    'BT' => 'Telecoms', 'Virgin Media Business' => 'Telecoms', 'Vodafone' => 'Telecoms', 'EE' => 'Telecoms',
    'Telefonica O2' => 'Telecoms', 'Openreach' => 'Telecoms', 'MLL Telecom' => 'Telecoms', 'Gamma' => 'Telecoms',
    'Phoenix Software' => 'Reseller', 'Softcat' => 'Reseller', 'Bytes' => 'Reseller', 'CDW' => 'Reseller',
    'XMA' => 'Reseller', 'Insight Direct' => 'Reseller', 'Trustmarque' => 'Reseller', 'Boxxe' => 'Reseller',
    'CCS Media' => 'Reseller', 'Probrand' => 'Reseller', 'SCC' => 'Reseller', 'Computacenter' => 'Reseller',
    'European Electronique' => 'Reseller', 'Jigsaw24' => 'Reseller',
    'Oracle' => 'Software', 'Microsoft' => 'Software', 'SAP' => 'Software',
    'NEC Software Solutions' => 'Software', 'Idox Software' => 'Software', 'Civica UK' => 'Software',
    'Liquidlogic' => 'Software', 'The Access Group' => 'Software', 'Unit4' => 'Software',
    'MRI Community Software' => 'Software', 'Granicus-Firmstep' => 'Software', 'Brightly Software' => 'Software',
    'OLM Systems' => 'Software', 'MHR' => 'Software', 'Fujitsu' => 'Managed services',
    'Amazon Web Services' => 'Cloud', 'Learning Pool' => 'Software',
];
$sic_labels_c = ['62'=>'IT services','63'=>'Data services','61'=>'Telecoms','58'=>'Software','26'=>'Hardware','46'=>'Reseller','47'=>'Reseller','70'=>'Consultancy'];
// Pre-fetch SIC codes for suppliers in this council
$sup_names = array_column($unified_suppliers, 'supplier');
$sic_map = [];
if ($sup_names) {
    $in = implode(',', array_map(fn($s) => $pdo->quote($s), $sup_names));
    $cn_map = [];
    foreach ($pdo->query("SELECT canonical_name, sic_codes, company_number FROM ct_suppliers WHERE canonical_name IN ($in)")->fetchAll() as $row) {
        $cn_map[$row["canonical_name"]] = $row["company_number"];
        $sic_map[$row['canonical_name']] = $row['sic_codes'];
    }
}
?>
<h2 class="govuk-heading-m">Suppliers</h2>
<div style="overflow-x:auto">
<table class="govuk-table">
  <thead class="govuk-table__head">
    <tr>
      <th class="govuk-table__header">Supplier</th>
      <th class="govuk-table__header">Type</th>
      <th class="govuk-table__header govuk-table__header--numeric">Total payments</th>
      <th class="govuk-table__header" style="white-space:nowrap">Payment dates</th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
    <?php foreach ($unified_suppliers as $u):
      $has_pay = (float)$u['total_pay'] > 0;
      $sup_type = $type_overrides[$u['supplier']] ?? '';
      if (!$sup_type && isset($sic_map[$u['supplier']])) {
          $sic = explode(',', $sic_map[$u['supplier']])[0] ?? '';
          $sup_type = $sic_labels_c[substr($sic,0,2)] ?? '';
      }
    ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><?php $cn=$cn_map[$u["supplier"]] ?? null; if($cn): ?><a class="govuk-link" href="/contracts/supplier.php?id=<?= htmlspecialchars($cn) ?><?= $c_fy ? '&fy=' . $c_fy : '' ?>"><?= htmlspecialchars($u["supplier"]) ?></a><?php else: ?><?= htmlspecialchars($u["supplier"]) ?><?php endif; ?></td>
      <td class="govuk-table__cell" style="font-size:0.8rem;color:#505a5f"><?= htmlspecialchars($sup_type) ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric">
        <?= $has_pay ? fmt_value((float)$u['total_pay']) : '<span style="color:#505a5f">—</span>' ?>
      </td>
      <td class="govuk-table__cell" style="font-size:0.8rem;color:#505a5f;white-space:nowrap">
        <?= $has_pay ? htmlspecialchars(fmt_date_range($u['first_pay'], $u['last_pay'])) : '—' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; // end view=payments ?>

<?php if ($view === 'contracts'): ?>
<!-- Contract register entries -->
<?php
$reg_entries_stmt = $pdo->prepare("SELECT e.supplier, e.supplier_canon, e.contract_title, e.value_amount, e.start_date, e.end_date, e.department, s.company_number FROM ct_contract_register_entries e LEFT JOIN ct_suppliers s ON s.canonical_name = e.supplier_canon WHERE e.council = ? AND e.is_tech = 1 ORDER BY e.value_amount DESC");
$reg_entries_stmt->execute([$council_name]);
$reg_entries = $reg_entries_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php if ($reg_entries): ?>
<h2 class="govuk-heading-m">Tech contracts (<?= count($reg_entries) ?>)</h2>
<table class="govuk-table" style="font-size:0.875rem">
  <thead class="govuk-table__head"><tr>
    <th class="govuk-table__header">Supplier</th>
    <th class="govuk-table__header">Contract</th>
    <th class="govuk-table__header govuk-table__header--numeric">Value</th>
    <th class="govuk-table__header">Start</th>
    <th class="govuk-table__header">End</th>
  </tr></thead>
  <tbody class="govuk-table__body">
  <?php foreach ($reg_entries as $r): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell" style="font-size:1rem"><?php $sname = htmlspecialchars($r['supplier_canon'] ?: $r['supplier']); $fy_qs = $c_fy ? '&fy=' . $c_fy : '';
        echo $r['company_number'] ? "<a class=\"govuk-link\" href=\"/contracts/supplier.php?id={$r['company_number']}{$fy_qs}\">$sname</a>" : "<strong>$sname</strong>"; ?></td>
      <td class="govuk-table__cell" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['contract_title'] ?? '') ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= $r['value_amount'] ? '£' . number_format((float)$r['value_amount'], 0) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8rem"><?= $r['start_date'] ? date('M Y', strtotime($r['start_date'])) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8rem"><?= $r['end_date'] ? date('M Y', strtotime($r['end_date'])) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
  <p class="govuk-body">No tech contracts found in the contract register for this council.</p>
<?php endif; ?>
<?php endif; // end view=contracts ?>

<?php if ($view === 'notices'): ?>
<!-- Contract notices (Contracts Finder / FTS) -->
<?php
$notices_stmt = $pdo->prepare("SELECT title, supplier_names, value_amount, published_date, source, stage FROM ct_contracts WHERE buyer_lad_code = ? AND cpv_division IN ('30','32','48','64','72','73') ORDER BY published_date DESC LIMIT 100");
$notices_stmt->execute([$lad_code]);
$notices = $notices_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php if ($notices): ?>
<h2 class="govuk-heading-m">Contract notices (<?= count($notices) ?>)</h2>
<table class="govuk-table" style="font-size:0.875rem">
  <thead class="govuk-table__head"><tr>
    <th class="govuk-table__header">Title</th>
    <th class="govuk-table__header">Supplier</th>
    <th class="govuk-table__header govuk-table__header--numeric">Value</th>
    <th class="govuk-table__header">Date</th>
    <th class="govuk-table__header">Source</th>
  </tr></thead>
  <tbody class="govuk-table__body">
  <?php foreach ($notices as $n): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(mb_substr($n['title'] ?? '', 0, 60)) ?></td>
      <td class="govuk-table__cell" style="font-size:0.8rem"><?= htmlspecialchars(mb_substr($n['supplier_names'] ?? '', 0, 40)) ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= $n['value_amount'] ? '£' . number_format((float)$n['value_amount'], 0) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8rem"><?= $n['published_date'] ?? '—' ?></td>
      <td class="govuk-table__cell"><span class="govuk-tag govuk-tag--grey" style="font-size:0.7rem"><?= htmlspecialchars($n['source']) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
  <p class="govuk-body">No tech contract notices found on Contracts Finder or Find a Tender for this council.</p>
<?php endif; ?>
<?php endif; // end view=notices ?>

<?php
layout_foot(true);
