<?php
/**
 * analysis.php — Analysis: tech spend vs deprivation (IMD).
 *
 * Public (no login). Plots each English council as one dot on IMD average score (x)
 * against tech spend per resident (y), plus a sortable table view. English lower-tier + unitary councils only (IMD is the
 * English IoD2019 index; devolved nations and upper-tier counties have no rank).
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_layout.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// ── Nation facet ───────────────────────────────────────────────────────────
// Deprivation indices are NOT comparable across nations (England IoD2019 uses an
// average score; Scotland SIMD / Wales WIMD use % of small areas in the most-
// deprived national quintile). So each nation is plotted on its OWN axis, never
// shared. $nation drives: lad_code prefix, which deprivation column is the x
// value (imd_x), and the axis label.
$nations = [
    'E' => [
        'label'    => 'England',
        'col'      => 'r.imd_avg_score',
        'axis'     => 'IMD average score (IoD2019, higher = more deprived) →',
        'source'   => 'IoD2019',
        'x_round'  => 5,      // axis tick rounding
        'x_dp'     => 1,      // decimals in tooltip
    ],
    'S' => [
        'label'    => 'Scotland',
        'col'      => 'r.imd_pct_q1',
        'axis'     => '% of datazones in most-deprived national quintile (SIMD 2020) →',
        'source'   => 'SIMD2020',
        'x_round'  => 10,
        'x_dp'     => 1,
    ],
    'W' => [
        'label'    => 'Wales',
        'col'      => 'r.imd_pct_q1',
        'axis'     => '% of LSOAs in most-deprived national quintile (WIMD 2019) →',
        'source'   => 'WIMD2019',
        'x_round'  => 10,
        'x_dp'     => 1,
    ],
];
// Nation selector hidden for now — pinned to England. To re-enable the facet,
// restore: $nation = $_GET['nation'] ?? 'E'; and unhide the selector below.
$show_nation_selector = false;
$nation = $show_nation_selector ? ($_GET['nation'] ?? 'E') : 'E';
if (!array_key_exists($nation, $nations)) $nation = 'E';
$nat = $nations[$nation];
$imd_col = $nat['col'];

// ── FY filter ──────────────────────────────────────────────────────────────
// 'all' uses the all-time ct_council_summary; a year uses ct_council_summary_fy.
$fy_options = [
    'all'  => 'All time',
    '2025' => '2025/26',
    '2024' => '2024/25',
    '2023' => '2023/24',
    '2022' => '2022/23',
];
$fy = $_GET['fy'] ?? 'all';
if (!array_key_exists($fy, $fy_options)) $fy = 'all';

// One row per council in the selected nation with a deprivation value and >0 tech
// spend in the selected scope. Spend per head = total_spend / population.
// City of London (E09000001) excluded — tiny resident pop skews per-head.
$imd_where = "$imd_col IS NOT NULL AND LEFT(cc.lad_code,1) = :natp AND cc.lad_code <> 'E09000001'";
if ($fy === 'all') {
    $sql = "
        SELECT cc.council, cc.lad_code, cc.council_type, cc.region, cc.population,
               $imd_col AS imd_x, r.imd_year,
               cs.total_spend,
               cs.total_spend / cc.population AS spend_per_head
        FROM ct_council_config cc
        JOIN ct_la_reference r  ON r.lad_code = cc.lad_code
        JOIN ct_council_summary cs ON cs.lad_code = cc.lad_code
        WHERE cc.population > 0 AND cs.total_spend > 0 AND $imd_where
        ORDER BY spend_per_head DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':natp' => $nation]);
} else {
    $sql = "
        SELECT cc.council, cc.lad_code, cc.council_type, cc.region, cc.population,
               $imd_col AS imd_x, r.imd_year,
               csf.total_spend,
               csf.total_spend / cc.population AS spend_per_head
        FROM ct_council_config cc
        JOIN ct_la_reference r  ON r.lad_code = cc.lad_code
        JOIN ct_council_summary_fy csf ON csf.lad_code = cc.lad_code AND csf.fy_year = :fy
        WHERE cc.population > 0 AND csf.total_spend > 0 AND $imd_where
        ORDER BY spend_per_head DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':natp' => $nation, ':fy' => (int)$fy]);
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$fy_label = $fy_options[$fy];

$n = count($rows);
$imd_year = $rows[0]['imd_year'] ?? $nat['source'];

// ── Councils WITH spend but NO deprivation value (table only, not the scatter)
// Mostly county councils — IoD2019 has no county-level score. Shown in the table
// with spend-per-head so their spend isn't invisible, but excluded from the
// scatter and the correlation (no x-value). LEFT JOIN + IMD IS NULL is the
// complement of the $rows query above; same nation / FY / City-of-London rules.
$noimd_join  = $fy === 'all'
    ? "JOIN ct_council_summary cs ON cs.lad_code = cc.lad_code"
    : "JOIN ct_council_summary_fy cs ON cs.lad_code = cc.lad_code AND cs.fy_year = :fy";
$noimd_sql = "
    SELECT cc.council, cc.lad_code, cc.council_type, cc.region, cc.population,
           cs.total_spend,
           cs.total_spend / cc.population AS spend_per_head
    FROM ct_council_config cc
    LEFT JOIN ct_la_reference r ON r.lad_code = cc.lad_code
    $noimd_join
    WHERE cc.population > 0 AND cs.total_spend > 0
      AND LEFT(cc.lad_code,1) = :natp AND cc.lad_code <> 'E09000001'
      AND ($imd_col IS NULL)
    ORDER BY spend_per_head DESC";
$noimd_stmt = $pdo->prepare($noimd_sql);
$noimd_stmt->execute($fy === 'all' ? [':natp' => $nation] : [':natp' => $nation, ':fy' => (int)$fy]);
$noimd_rows = $noimd_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Aggregates for the summary + axis scaling ──────────────────────────────
$max_sph = 0.0; $max_imd = 0.0; $min_imd = 999.0;
$sum_sph = 0.0;
foreach ($rows as $row) {
    $sph = (float)$row['spend_per_head'];
    $imd = (float)$row['imd_x'];
    if ($sph > $max_sph) $max_sph = $sph;
    if ($imd > $max_imd) $max_imd = $imd;
    if ($imd < $min_imd) $min_imd = $imd;
    $sum_sph += $sph;
}
$avg_sph = $n ? $sum_sph / $n : 0;

// ── Pearson correlation: deprivation (nation's own metric) vs spend per head ─
$mx = 0.0; $my = 0.0;
foreach ($rows as $row) { $mx += (float)$row['imd_x']; $my += (float)$row['spend_per_head']; }
$mx = $n ? $mx / $n : 0; $my = $n ? $my / $n : 0;
$sxy = $sxx = $syy = 0.0;
foreach ($rows as $row) {
    $dx = (float)$row['imd_x'] - $mx;
    $dy = (float)$row['spend_per_head'] - $my;
    $sxy += $dx * $dy; $sxx += $dx * $dx; $syy += $dy * $dy;
}
$pearson = ($sxx > 0 && $syy > 0) ? $sxy / sqrt($sxx * $syy) : 0.0;

// ── SVG scatter geometry ───────────────────────────────────────────────────
$W = 900; $H = 460;
$padL = 64; $padR = 24; $padT = 24; $padB = 56;
$plotW = $W - $padL - $padR;
$plotH = $H - $padT - $padB;
// x: deprivation metric 0..nice(max) (rounding differs per nation). y: spend/head.
$xr = $nat['x_round'];
$x_max = max((float)$xr, ceil($max_imd / $xr) * $xr);
$y_max = max(10.0, ceil($max_sph / 50) * 50);
$px = fn(float $imd): float => $padL + ($imd / $x_max) * $plotW;
$py = fn(float $sph): float => $padT + $plotH - (min($sph, $y_max) / $y_max) * $plotH;

layout_head('Analysis – Spend vs deprivation', 'analysis.php');
?>
<style>
  .viz-root {
    --surface-1: #ffffff; --page: #ffffff;
    --text-primary: #0b0b0b; --text-secondary: #52514e; --muted: #898781;
    --grid: #e1e0d9; --baseline: #c3c2b7;
    --series-1: #2a78d6; --series-1-ring: rgba(11,11,11,0.10);
  }
  .viz-root { background: var(--surface-1); border: 1px solid var(--series-1-ring); border-radius: 6px; padding: 16px; }
  .viz-dot { fill: var(--series-1); stroke: var(--surface-1); stroke-width: 1.5; cursor: pointer; transition: r .08s; }
  .viz-dot:hover { r: 7; }
  .viz-axis-label { fill: var(--text-secondary); font-size: 13px; }
  .viz-tick { fill: var(--muted); font-size: 11px; }
  .viz-grid { stroke: var(--grid); stroke-width: 1; }
  .viz-baseline { stroke: var(--baseline); stroke-width: 1; }
  .viz-trend { stroke: var(--series-1); stroke-width: 2; stroke-dasharray: 5 4; opacity: .55; }
  .viz-tip { position: fixed; z-index: 50; pointer-events: none; background: #ffffff; color: #0b0b0b;
             border: 1px solid #b1b4b6; box-shadow: 0 2px 6px rgba(0,0,0,0.15);
             font-size: 12px; line-height: 1.35; padding: 6px 9px; border-radius: 4px; opacity: 0; transition: opacity .1s; max-width: 240px; }
  .analysis-tabs { display:flex; gap:0; border-bottom:1px solid var(--baseline); margin:24px 0 0; }
  .analysis-tabs button { background:none; border:none; border-bottom:4px solid transparent; padding:10px 16px; font-size:1rem; cursor:pointer; color:var(--text-secondary); }
  .analysis-tabs button[aria-selected="true"] { border-bottom-color: var(--series-1); color: var(--series-1); font-weight:700; }
  .ct-sort { background:none; border:none; padding:0; font:inherit; font-weight:700; color:inherit; cursor:pointer; display:inline-flex; align-items:center; gap:4px; }
  .ct-sort:hover { color: var(--series-1); }
  .ct-sort__arrow { font-size:.7rem; width:.7em; color: var(--muted); }
  .ct-sort[data-dir="asc"] .ct-sort__arrow::after { content:"\25B2"; color: var(--series-1); }
  .ct-sort[data-dir="desc"] .ct-sort__arrow::after { content:"\25BC"; color: var(--series-1); }
</style>

<div class="viz-root govuk-!-margin-bottom-0">
  <form method="get" style="display:flex;align-items:center;gap:10px;margin:0 0 12px;flex-wrap:wrap">
    <?php if ($show_nation_selector): ?>
    <label class="govuk-label" for="nation" style="margin:0;font-weight:700">Nation</label>
    <select class="govuk-select" id="nation" name="nation" onchange="this.form.submit()" style="height:36px;padding:2px 8px">
      <?php foreach ($nations as $code => $nn): ?>
        <option value="<?= $code ?>"<?= $code === $nation ? ' selected' : '' ?>><?= htmlspecialchars($nn['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label class="govuk-label" for="fy" style="margin:0<?= $show_nation_selector ? ' 0 0 8px' : '' ?>;font-weight:700">Financial year</label>
    <select class="govuk-select" id="fy" name="fy" onchange="this.form.submit()" style="height:36px;padding:2px 8px">
      <?php foreach ($fy_options as $val => $label): ?>
        <option value="<?= $val ?>"<?= (string)$val === $fy ? ' selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="govuk-button govuk-button--secondary" style="margin:0">Apply</button></noscript>
  </form>
  <p class="govuk-body-s" style="color:#505a5f;margin:0 0 12px">
    Each dot is one <?= htmlspecialchars($nat['label']) ?> council: deprivation (<?= htmlspecialchars($nat['source']) ?>) against
    tech spend per resident (<?= htmlspecialchars($fy_label) ?>). <?= number_format($n) ?> councils with both a deprivation figure and recorded spend.
    Correlation (Pearson <em>r</em>) = <strong><?= number_format($pearson, 2) ?></strong>.
  </p>
  <?php if ($nation !== 'E'): ?>
  <div class="govuk-inset-text" style="margin:0 0 12px;font-size:.85rem;color:#505a5f">
    <?= htmlspecialchars($nat['source']) ?> is <?= htmlspecialchars($nat['label']) ?>'s own deprivation index and is
    <strong>not comparable</strong> with England's IoD2019 or the other nations' indices — each ranks only its own councils.
    <?php if ($n < 8): ?> Coverage is thin (<?= (int)$n ?> councils with spend data), so read the trend with caution.<?php endif; ?>
  </div>
  <?php else: ?>
  <div class="govuk-inset-text" style="margin:0 0 12px;font-size:.85rem;color:#505a5f">
    County councils are absent from the scatter: the IoD2019 index is published at district/unitary level only, so
    there is no single deprivation score for a county (each spans many districts of differing deprivation). Their
    spend-per-head is listed in the table below (marked “—”).
  </div>
  <?php endif; ?>

  <div class="analysis-tabs" role="tablist">
    <button role="tab" aria-selected="true" aria-controls="tab-chart" id="btn-chart">Scatter</button>
    <button role="tab" aria-selected="false" aria-controls="tab-table" id="btn-table">Table</button>
  </div>

  <!-- ── Scatter ── -->
  <div id="tab-chart" role="tabpanel" aria-labelledby="btn-chart">
    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" width="100%" role="img"
         aria-label="Scatter plot of tech spend per resident against deprivation for <?= htmlspecialchars($nat['label']) ?> councils">
      <?php
        // Y gridlines + ticks
        $y_steps = 5;
        for ($i = 0; $i <= $y_steps; $i++):
            $val = $y_max * $i / $y_steps;
            $yy = $py($val);
      ?>
        <line class="viz-grid" x1="<?= $padL ?>" y1="<?= round($yy,1) ?>" x2="<?= $W-$padR ?>" y2="<?= round($yy,1) ?>"/>
        <text class="viz-tick" x="<?= $padL-8 ?>" y="<?= round($yy+3,1) ?>" text-anchor="end">£<?= number_format($val,0) ?></text>
      <?php endfor; ?>
      <?php
        // X ticks
        $x_steps = 5;
        for ($i = 0; $i <= $x_steps; $i++):
            $val = $x_max * $i / $x_steps;
            $xx = $px($val);
      ?>
        <text class="viz-tick" x="<?= round($xx,1) ?>" y="<?= $H-$padB+18 ?>" text-anchor="middle"><?= number_format($val,0) ?></text>
      <?php endfor; ?>
      <!-- baseline -->
      <line class="viz-baseline" x1="<?= $padL ?>" y1="<?= $padT+$plotH ?>" x2="<?= $W-$padR ?>" y2="<?= $padT+$plotH ?>"/>
      <line class="viz-baseline" x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $padL ?>" y2="<?= $padT+$plotH ?>"/>
      <!-- axis labels -->
      <text class="viz-axis-label" x="<?= $padL + $plotW/2 ?>" y="<?= $H-6 ?>" text-anchor="middle"><?= htmlspecialchars($nat['axis']) ?></text>
      <text class="viz-axis-label" x="<?= 16 ?>" y="<?= $padT + $plotH/2 ?>" text-anchor="middle" transform="rotate(-90 16 <?= $padT + $plotH/2 ?>)">Tech spend per resident →</text>
      <?php
        // Least-squares trend line (spend on IMD)
        $slope = $sxx > 0 ? $sxy / $sxx : 0;
        $intercept = $my - $slope * $mx;
        $tx1 = 0; $tx2 = $x_max;
        $ty1 = $intercept + $slope * $tx1;
        $ty2 = $intercept + $slope * $tx2;
      ?>
      <line class="viz-trend" x1="<?= round($px($tx1),1) ?>" y1="<?= round($py(max(0,$ty1)),1) ?>"
                              x2="<?= round($px($tx2),1) ?>" y2="<?= round($py(max(0,$ty2)),1) ?>"/>
      <!-- dots -->
      <?php
        // Deprivation-metric label for the tooltip (nation-specific).
        $imd_tip_label = $nation === 'E' ? 'IMD score' : '% in most-deprived quintile';
        foreach ($rows as $row):
        $cx = $px((float)$row['imd_x']);
        $cy = $py((float)$row['spend_per_head']);
      ?>
        <circle class="viz-dot" cx="<?= round($cx,1) ?>" cy="<?= round($cy,1) ?>" r="4.5"
          data-council="<?= htmlspecialchars($row['council'], ENT_QUOTES) ?>"
          data-lad="<?= htmlspecialchars($row['lad_code'], ENT_QUOTES) ?>"
          data-imd="<?= number_format((float)$row['imd_x'], (int)$nat['x_dp']) ?><?= $nation === 'E' ? '' : '%' ?>"
          data-sph="<?= number_format((float)$row['spend_per_head'],2) ?>"
          data-total="<?= htmlspecialchars(fmt_value((float)$row['total_spend']), ENT_QUOTES) ?>"></circle>
      <?php endforeach; ?>
    </svg>
  </div>

  <!-- ── Table ── -->
  <div id="tab-table" role="tabpanel" aria-labelledby="btn-table" hidden>
    <div style="overflow-x:auto">
    <table class="govuk-table" id="analysis-table">
      <?php
        $imd_col_label = $nation === 'E' ? 'IMD score' : '% in most-deprived quintile';
      ?>
      <thead class="govuk-table__head"><tr>
        <th class="govuk-table__header">Council</th>
        <th class="govuk-table__header">Type</th>
        <th class="govuk-table__header govuk-table__header--numeric"><button type="button" class="ct-sort" data-col="2" data-type="num"><?= htmlspecialchars($imd_col_label) ?><span class="ct-sort__arrow"></span></button></th>
        <th class="govuk-table__header govuk-table__header--numeric"><button type="button" class="ct-sort" data-col="3" data-type="num">Spend / head<span class="ct-sort__arrow"></span></button></th>
        <th class="govuk-table__header govuk-table__header--numeric"><button type="button" class="ct-sort" data-col="4" data-type="num">Total spend<span class="ct-sort__arrow"></span></button></th>
      </tr></thead>
      <tbody class="govuk-table__body">
        <?php foreach ($rows as $row): ?>
        <tr class="govuk-table__row">
          <td class="govuk-table__cell"><a class="govuk-link" href="/contracts/council.php?id=<?= htmlspecialchars($row['lad_code']) ?>"><?= htmlspecialchars($row['council']) ?></a></td>
          <td class="govuk-table__cell" style="font-size:.85rem;color:#505a5f"><?= htmlspecialchars($row['council_type']) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" data-sort="<?= (float)$row['imd_x'] ?>"><?= number_format((float)$row['imd_x'], (int)$nat['x_dp']) ?><?= $nation === 'E' ? '' : '%' ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" data-sort="<?= (float)$row['spend_per_head'] ?>">£<?= number_format((float)$row['spend_per_head'],2) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" data-sort="<?= (float)$row['total_spend'] ?>"><?= fmt_value((float)$row['total_spend']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php foreach ($noimd_rows as $row): ?>
        <tr class="govuk-table__row">
          <td class="govuk-table__cell"><a class="govuk-link" href="/contracts/council.php?id=<?= htmlspecialchars($row['lad_code']) ?>"><?= htmlspecialchars($row['council']) ?></a></td>
          <td class="govuk-table__cell" style="font-size:.85rem;color:#505a5f"><?= htmlspecialchars($row['council_type']) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" data-sort="-1" title="No <?= $nation === 'E' ? 'IoD2019' : 'index' ?> score at this council's tier"><span style="color:#898781">—</span></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" data-sort="<?= (float)$row['spend_per_head'] ?>">£<?= number_format((float)$row['spend_per_head'],2) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" data-sort="<?= (float)$row['total_spend'] ?>"><?= fmt_value((float)$row['total_spend']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($noimd_rows): ?>
    <p class="govuk-body-s" style="color:#505a5f;margin-top:8px">
      The <?= count($noimd_rows) ?> council<?= count($noimd_rows) === 1 ? '' : 's' ?> shown with “—” (mostly county councils) have recorded spend but no
      <?= $nation === 'E' ? 'IoD2019' : 'deprivation' ?> score at their tier, so they appear in the table only, not the scatter.
    </p>
    <?php endif; ?>
    </div>
  </div>
</div>

<div class="viz-tip" id="viz-tip"></div>

<script>
(function () {
  // Tabs
  var tabs = document.querySelectorAll('.analysis-tabs button');
  tabs.forEach(function (btn) {
    btn.addEventListener('click', function () {
      tabs.forEach(function (b) {
        b.setAttribute('aria-selected', 'false');
        document.getElementById(b.getAttribute('aria-controls')).hidden = true;
      });
      btn.setAttribute('aria-selected', 'true');
      document.getElementById(btn.getAttribute('aria-controls')).hidden = false;
    });
  });

  // Scatter tooltip
  var tip = document.getElementById('viz-tip');
  document.querySelectorAll('.viz-dot').forEach(function (dot) {
    dot.addEventListener('mousemove', function (e) {
      tip.innerHTML = '<strong>' + dot.dataset.council + '</strong><br>'
        + <?= json_encode($imd_tip_label) ?> + ' ' + dot.dataset.imd + '<br>'
        + '£' + dot.dataset.sph + '/head · ' + dot.dataset.total + ' total';
      tip.style.left = (e.clientX + 14) + 'px';
      tip.style.top  = (e.clientY + 14) + 'px';
      tip.style.opacity = '1';
    });
    dot.addEventListener('mouseleave', function () { tip.style.opacity = '0'; });
    dot.addEventListener('click', function () {
      window.location = '/contracts/council.php?id=' + encodeURIComponent(dot.dataset.lad);
    });
  });

  // Sortable table — sorts on the cell's raw data-sort value, not formatted text.
  var table = document.getElementById('analysis-table');
  if (table) {
    var tbody = table.querySelector('tbody');
    table.querySelectorAll('.ct-sort').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var col = +btn.dataset.col;
        var dir = btn.dataset.dir === 'desc' ? 'asc' : 'desc';
        // clear arrows on the other headers
        table.querySelectorAll('.ct-sort').forEach(function (b) { if (b !== btn) b.removeAttribute('data-dir'); });
        btn.dataset.dir = dir;
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        rows.sort(function (a, b) {
          var av = parseFloat(a.children[col].getAttribute('data-sort')) || 0;
          var bv = parseFloat(b.children[col].getAttribute('data-sort')) || 0;
          return dir === 'asc' ? av - bv : bv - av;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
      });
    });
  }
})();
</script>

<?php layout_foot(true); ?>
