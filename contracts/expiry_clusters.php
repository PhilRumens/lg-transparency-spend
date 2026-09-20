<?php
/**
 * expiry_clusters.php — Contract expiry clusters.
 *
 * Public (no login). Finds groups of geographically-near councils running the
 * SAME LGAM product with tech contracts expiring within a shared time window —
 * i.e. joint-procurement opportunities / co-terminating regional markets.
 *
 * Engine: cluster_lib.php (find_expiry_clusters). Geography from ct_la_geo
 * (ONS LAD centroids); "near" = pairwise haversine within the chosen radius.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/cluster_lib.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// ── Filters (GET) ────────────────────────────────────────────────────────
$group_opts   = ['proximity' => 'Proximity (distance)', 'sa' => 'Strategic Authority'];
$radius_opts  = [15 => '15 km (same conurbation)', 25 => '25 km', 40 => '40 km (sub-region)', 60 => '60 km', 100 => '100 km (wide)', 2000 => 'UK-wide (any distance)'];
$window_opts  = [3 => 'within 3 months', 6 => 'within 6 months', 9 => 'within 9 months', 12 => 'within 12 months', 24 => 'within 24 months'];
$horizon_opts = [3 => 'next 3 months', 6 => 'next 6 months', 12 => 'next 12 months', 24 => 'next 2 years', 36 => 'next 3 years', 60 => 'next 5 years', 0 => 'any future date'];
$minc_opts    = [2 => '2+', 3 => '3+', 4 => '4+', 5 => '5+', 10 => '10+'];

$group   = (string)($_GET['group'] ?? 'proximity'); if (!isset($group_opts[$group])) $group = 'proximity';
$radius  = (int)($_GET['radius'] ?? 2000); if (!isset($radius_opts[$radius]))   $radius = 2000;
$window  = (int)($_GET['window'] ?? 3);    if (!isset($window_opts[$window]))   $window = 3;
$horizon = (int)($_GET['horizon'] ?? 12);  if (!isset($horizon_opts[$horizon])) $horizon = 12;
$minc    = (int)($_GET['minc']   ?? 5);    if (!isset($minc_opts[$minc]))       $minc = 5;
$tab     = ($_GET['tab'] ?? 'chart') === 'list' ? 'list' : 'chart';

$clusters = find_expiry_clusters($pdo, [
    'group_by'       => $group,
    'radius_km'      => $radius,
    'window_months'  => $window,
    'horizon_months' => $horizon,
    'min_councils'   => $minc,
]);

// ── Pagination ───────────────────────────────────────────────────────────
$per_page    = 25;
$total       = count($clusters);
$page_count  = max(1, (int)ceil($total / $per_page));
$page        = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
if ($page > $page_count) $page = $page_count;
$page_clusters = array_slice($clusters, ($page - 1) * $per_page, $per_page);

// Build a querystring carrying the current filters (minus page) for page links.
// Paging only happens from the List tab, so keep the reader there on navigation.
$page_qs = http_build_query([
    'tab' => 'list', 'group' => $group, 'radius' => $radius, 'window' => $window,
    'horizon' => $horizon, 'minc' => $minc,
]);

// ── Scatter data: one point per cluster, across the WHOLE filtered set ──────
// x = earliest expiry (unix ms), y = combined value (£), r-driver = councils.
$chart_points = [];
foreach ($clusters as $ci => $c) {
    $chart_points[] = [
        'x'   => strtotime($c['earliest_end']) * 1000,
        'y'   => (float)$c['total_value'],
        'n'   => (int)$c['n_councils'],
        'lo'  => $c['earliest_end'],
        'hi'  => $c['latest_end'],
        'p'   => $c['product_name'] ?: 'Unnamed product',
        's'   => $c['product_supplier'] ?? '',
        'sa'  => $c['sa_name'] ?? '',
        'pg'  => (int)floor($ci / $per_page) + 1,   // which results page this card is on
        'i'   => $ci,                               // global cluster index → table anchor
    ];
}

// lad_code lookup for council links
$lad_by_council = [];
foreach ($pdo->query("SELECT council, lad_code FROM ct_council_config WHERE lad_code IS NOT NULL AND lad_code != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $lad_by_council[$r['council']] = $r['lad_code'];
}

function fmt_gbp(float $v): string {
    if ($v >= 1e6) return '£' . number_format($v / 1e6, 1) . 'M';
    if ($v >= 1e3) return '£' . number_format($v / 1e3, 0) . 'k';
    return '£' . number_format($v);
}

/**
 * Render a per-cluster expiry timeline as inline SVG. One row per council,
 * sorted by expiry date; each dot sits on its expiry along a shared month
 * axis; dot size ∝ contract value. Replaces the flat council-chip list.
 */
