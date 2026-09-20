<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    layout_head('Contract spend');
    echo '<p class="govuk-body">No contract ID supplied.</p>';
    layout_foot(false);
    exit;
}

// Load contract + council lad_code in one query
$stmt = $pdo->prepare("
    SELECT e.id, e.council, cc.lad_code, e.supplier, e.supplier_canon,
           e.contract_title, e.contract_ref, e.value_amount,
           e.start_date, e.end_date, e.department, e.tender_process, e.is_tech
    FROM ct_contract_register_entries e
    LEFT JOIN ct_council_config cc ON cc.council = e.council
    WHERE e.id = ?
");
$stmt->execute([$id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    http_response_code(404);
    layout_head('Contract spend');
    echo '<p class="govuk-body">Contract not found.</p>';
    layout_foot(false);
    exit;
}

$lad_code   = $contract['lad_code'];
$canon      = $contract['supplier_canon'];
$start_date = $contract['start_date'];
$end_date   = $contract['end_date'];

// Determine query window
$window_start = $start_date; // null = no lower bound
$window_end   = $end_date;   // null = no upper bound (ongoing)

// Actual spend within contract window
$actual_spend = null;
$months       = [];
$after_count  = 0;
$after_total  = 0.0;

if ($lad_code && $canon) {
    $spend_params = [$lad_code, $canon];
    $start_cond = $window_start ? 'AND t.paid_date >= ?' : '';
    $end_cond   = $window_end   ? 'AND t.paid_date <= ?' : '';
    if ($window_start) $spend_params[] = $window_start;
    if ($window_end)   $spend_params[] = $window_end;

    $total_stmt = $pdo->prepare("
        SELECT SUM(t.amount) AS actual_spend
        FROM ct_transparency_spend t
        WHERE t.lad_code = ?
          AND t.supplier_canon = ?
          AND t.internal_provider = 0
          AND (t.validation_status IS NULL OR t.validation_status != 'invalid')
          $start_cond
          $end_cond
    ");
    $total_stmt->execute($spend_params);
    $actual_spend = $total_stmt->fetchColumn();
    $actual_spend = $actual_spend !== null ? (float)$actual_spend : null;

    // Monthly breakdown
    $monthly_stmt = $pdo->prepare("
        SELECT DATE_FORMAT(t.paid_date, '%Y-%m') AS ym,
               SUM(t.amount) AS monthly_total,
               COUNT(*) AS row_count
        FROM ct_transparency_spend t
        WHERE t.lad_code = ?
          AND t.supplier_canon = ?
          AND t.internal_provider = 0
          AND (t.validation_status IS NULL OR t.validation_status != 'invalid')
          $start_cond
          $end_cond
        GROUP BY DATE_FORMAT(t.paid_date, '%Y-%m')
        ORDER BY ym
    ");
    $monthly_stmt->execute($spend_params);
    $months = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Payments after end date (zombie check)
    if ($window_end) {
        $after_stmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(t.amount), 0) AS after_total
            FROM ct_transparency_spend t
            WHERE t.lad_code = ?
              AND t.supplier_canon = ?
              AND t.internal_provider = 0
              AND t.paid_date > ?
        ");
        $after_stmt->execute([$lad_code, $canon, $window_end]);
        $ar = $after_stmt->fetch(PDO::FETCH_ASSOC);
        $after_count = (int)$ar['cnt'];
        $after_total = (float)$ar['after_total'];
    }
}

// Overlapping contracts check (same supplier_canon + council, overlapping dates)
$overlap_count = 0;
if ($canon) {
    $ov_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM ct_contract_register_entries e2
        WHERE e2.council = ?
          AND e2.supplier_canon = ?
          AND e2.id != ?
          AND e2.is_tech = 1
          AND (e2.start_date IS NULL OR ? IS NULL OR e2.start_date <= ?)
          AND (e2.end_date   IS NULL OR ? IS NULL OR e2.end_date   >= ?)
    ");
    $ov_stmt->execute([
        $contract['council'], $canon, $id,
        $window_end,   $window_end,
        $window_start, $window_start,
    ]);
    $overlap_count = (int)$ov_stmt->fetchColumn();
}

function fmt_val(?float $v): string {
    if ($v === null) return '—';
    if ($v >= 1e6) return '£' . number_format($v / 1e6, 2) . 'm';
    if ($v >= 1e3) return '£' . number_format($v / 1e3, 1) . 'k';
    return '£' . number_format($v, 2);
}

$contracted = $contract['value_amount'] !== null ? (float)$contract['value_amount'] : null;
$is_overrun = $contracted !== null && $actual_spend !== null && $actual_spend > $contracted;
$pct = ($contracted && $actual_spend !== null) ? round($actual_spend / $contracted * 100) : null;

$title = $contract['contract_title'] ?: 'Unnamed contract';
layout_head('Contract spend: ' . $title);
?>

<style>
.cs-header { background:#f3f2f1; padding:20px 24px; margin-bottom:24px; border-left:5px solid #1d70b8; }
.cs-header h1 { font-size:1.25rem; font-weight:700; margin:0 0 6px; }
.cs-meta { font-size:0.8rem; color:#505a5f; display:flex; gap:1.5rem; flex-wrap:wrap; margin-top:8px; }
.cs-meta span { display:inline-block; }
.cs-totals { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:24px; }
.cs-total { flex:1 1 160px; background:#fff; border:1px solid #b1b4b6; padding:14px 16px; }
.cs-total__num { font-size:1.4rem; font-weight:700; color:#1d70b8; }
.cs-total__label { font-size:0.75rem; color:#505a5f; margin-top:3px; }
.cs-total--warn .cs-total__num { color:#d4351c; }
.cs-total--zombie .cs-total__num { color:#f47738; }
.cs-flag { display:inline-block; font-size:0.75rem; padding:2px 8px; border-radius:2px; font-weight:600; margin-left:6px; }
.cs-flag--overrun { background:#fce9e7; color:#d4351c; }
.cs-flag--zombie  { background:#fff4e6; color:#f47738; }
.cs-flag--overlap { background:#e8f0fe; color:#1d70b8; }
.cs-month-bar { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
.cs-month-bar__label { font-size:0.8rem; color:#505a5f; min-width:56px; }
.cs-month-bar__track { flex:1; background:#f3f2f1; height:14px; border-radius:2px; overflow:hidden; }
.cs-month-bar__fill { height:100%; background:#1d70b8; border-radius:2px; transition:width 0.2s; }
.cs-month-bar__amt { font-size:0.8rem; font-weight:600; min-width:70px; text-align:right; }
.cs-no-data { padding:20px; background:#f3f2f1; color:#505a5f; font-size:0.875rem; }
.cs-back { font-size:0.875rem; margin-bottom:16px; display:block; }
</style>

<a href="javascript:history.back()" class="govuk-link cs-back">← Back</a>

<div class="cs-header">
  <h1><?= htmlspecialchars($title) ?>
    <?php if ($is_overrun): ?><span class="cs-flag cs-flag--overrun">Overrun</span><?php endif; ?>
    <?php if ($after_count > 0): ?><span class="cs-flag cs-flag--zombie">Payments after end</span><?php endif; ?>
    <?php if ($overlap_count > 0): ?><span class="cs-flag cs-flag--overlap">Overlapping contracts</span><?php endif; ?>
  </h1>
  <div class="cs-meta">
    <span><strong>Council:</strong> <a href="/contracts/council.php?id=<?= urlencode($lad_code ?? '') ?>" class="govuk-link"><?= htmlspecialchars($contract['council']) ?></a></span>
    <span><strong>Supplier:</strong> <a href="/contracts/supplier.php?supplier=<?= urlencode($canon ?? $contract['supplier']) ?>" class="govuk-link"><?= htmlspecialchars($contract['supplier']) ?></a></span>
    <?php if ($contract['department']): ?><span><strong>Department:</strong> <?= htmlspecialchars($contract['department']) ?></span><?php endif; ?>
    <?php if ($contract['tender_process']): ?><span><strong>Tender:</strong> <?= htmlspecialchars($contract['tender_process']) ?></span><?php endif; ?>
    <?php if ($contract['contract_ref']): ?><span><strong>Ref:</strong> <?= htmlspecialchars($contract['contract_ref']) ?></span><?php endif; ?>
  </div>
</div>

<!-- Totals row -->
<div class="cs-totals">
  <div class="cs-total">
    <div class="cs-total__num"><?= fmt_val($contracted) ?></div>
    <div class="cs-total__label">Contract value</div>
  </div>
  <div class="cs-total">
    <div class="cs-total__num"><?= $start_date ? date('M Y', strtotime($start_date)) : '—' ?></div>
    <div class="cs-total__label">Start date</div>
  </div>
  <div class="cs-total">
    <div class="cs-total__num"><?= $end_date ? date('M Y', strtotime($end_date)) : 'Ongoing' ?></div>
    <div class="cs-total__label">End date</div>
  </div>

  <?php if (!$lad_code): ?>
  <div class="cs-total">
    <div class="cs-total__num">—</div>
    <div class="cs-total__label">No spend data (council not in database)</div>
  </div>
  <?php elseif (!$canon): ?>
  <div class="cs-total">
    <div class="cs-total__num">—</div>
    <div class="cs-total__label">No spend data (supplier not matched)</div>
  </div>
  <?php elseif ($actual_spend === null || $actual_spend == 0.0): ?>
  <div class="cs-total">
    <div class="cs-total__num">—</div>
    <div class="cs-total__label">No matching spend payments found</div>
  </div>
  <?php else: ?>
  <div class="cs-total<?= $is_overrun ? ' cs-total--warn' : '' ?>">
    <div class="cs-total__num"><?= fmt_val($actual_spend) ?><?= $is_overrun ? ' ▲' : '' ?></div>
    <div class="cs-total__label">Actual spend during period<?= $pct !== null ? " ($pct% of contract)" : '' ?></div>
  </div>
  <?php endif; ?>

  <?php if ($after_count > 0): ?>
  <div class="cs-total cs-total--zombie">
    <div class="cs-total__num"><?= fmt_val($after_total) ?></div>
    <div class="cs-total__label"><?= $after_count ?> payment<?= $after_count != 1 ? 's' : '' ?> recorded after contract end</div>
  </div>
  <?php endif; ?>
</div>

<?php if ($overlap_count > 0): ?>
<div class="govuk-inset-text" style="margin-bottom:20px;font-size:0.875rem">
  <strong>Note:</strong> This council has <?= $overlap_count ?> other overlapping contract<?= $overlap_count != 1 ? 's' : '' ?> with the same supplier during this period. Spend figures above cover all payments to <?= htmlspecialchars($canon) ?> in the date window and cannot be attributed to individual contracts.
</div>
<?php endif; ?>

<?php if ($months): ?>
<h2 class="govuk-heading-s" style="margin-bottom:12px">Monthly payments during contract period</h2>
<?php
$max_month = max(array_column($months, 'monthly_total'));
foreach ($months as $m):
    $pct_bar = $max_month > 0 ? round($m['monthly_total'] / $max_month * 100) : 0;
    $ym_parts = explode('-', $m['ym']);
    $label = date('M y', mktime(0,0,0,(int)$ym_parts[1],1,(int)$ym_parts[0]));
?>
<div class="cs-month-bar">
  <span class="cs-month-bar__label"><?= $label ?></span>
  <div class="cs-month-bar__track"><div class="cs-month-bar__fill" style="width:<?= $pct_bar ?>%"></div></div>
  <span class="cs-month-bar__amt"><?= fmt_val((float)$m['monthly_total']) ?></span>
</div>
<?php endforeach; ?>
<?php elseif ($lad_code && $canon): ?>
<div class="cs-no-data">No matching spend payments found for <?= htmlspecialchars($canon) ?> at <?= htmlspecialchars($contract['council']) ?><?= $start_date ? ' from ' . date('M Y', strtotime($start_date)) : '' ?><?= $end_date ? ' to ' . date('M Y', strtotime($end_date)) : '' ?>.</div>
<?php endif; ?>

<?php if ($after_count > 0): ?>
<h2 class="govuk-heading-s" style="margin-top:24px;margin-bottom:12px">Payments after contract end (<?= date('M Y', strtotime($end_date)) ?>)</h2>
<?php
$after_monthly = $pdo->prepare("
    SELECT DATE_FORMAT(t.paid_date, '%Y-%m') AS ym,
           SUM(t.amount) AS monthly_total,
           COUNT(*) AS row_count
    FROM ct_transparency_spend t
    WHERE t.lad_code = ?
      AND t.supplier_canon = ?
      AND t.internal_provider = 0
      AND t.paid_date > ?
    GROUP BY DATE_FORMAT(t.paid_date, '%Y-%m')
    ORDER BY ym
    LIMIT 24
");
$after_monthly->execute([$lad_code, $canon, $window_end]);
$after_months = $after_monthly->fetchAll(PDO::FETCH_ASSOC);
$max_after = $after_months ? max(array_column($after_months, 'monthly_total')) : 1;
foreach ($after_months as $m):
    $pct_bar = $max_after > 0 ? round($m['monthly_total'] / $max_after * 100) : 0;
    $ym_parts = explode('-', $m['ym']);
    $label = date('M y', mktime(0,0,0,(int)$ym_parts[1],1,(int)$ym_parts[0]));
?>
<div class="cs-month-bar">
  <span class="cs-month-bar__label"><?= $label ?></span>
  <div class="cs-month-bar__track"><div class="cs-month-bar__fill" style="width:<?= $pct_bar ?>%;background:#f47738"></div></div>
  <span class="cs-month-bar__amt" style="color:#f47738"><?= fmt_val((float)$m['monthly_total']) ?></span>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php layout_foot(false); ?>