function render_cluster_timeline(array $c, array $lad_by): string {
    $members = $c['councils'];
    usort($members, fn($a, $b) => strtotime($a['end_date']) <=> strtotime($b['end_date']));

    $lo = strtotime($c['earliest_end']);
    $hi = strtotime($c['latest_end']);
    $maxv = 0.0;
    foreach ($members as $m) $maxv = max($maxv, (float)$m['value']);
    if ($maxv <= 0) $maxv = 1;

    // Geometry (SVG scales to container width via viewBox).
    $rowH = 20; $labelW = 265; $valW = 70; $plotL = $labelW; $plotR = 40; $W = 960;
    $plotW = $W - $labelW - $valW - $plotR;
    $topPad = 42;
    $H = $topPad + count($members) * $rowH + 16;

    $xOf = function($ts) use ($lo, $hi, $plotL, $plotW) {
        if ($hi <= $lo) return $plotL + $plotW / 2;   // single-date cluster: centre the dots
        return $plotL + ($ts - $lo) / ($hi - $lo) * $plotW;
    };
    $rOf = fn($v) => 3 + 8 * sqrt(max(0, $v) / $maxv);

    // Month gridlines across the span.
    $ticks = [];
    $t = strtotime(date('Y-m-01', $lo));
    $end = $hi + 2678400;
    while ($t <= $end) { $ticks[] = $t; $t = strtotime('+1 month', $t); }

    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $o = [];
    $o[] = '<svg class="ec-tl" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Timeline of contract expiries by council">';

    if ($hi > $lo) {
        foreach ($ticks as $tk) {
            $X = $xOf($tk);
            if ($X < $plotL - 1 || $X > $W - $valW) continue;
            $o[] = '<line class="ec-tl-grid" x1="' . round($X,1) . '" y1="' . ($topPad-8) . '" x2="' . round($X,1) . '" y2="' . ($H-12) . '"/>';
            $o[] = '<text class="ec-tl-mlab" x="' . round($X,1) . '" y="' . ($topPad-14) . '" text-anchor="middle">' . date('M y', $tk) . '</text>';
        }
    }

    foreach ($members as $i => $m) {
        $y = $topPad + $i * $rowH + $rowH/2;
        $X = $xOf(strtotime($m['end_date']));
        $r = $rOf((float)$m['value']);
        $lad = $lad_by[$m['council']] ?? ($m['lad_code'] ?? '');
        $label = $lad
            ? '<a href="/contracts/council.php?id=' . $h($lad) . '">' . $h($m['council']) . '</a>'
            : $h($m['council']);
        $tip = $h($m['council'] . ' — ' . date('j M Y', strtotime($m['end_date'])) . ' · ' . fmt_gbp((float)$m['value']));
        $o[] = '<text class="ec-tl-rowlab" x="6" y="' . round($y+4,1) . '">' . $label . '</text>';
        $o[] = '<line class="ec-tl-stem" x1="' . $plotL . '" y1="' . round($y,1) . '" x2="' . round($X,1) . '" y2="' . round($y,1) . '"/>';
        $o[] = '<circle class="ec-tl-dot" cx="' . round($X,1) . '" cy="' . round($y,1) . '" r="' . round($r,1) . '"><title>' . $tip . '</title></circle>';
        $o[] = '<text class="ec-tl-val" x="' . ($W-$valW+6) . '" y="' . round($y+4,1) . '">' . fmt_gbp((float)$m['value']) . '</text>';
    }
    $o[] = '</svg>';
    return implode('', $o);
}

layout_head('Contract expiry clusters', 'expiry_clusters.php');
?>
<style>
  .ec-intro { max-width: none; }
  .ec-filters { display:flex; align-items:flex-end; gap:14px; margin:0 0 16px; flex-wrap:wrap; }
  .ec-filters .govuk-label { margin:0 0 2px; font-weight:700; font-size:.8rem }
  .ec-filters select { height:36px; padding:2px 8px }
  @media (min-width:641px) {
    .ec-filters { flex-wrap:nowrap; }
    .ec-filters > div { flex:1 1 0; min-width:0; }
    .ec-filters select { width:100%; }
  }
  .ec-count { color:#505a5f; font-size:.9rem; margin:0 0 16px }
  .ec-card { border:1px solid #b1b4b6; border-left:5px solid #1d70b8; margin:0 0 14px; background:#fff }
  .ec-card__summary { list-style:none; cursor:pointer; padding:14px 16px 14px 40px; position:relative; outline-offset:-3px }
  .ec-card__summary::-webkit-details-marker { display:none }
  .ec-card__summary:hover { background:#f8f8f8 }
  .ec-card__summary:focus-visible { outline:3px solid #ffdd00 }
  /* Disclosure caret — rotates when open */
  .ec-card__summary::before { content:""; position:absolute; left:16px; top:20px; width:0; height:0; border-left:7px solid #1d70b8; border-top:5px solid transparent; border-bottom:5px solid transparent; transition:transform .12s }
  .ec-card[open] > .ec-card__summary::before { transform:rotate(90deg) }
  .ec-card[open] > .ec-card__summary { border-bottom:1px solid #e1e0d9 }
  .ec-card__body { padding:12px 16px 14px 40px }
  .ec-card__head { display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap; margin-bottom:6px }
  .ec-card__title { font-weight:700; font-size:1.1rem; margin:0 }
  .ec-card__supplier { color:#505a5f; font-weight:400; font-size:.9rem }
  .ec-desc { color:#0b0c0c; font-size:.9rem; margin:0 0 6px }
  .ec-sa { display:inline-block; font-size:.78rem; font-weight:400; color:#005a30; background:#cce2d8; border-radius:3px; padding:2px 7px; margin-left:6px; white-space:nowrap }
  /* Scatter — procurement opportunity map */
  .ec-chart-wrap { border:1px solid #b1b4b6; background:#fff; padding:14px 10px 6px; margin:0 0 20px }
  .ec-chart-title { font-size:.95rem; font-weight:700; color:#0b0c0c; margin:0 6px 2px }
  .ec-chart-sub { font-size:.8rem; color:#505a5f; margin:0 6px 8px }
  .ec-chart-svg { display:block; width:100%; height:auto; font-family:inherit }
  .ec-chart-svg .axis-line { stroke:#c3c2b7; stroke-width:1 }
  .ec-chart-svg .grid-line { stroke:#e1e0d9; stroke-width:1 }
  .ec-chart-svg .tick-label { fill:#898781; font-size:11px }
  .ec-chart-svg .axis-title { fill:#505a5f; font-size:12px; font-weight:700 }
  .ec-chart-svg a { cursor:pointer }
  .ec-chart-svg a:focus { outline:none }
  .ec-chart-svg a:focus .dot { stroke:#0b0c0c; stroke-width:3 }
  .ec-chart-svg .dot { fill:#1d70b8; fill-opacity:.55; stroke:#fff; stroke-width:2; cursor:pointer }
  .ec-chart-svg a:hover .dot,
  .ec-chart-svg .dot:hover { fill-opacity:.85 }
  /* Card the reader was deep-linked to from a scatter dot — brief highlight. */
  .ec-card:target { border-left-color:#00703c; box-shadow:0 0 0 3px #cce2d8; animation:ec-flash 2s ease-out 1 }
  @keyframes ec-flash { 0% { box-shadow:0 0 0 3px #ffdd00 } 100% { box-shadow:0 0 0 3px #cce2d8 } }
  .ec-expandbar { display:flex; justify-content:flex-end; margin:0 0 12px }
  .ec-expandall { background:none; border:none; color:#1d70b8; font-size:.9rem; font-weight:700; cursor:pointer; padding:4px 2px }
  .ec-expandall:hover { text-decoration:underline }
  .ec-tip { position:fixed; z-index:50; pointer-events:none; background:#0b0c0c; color:#fff; font-size:.8rem; line-height:1.4; padding:8px 10px; border-radius:4px; max-width:280px; opacity:0; transition:opacity .08s }
  .ec-tip strong { color:#fff }
  .ec-tip .ec-tip-sa { color:#95d5b2 }
  .ec-chart-legend { font-size:.78rem; color:#505a5f; margin:2px 6px 0 }
  /* View tabs (Chart / List) — mirrors analysis.php */
  .ec-tabs { display:flex; gap:0; border-bottom:1px solid #b1b4b6; margin:8px 0 16px }
  .ec-tabs button { background:none; border:none; border-bottom:4px solid transparent; padding:10px 16px; font-size:1rem; cursor:pointer; color:#505a5f }
  .ec-tabs button[aria-selected="true"] { border-bottom-color:#1d70b8; color:#1d70b8; font-weight:700 }
  .ec-badges { font-size:.8rem; color:#505a5f }
  .ec-badge { display:inline-block; background:#f3f2f1; border-radius:3px; padding:2px 7px; margin-left:4px }
  .ec-meta { font-size:.85rem; color:#0b0c0c; margin:6px 0 8px }
  .ec-meta strong { color:#1d70b8 }
  .ec-councils { display:flex; flex-wrap:wrap; gap:6px; margin:0 }
  .ec-chip { font-size:.82rem; background:#f3f2f1; border:1px solid #dcdcdc; border-radius:3px; padding:3px 8px }
  .ec-chip .ec-exp { color:#505a5f; margin-left:6px }
  /* Per-cluster expiry timeline (replaces chips) */
  .ec-tl { display:block; width:100%; height:auto; font-family:inherit; margin-top:4px }
  .ec-tl .ec-tl-grid { stroke:#e1e0d9; stroke-width:1 }
  .ec-tl .ec-tl-mlab { fill:#898781; font-size:11px }
  .ec-tl .ec-tl-rowlab { fill:#0b0c0c; font-size:11.5px }
  .ec-tl .ec-tl-rowlab a { fill:#1d70b8; text-decoration:underline }
  .ec-tl .ec-tl-rowlab a:hover { fill:#003078 }
  .ec-tl .ec-tl-val { fill:#505a5f; font-size:11px }
  .ec-tl .ec-tl-stem { stroke:#b1c7de; stroke-width:1 }
  .ec-tl .ec-tl-dot { fill:#1d70b8; fill-opacity:.82; stroke:#fff; stroke-width:1.5 }
  .ec-tl .ec-tl-dot:hover { fill-opacity:1 }
  .ec-tl-legend { color:#505a5f; font-size:.78rem; margin:6px 0 0 }
  .ec-pager { display:flex; align-items:center; gap:16px; margin:20px 0 8px }
  .ec-pager__link { font-size:.95rem; text-decoration:none; color:#1d70b8; font-weight:700 }
  .ec-pager__link:hover { text-decoration:underline }
  .ec-pager__link--off { color:#b1b4b6; font-weight:400 }
  .ec-pager__status { font-size:.9rem; color:#505a5f }
</style>

<h1 class="govuk-heading-l">Contract expiry clusters</h1>
<p class="govuk-body ec-intro">
  Groups of councils procuring the <strong>same IT product or supplier</strong> whose contracts <strong>expire around the
  same time</strong> — candidate joint-procurement opportunities and co-terminating markets. Shows all UK
  councils by default; use the <strong>Proximity</strong> filter to narrow to councils near each other for
  regional or shared-service deals. Ranked by a score combining cluster size, how tightly the expiry dates
  bunch, product specificity (niche platforms rank above commodities), and combined contract value.
</p>

<form method="get" class="ec-filters">
  <div>
    <label class="govuk-label" for="group">Group by</label>
    <select class="govuk-select" id="group" name="group" onchange="this.form.submit()">
      <?php foreach ($group_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $group ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div<?= $group === 'sa' ? ' style="display:none"' : '' ?>>
    <label class="govuk-label" for="radius">Proximity</label>
    <select class="govuk-select" id="radius" name="radius" onchange="this.form.submit()">
      <?php foreach ($radius_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $radius ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="govuk-label" for="horizon">Expires within</label>
    <select class="govuk-select" id="horizon" name="horizon" onchange="this.form.submit()">
      <?php foreach ($horizon_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $horizon ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="govuk-label" for="window">Co-termination spread</label>
    <select class="govuk-select" id="window" name="window" onchange="this.form.submit()">
      <?php foreach ($window_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $window ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="govuk-label" for="minc">Min councils</label>
    <select class="govuk-select" id="minc" name="minc" onchange="this.form.submit()">
      <?php foreach ($minc_opts as $v => $l): ?><option value="<?= $v ?>"<?= $v === $minc ? ' selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
    </select>
  </div>
  <noscript><button class="govuk-button" style="margin:0">Apply</button></noscript>
</form>

<div class="govuk-inset-text" style="margin:0 0 16px;font-size:.85rem;color:#505a5f">
  <?php if ($group === 'sa'): ?>
    <strong>“Group by → Strategic Authority”</strong> clusters councils that belong to the <strong>same
    combined / strategic authority</strong> and run the same product with co-terminating contracts — the natural
    unit for a joint procurement. Councils not in any Strategic Authority are excluded in this mode; switch to
    <strong>Proximity</strong> to include them by distance instead.
  <?php else: ?>
    <strong>“Near” = every pair of councils in a cluster within the chosen radius</strong> (straight-line, between
    ONS local-authority centroids). Set Proximity to <strong>UK-wide</strong> to group purely on product and
    timing regardless of location.
  <?php endif; ?>
  Only councils with a published contract register, an LGAM-classified tech contract, and a future end date are
  included — so gaps in register coverage mean some real clusters are invisible. County councils and national
  bodies are excluded. Contract values are as recorded in each register (often estimated/annualised) and are
  indicative, not audited.<br><br>
  <strong>“Expires within”</strong> sets how far ahead to look (a horizon from today).
  <strong>“Co-termination spread”</strong> sets how tightly a cluster’s expiries must bunch — the gap between the
  earliest and latest end date in a group — so a tighter spread means the contracts fall due closer together.
</div>

<p class="ec-count"><?= $total ?> cluster<?= $total === 1 ? '' : 's' ?> found
  (<?= $group === 'sa' ? 'by Strategic Authority' : ($radius >= 2000 ? 'UK-wide' : 'radius ' . $radius . ' km') ?>, expiring <?= $horizon === 0 ? 'any future date' : 'within ' . $horizon . ' months' ?>,
  co-terminating within <?= $window ?> months, <?= $minc ?>+ councils).<?php if ($total > $per_page): ?>
  Showing <strong><?= (($page - 1) * $per_page) + 1 ?>–<?= min($page * $per_page, $total) ?></strong> (page <?= $page ?> of <?= $page_count ?>).<?php endif; ?></p>

<?php if (!$clusters): ?>
  <p class="govuk-body">No clusters match these filters. Try <?= $group === 'sa' ? 'switching Group by to Proximity' : 'a wider radius' ?>, a longer expiry horizon, a looser co-termination spread, or a lower council threshold.</p>
<?php else: ?>

<div class="ec-tabs" role="tablist">
  <button role="tab" aria-selected="<?= $tab === 'chart' ? 'true' : 'false' ?>" aria-controls="ec-tab-chart" id="ec-btn-chart">Scatter</button>
  <button role="tab" aria-selected="<?= $tab === 'list' ? 'true' : 'false' ?>" aria-controls="ec-tab-list" id="ec-btn-list">Table</button>
</div>

<div id="ec-tab-chart" role="tabpanel" aria-labelledby="ec-btn-chart"<?= $tab === 'list' ? ' hidden' : '' ?>>
<?php // ── Procurement opportunity map (scatter) ────────────────────────────
  // Geometry
  $W = 900; $H = 380; $mL = 62; $mR = 20; $mT = 12; $mB = 42;
  $plotW = $W - $mL - $mR; $plotH = $H - $mT - $mB;

  // Domains
  $xs = array_column($chart_points, 'x');
  $ns = array_column($chart_points, 'n');
  $xmin = min($xs); $xmax = max($xs); if ($xmax == $xmin) $xmax = $xmin + 86400000;
  $span_days_all = ($xmax - $xmin) / 86400000;
  // Linear y-domain: number of councils. Floor at 0, top a touch above the max
  // so the tallest dot isn't clipped against the frame.
  $nmax = max($ns);
  $ytop = $nmax + 1;

  $px = fn($x) => $mL + ($x - $xmin) / ($xmax - $xmin) * $plotW;
  $py = fn($n) => $mT + $plotH - ($n / max($ytop, 1)) * $plotH;

  // Radius by combined contract value: sqrt scale so dot AREA reads ∝ value.
  // Bounded 4–16px between the smallest and largest value in view.
  $vs = array_filter(array_column($chart_points, 'y'), fn($v) => $v > 0);
  $vmin = $vs ? min($vs) : 0.0;
  $vmax = $vs ? max($vs) : 1.0;
  $svmin = sqrt(max($vmin, 0)); $svmax = sqrt(max($vmax, 1));
  $pr = function($v) use ($svmin, $svmax) {
      if ($svmax <= $svmin) return 8.0;
      return 4 + ((sqrt(max((float)$v, 0)) - $svmin) / ($svmax - $svmin)) * 12;
  };

  // X ticks: ~6 evenly spaced dates. Y ticks: integer council counts.
  $xticks = [];
  for ($t = 0; $t <= 5; $t++) { $xticks[] = $xmin + ($xmax - $xmin) * $t / 5; }
  // Aim for ~5–6 clean integer y ticks; step up if there are many councils.
  $ystep = (int)max(1, ceil($ytop / 6));
  $yticks = [];
  for ($v = 0; $v <= $ytop; $v += $ystep) { $yticks[] = $v; }

  $fmt_axis_gbp = function($v) {
      if ($v >= 1e6) return '£' . rtrim(rtrim(number_format($v / 1e6, 1), '0'), '.') . 'M';
      if ($v >= 1e3) return '£' . (int)($v / 1e3) . 'k';
      return '£' . (int)$v;
  };
?>
<div class="ec-chart-wrap">
  <p class="ec-chart-title">Procurement opportunity map</p>
  <p class="ec-chart-sub">Each dot is a cluster · left = expiring soonest · higher = more councils · bigger = greater combined value. Hover for detail.</p>
  <svg class="ec-chart-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Scatter plot of contract clusters by expiry date and number of councils" id="ec-scatter">
    <!-- gridlines + y ticks -->
    <?php foreach ($yticks as $yt): $Y = $py($yt); ?>
      <line class="grid-line" x1="<?= $mL ?>" y1="<?= round($Y,1) ?>" x2="<?= $mL + $plotW ?>" y2="<?= round($Y,1) ?>"/>
      <text class="tick-label" x="<?= $mL - 6 ?>" y="<?= round($Y+3,1) ?>" text-anchor="end"><?= (int)$yt ?></text>
    <?php endforeach; ?>
    <!-- x ticks -->
    <?php
      // Short spans need day-level labels or the month ticks read as duplicates.
      $xfmt = $span_days_all <= 120 ? 'j M y' : 'M Y';
      foreach ($xticks as $xt): $X = $px($xt); ?>
      <line class="axis-line" x1="<?= round($X,1) ?>" y1="<?= $mT + $plotH ?>" x2="<?= round($X,1) ?>" y2="<?= $mT + $plotH + 4 ?>"/>
      <text class="tick-label" x="<?= round($X,1) ?>" y="<?= $mT + $plotH + 18 ?>" text-anchor="middle"><?= date($xfmt, (int)($xt/1000)) ?></text>
    <?php endforeach; ?>
    <!-- axis lines -->
    <line class="axis-line" x1="<?= $mL ?>" y1="<?= $mT ?>" x2="<?= $mL ?>" y2="<?= $mT + $plotH ?>"/>
    <line class="axis-line" x1="<?= $mL ?>" y1="<?= $mT + $plotH ?>" x2="<?= $mL + $plotW ?>" y2="<?= $mT + $plotH ?>"/>
    <text class="axis-title" x="<?= $mL ?>" y="<?= $H - 6 ?>" text-anchor="start">Earliest expiry →</text>
    <text class="axis-title" x="<?= -($mT + $plotH/2) ?>" y="14" transform="rotate(-90)" text-anchor="middle">Number of councils</text>
    <!-- dots (drawn largest-value-first so small ones stay clickable on top) -->
    <?php
      usort($chart_points, fn($a, $b) => $b['y'] <=> $a['y']);
      foreach ($chart_points as $pt):
        $tip = htmlspecialchars($pt['p'] . ($pt['s'] ? ' — ' . $pt['s'] : '') . '|' .
               $pt['n'] . ' councils|' . fmt_gbp((float)$pt['y']) . '|' .
               date('M Y', strtotime($pt['lo'])) . ($pt['lo'] !== $pt['hi'] ? '–' . date('M Y', strtotime($pt['hi'])) : '') .
               '|' . $pt['sa'], ENT_QUOTES);
        // Deep-link to this cluster's card in the Table view (right page + anchor).
        $href = htmlspecialchars('?' . $page_qs . '&page=' . $pt['pg'] . '#c' . $pt['i'], ENT_QUOTES);
    ?>
      <a href="<?= $href ?>" aria-label="<?= htmlspecialchars($pt['p'], ENT_QUOTES) ?> — view in table"><circle class="dot" cx="<?= round($px($pt['x']),1) ?>" cy="<?= round($py($pt['n']),1) ?>" r="<?= round($pr($pt['y']),1) ?>" data-tip="<?= $tip ?>"/></a>
    <?php endforeach; ?>
  </svg>
  <p class="ec-chart-legend">Dot size ∝ combined contract value of the cluster. Vertical position is the number of councils.</p>
</div>
<div class="ec-tip" id="ec-tip"></div>
<script>
(function(){
  var svg = document.getElementById('ec-scatter'), tip = document.getElementById('ec-tip');
  if (!svg || !tip) return;
  function show(e){
    var d = e.target.getAttribute('data-tip'); if (!d) return;
    var p = d.split('|');
    var sa = p[4] ? '<div class="ec-tip-sa">🏛 ' + p[4] + '</div>' : '';
    tip.innerHTML = '<strong>' + p[0] + '</strong><br>' + p[1] + ' · ' + p[2] + '<br>expiring ' + p[3] + sa;
    tip.style.opacity = '1';
    move(e);
  }
  function move(e){
    var x = e.clientX + 14, y = e.clientY + 14;
    if (x + 290 > window.innerWidth) x = e.clientX - 290;
    tip.style.left = x + 'px'; tip.style.top = y + 'px';
  }
  function hide(){ tip.style.opacity = '0'; }
  svg.addEventListener('mouseover', function(e){ if (e.target.classList.contains('dot')) show(e); });
  svg.addEventListener('mousemove', function(e){ if (e.target.classList.contains('dot')) move(e); });
  svg.addEventListener('mouseout', function(e){ if (e.target.classList.contains('dot')) hide(); });
})();
</script>
</div><!-- /ec-tab-chart -->

<div id="ec-tab-list" role="tabpanel" aria-labelledby="ec-btn-list"<?= $tab === 'list' ? '' : ' hidden' ?>>
  <div class="ec-expandbar"><button type="button" class="ec-expandall" id="ec-expandall" aria-expanded="false">Expand all</button></div>
  <?php foreach ($page_clusters as $pi => $c): $gi = (($page - 1) * $per_page) + $pi; ?>
    <details class="ec-card" id="c<?= $gi ?>">
      <summary class="ec-card__summary">
        <div class="ec-card__head">
          <p class="ec-card__title"><?= htmlspecialchars($c['product_name'] ?: 'Unnamed product') ?><?php if (!empty($c['product_supplier'])): ?> <span class="ec-card__supplier">— <?= htmlspecialchars($c['product_supplier']) ?></span><?php endif; ?><?php if (!empty($c['sa_name'])): ?> <span class="ec-sa">🏛 <?= htmlspecialchars($c['sa_name']) ?></span><?php endif; ?></p>
          <span class="ec-badges">
            <span class="ec-badge">score <?= number_format($c['score'], 1) ?></span>
            <span class="ec-badge"><?= (int)$c['ubiq_count'] ?> councils use this nationally</span>
          </span>
        </div>
        <p class="ec-meta">
          <strong><?= (int)$c['n_councils'] ?> councils</strong> ·
          expiring <strong><?= htmlspecialchars(date('j M Y', strtotime($c['earliest_end']))) ?></strong>
          <?php if ($c['earliest_end'] !== $c['latest_end']): ?>– <strong><?= htmlspecialchars(date('j M Y', strtotime($c['latest_end']))) ?></strong> (span <?= (int)$c['span_days'] ?> days)<?php endif; ?> ·
          combined value <strong><?= fmt_gbp((float)$c['total_value']) ?></strong>
        </p>
      </summary>
      <div class="ec-card__body">
        <?php if (!empty($c['product_desc'])): ?><p class="ec-desc"><?= htmlspecialchars($c['product_desc']) ?></p><?php endif; ?>
        <?= render_cluster_timeline($c, $lad_by_council) ?>
        <p class="ec-tl-legend">Each row a council · position = expiry date · dot size ∝ contract value · hover a dot for detail.</p>
      </div>
    </details>
  <?php endforeach; ?>

  <?php if ($page_count > 1): ?>
    <nav class="ec-pager" aria-label="Cluster pages">
      <?php if ($page > 1): ?>
        <a class="ec-pager__link" href="?<?= $page_qs ?>&amp;page=<?= $page - 1 ?>">‹ Previous</a>
      <?php else: ?>
        <span class="ec-pager__link ec-pager__link--off">‹ Previous</span>
      <?php endif; ?>
      <span class="ec-pager__status">Page <?= $page ?> of <?= $page_count ?></span>
      <?php if ($page < $page_count): ?>
        <a class="ec-pager__link" href="?<?= $page_qs ?>&amp;page=<?= $page + 1 ?>">Next ›</a>
      <?php else: ?>
        <span class="ec-pager__link ec-pager__link--off">Next ›</span>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
</div><!-- /ec-tab-list -->

<script>
(function(){
  var tabs = document.querySelectorAll('.ec-tabs button');
  tabs.forEach(function(btn){
    btn.addEventListener('click', function(){
      tabs.forEach(function(b){
        b.setAttribute('aria-selected', 'false');
        document.getElementById(b.getAttribute('aria-controls')).hidden = true;
      });
      btn.setAttribute('aria-selected', 'true');
      document.getElementById(btn.getAttribute('aria-controls')).hidden = false;
    });
  });
})();

(function(){
  // Open the accordion the reader was deep-linked to (from a scatter dot).
  function openTarget(){
    if (!location.hash) return;
    var el = document.getElementById(location.hash.slice(1));
    if (el && el.tagName === 'DETAILS') {
      el.open = true;
      el.scrollIntoView({block:'center'});
    }
  }
  openTarget();
  window.addEventListener('hashchange', openTarget);

  // Expand / collapse all on the current page.
  var btn = document.getElementById('ec-expandall');
  if (btn){
    btn.addEventListener('click', function(){
      var open = btn.getAttribute('aria-expanded') !== 'true';
      document.querySelectorAll('#ec-tab-list details.ec-card').forEach(function(d){ d.open = open; });
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.textContent = open ? 'Collapse all' : 'Expand all';
    });
  }
})();
</script>
<?php endif; ?>

<?php layout_foot(true); ?>
