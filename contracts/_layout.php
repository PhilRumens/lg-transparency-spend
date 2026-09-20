<?php
/**
 * _layout.php — shared layout for contracts dashboard pages.
 * Usage: include this file at the top of each page, then call
 *   layout_head('Page title') — outputs <head> and opens <body> and <main>
 *   layout_foot()            — closes <main> and <body>
 */

function layout_head(string $title, string $active = ''): void {
    $nav_items = [
        'supplier.php'       => 'Suppliers',
        'council.php'        => 'Councils',
        'analysis.php'       => 'Analysis',
    ];
    // Section tabs: a secondary nav bar shown under the main menu when the
    // current page belongs to a section. Keyed by the section's top-level page.
    $section_tabs = [
        'analysis.php' => [
            'analysis.php'        => 'Spend',
            'expiry_clusters.php' => 'Contract clusters',
        ],
        // Experimental section — anchored on lgam_classify.php (the top-level
        // "Experimental" nav item), with its features listed as section tabs.
        'lgam_classify.php' => [
            'lgam_classify.php'  => 'LGAM',
            'nations.php'        => 'Nations & Regions',
            'reorg.php'          => 'Reorganisation',
        ],
    ];
    if (logged_in()) {
        $nav_items += [
            'transparency.php'   => 'Payment Data',
            'contracts_reg.php'  => 'Contract Data',
            'tech.php'           => 'Framework Data',
            'lgam_classify.php'  => 'Experimental',
        ];
    } else {
        $nav_items['tech_login.php'] = 'More data';
    }
    $real_base = basename($_SERVER['PHP_SELF']);
    $base = $real_base;
  // sources.php is a sub-page of transparency.php
  if ($base === 'sources.php') $base = 'transparency.php';
  // contracts.php is a sub-page of tech.php
  if ($base === 'contracts.php') $base = 'tech.php';
  // expiry_clusters.php lives in the Analysis section
  if ($base === 'expiry_clusters.php') $base = 'analysis.php';
  // nations.php and reorg.php live in the Experimental section (anchored on
  // lgam_classify.php) so the top-level "Experimental" item highlights for them.
  if ($base === 'nations.php' || $base === 'reorg.php') $base = 'lgam_classify.php';
  // Which section tab bar (if any) applies to the current page.
  $active_tabs = null;
  foreach ($section_tabs as $tabs) {
      if (isset($tabs[$real_base])) { $active_tabs = $tabs; break; }
  }
    ?>
<!DOCTYPE html>
<html lang="en" class="govuk-template">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> – LG Technology Spend</title>
  <link rel="icon" type="image/svg+xml" href="/contracts/assets/icons/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/contracts/assets/icons/favicon-32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/contracts/assets/icons/favicon-16.png">
  <link rel="apple-touch-icon" sizes="64x64" href="/contracts/assets/icons/favicon-64.png">
  <link rel="stylesheet" href="/contracts/assets/css/govuk-frontend.min.css">
  <style>
    /* Ensure GDS Transport fonts load from the correct path */
    @font-face {
      font-family: "GDS Transport";
      src: url("/contracts/assets/fonts/bold-b542beb274-v2.woff2") format("woff2"),
           url("/contracts/assets/fonts/bold-affa96571d-v2.woff") format("woff");
      font-weight: 700;
    }
    @font-face {
      font-family: "GDS Transport";
      src: url("/contracts/assets/fonts/light-94a07e06a1-v2.woff2") format("woff2"),
           url("/contracts/assets/fonts/light-f591b13f7d-v2.woff") format("woff");
      font-weight: 400;
    }
  </style>
  <style>
    .govuk-width-container { max-width: 1170px; }
    .app-subnav { background: #f3f2f1; border-bottom: 1px solid #b1b4b6; margin-bottom: 0; }
    .app-subnav__list { display: flex; list-style: none; margin: 0; padding: 0 15px; gap: 0; }
    .app-subnav__item a { display: block; padding: 12px 15px; font-size: 1rem; font-weight: 400; color: #0b0c0c; text-decoration: none; border-bottom: 4px solid transparent; }
    .app-subnav__item a:hover { color: #0b0c0c; border-bottom-color: #b1b4b6; }
    .app-subnav__item--active > a { font-weight: 700; border-bottom-color: #1d70b8; color: #1d70b8; }
    /* Section tabs — secondary bar under the main menu (e.g. within Analysis) */
    .app-sectionnav { background: #fff; border-bottom: 1px solid #b1b4b6; }
    .app-sectionnav__list { display: flex; list-style: none; margin: 0; padding: 0 15px; gap: 0; }
    .app-sectionnav__item a { display: block; padding: 10px 15px; font-size: .9375rem; font-weight: 400; color: #0b0c0c; text-decoration: none; border-bottom: 3px solid transparent; }
    .app-sectionnav__item a:hover { border-bottom-color: #b1b4b6; }
    .app-sectionnav__item--active a { font-weight: 700; border-bottom-color: #1d70b8; color: #1d70b8; }

    .ct-stat-card { background: #fff; border: 1px solid #b1b4b6; padding: 20px; margin-bottom: 0; }
    .ct-stat-card__number { font-size: 2.5rem; font-weight: 700; line-height: 1; color: #1d70b8; }
    .ct-stat-card__label { font-size: 1rem; color: #505a5f; margin-top: 5px; }
    .ct-stat-card__sub { font-size: 0.875rem; color: #6f777b; margin-top: 3px; }
    .ct-layer { border: 1px solid #b1b4b6; margin-bottom: 15px; }
    .ct-layer__header { background: #f3f2f1; padding: 15px 20px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; border: none; width: 100%; text-align: left; font-family: inherit; font-size: 1.1875rem; font-weight: 700; }
    .ct-layer__header:hover { background: #e8e8e8; }
    .ct-layer__header-right { display: flex; align-items: center; gap: 15px; }
    .ct-layer__count { background: #1d70b8; color: #fff; padding: 3px 10px; border-radius: 3px; font-size: 0.875rem; font-weight: 700; white-space: nowrap; }
    .ct-layer__value { font-size: 0.875rem; color: #505a5f; white-space: nowrap; }
    .ct-layer__chevron { font-size: 0.875rem; color: #505a5f; transition: transform 0.2s; }
    .ct-layer__chevron--open { transform: rotate(180deg); }
    .ct-layer__body { display: none; padding: 20px; border-top: 1px solid #b1b4b6; }
    .ct-layer__body--open { display: block; }
    .ct-sublayer { margin-bottom: 20px; border-left: 4px solid #1d70b8; padding-left: 15px; }
    .ct-sublayer__title { font-weight: 700; font-size: 1rem; margin-bottom: 10px; }
    .ct-sublayer__count { font-weight: 400; color: #505a5f; font-size: 0.875rem; margin-left: 8px; }
    .ct-tag { display: inline-block; padding: 2px 8px; font-size: 0.75rem; font-weight: 700; border-radius: 3px; white-space: nowrap; }
    .ct-tag--complete { background: #f3f2f1; color: #0b0c0c; }
    .ct-tag--active { background: #cce2d8; color: #005a30; }
    .ct-tag--planned, .ct-tag--planning { background: #fff7bf; color: #594d00; }
    .ct-tag--cancelled { background: #fcd6d6; color: #942514; }
    .govuk-table__cell--numeric { font-variant-numeric: tabular-nums; }
    .app-filter-bar { background: #f3f2f1; padding: 15px 20px; margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
    .app-filter-bar .govuk-form-group { margin-bottom: 0; }
    .app-filter-bar .govuk-label { font-size: 0.875rem; }
    .app-filter-bar .govuk-select, .app-filter-bar .govuk-input { font-size: 0.875rem; }
    .ct-vendor-card { border: 1px solid #b1b4b6; margin-bottom: 15px; }
    .ct-vendor-card__header { background: #f3f2f1; padding: 15px 20px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; border: none; width: 100%; text-align: left; font-family: inherit; }
    .ct-vendor-card__header:hover { background: #e8e8e8; }
    .ct-vendor-card__name { font-size: 1.0625rem; font-weight: 700; }
    .ct-vendor-card__cat { font-size: 0.875rem; color: #505a5f; margin-top: 2px; }
    .ct-vendor-card__body { display: none; border-top: 1px solid #b1b4b6; }
    .ct-vendor-card__body--open { display: block; }
    .ct-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1px; background: #b1b4b6; border-top: 1px solid #b1b4b6; }
    .ct-kpi { background: #fff; padding: 12px 15px; }
    .ct-kpi__n { font-size: 1.25rem; font-weight: 700; color: #1d70b8; }
    .ct-kpi__l { font-size: 0.75rem; color: #505a5f; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.03em; }
    .ct-spend-bar { padding: 15px 20px; border-top: 1px solid #b1b4b6; }
    .ct-spend-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 0.875rem; }
    .ct-spend-row__label { width: 160px; flex-shrink: 0; color: #505a5f; }
    .ct-spend-row__track { flex: 1; background: #f3f2f1; height: 8px; border-radius: 4px; overflow: hidden; }
    .ct-spend-row__fill { height: 100%; border-radius: 4px; background: #1d70b8; }
    .ct-spend-row__val { width: 70px; text-align: right; flex-shrink: 0; font-variant-numeric: tabular-nums; font-weight: 700; }
    .ct-fw-note { background: #f3f2f1; border-left: 4px solid #b1b4b6; padding: 10px 15px; font-size: 0.875rem; color: #505a5f; margin-top: 10px; }
    .ct-sync-status { padding: 10px 15px; border-left: 4px solid #1d70b8; background: #e8f1fb; font-size: 0.875rem; margin-bottom: 20px; }
    /* Lets wide data tables scroll horizontally within themselves on narrow
       screens instead of forcing the whole page to scroll sideways. A script
       in layout_foot() wraps every table.govuk-table in one of these. */
    .ct-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 20px; }
    .ct-table-scroll > table.govuk-table { margin-bottom: 0; }
    /* Base font — same as Skills Exchange */
    *{box-sizing:border-box}
    body{font-family:"GDS Transport",arial,sans-serif;color:#0b0c0c}
    /* Header — same approach as Skills Exchange */
    .ct-hdr{background:#0b0c0c;border-bottom:10px solid #1d70b8;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
    .ct-hdr-nav{display:flex;gap:20px;align-items:center}
    .ct-hdr-title{color:#fff;font-size:18px;font-weight:700}
    .ct-hdr-title-link{color:#fff;text-decoration:none;font-size:18px;font-weight:700}
    .ct-hdr-title-link:hover{text-decoration:underline}
    .ct-hdr-link{color:#fff;font-size:14px;text-decoration:underline;cursor:pointer}
    .ct-hdr-link:hover{text-decoration:none}
    /* Prototype banner */
    .ct-phase-banner{background:#fff;padding:8px 20px;border-bottom:1px solid #b1b4b6;font-size:14px;display:flex;align-items:center;gap:10px}
    .ct-phase-tag{background:#4c2c92;color:#fff;font-size:14px;font-weight:700;padding:2px 8px;text-transform:uppercase;letter-spacing:1px;white-space:nowrap}

    @media (max-width: 640px) {
      .app-subnav__list { flex-wrap: wrap; }
      .govuk-grid-column-one-quarter { width: 50%; }
      .ct-hdr { flex-wrap: wrap; row-gap: 8px; padding: 12px 15px; }
      .ct-hdr nav { gap: 12px; flex-wrap: wrap; }
      .ct-stat-card { padding: 15px; }
      .ct-stat-card__number { font-size: 1.75rem; }
      .ct-kpi { padding: 10px 12px; }
      .ct-layer__header, .ct-vendor-card__header { padding: 12px 15px; flex-wrap: wrap; row-gap: 8px; }
      .ct-layer__header-right { gap: 10px; }
      .ct-spend-row__label { width: 110px; font-size: 0.8rem; }
      .app-filter-bar { padding: 12px 15px; gap: 10px; }
      .app-filter-bar .govuk-form-group { flex: 1 1 140px; }
    }
    @media (max-width: 400px) {
      .govuk-grid-column-one-quarter { width: 100%; }
      .ct-spend-row__label { width: 90px; }
    }
  </style>
</head>
<body class="govuk-template__body">
  <script>document.body.className = ((document.body.className) ? document.body.className + ' js-enabled' : 'js-enabled');</script>

  <a href="#main-content" class="govuk-skip-link">Skip to main content</a>

  <div class="ct-hdr">
    <span class="ct-hdr-title"><a href="/contracts/tech.php" class="ct-hdr-title-link">LG Technology Spend</a></span>
    <nav class="ct-hdr-nav">
      <a href="/contracts/tech.php" class="ct-hdr-link">Home</a>
      <?php if (is_admin()): ?>
      <?php endif; ?>
      <?php if (logged_in()): ?>
      <a href="/contracts/logout.php" class="ct-hdr-link"><?php $u = current_user(); echo htmlspecialchars($u['name'] ?: $u['email']); ?> — Log out</a>
      <?php else: ?>
      <a href="/contracts/tech_login.php" class="ct-hdr-link">Sign in</a>
      <?php endif; ?>
    </nav>
  </div>

  <div class="ct-phase-banner">
    <span class="ct-phase-tag">Prototype</span>
    <span>This is a new service — your <a href="mailto:architect@maplecrescent.co.uk" class="govuk-link">feedback</a> will help us improve it.</span>
  </div>

  <nav class="app-subnav" aria-label="Contracts navigation">
    <div class="govuk-width-container">
      <ul class="app-subnav__list">
        <?php $i = 0; foreach ($nav_items as $file => $label): ?>
          <?php if ($i === 3): ?><li class="app-subnav__item" style="border-left:1px solid #b1b4b6;margin-left:8px;padding-left:8px"></li><?php endif; ?>
          <li class="app-subnav__item<?= $base === $file ? ' app-subnav__item--active' : '' ?>">
            <a href="/contracts/<?= $file ?>"><?= $label ?></a>
          </li>
        <?php $i++; endforeach; ?>
      </ul>
    </div>
  </nav>

  <?php if ($active_tabs): ?>
  <nav class="app-sectionnav" aria-label="<?= htmlspecialchars(($nav_items[$base] ?? 'Section') . ' navigation') ?>">
    <div class="govuk-width-container">
      <ul class="app-sectionnav__list">
        <?php foreach ($active_tabs as $tfile => $tlabel): ?>
          <li class="app-sectionnav__item<?= $real_base === $tfile ? ' app-sectionnav__item--active' : '' ?>">
            <a href="/contracts/<?= $tfile ?>"><?= $tlabel ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </nav>
  <?php endif; ?>

  <div class="govuk-width-container">
    <main class="govuk-main-wrapper" id="main-content">
      <?php if (!str_contains($title, '–')): ?><h1 class="govuk-heading-xl"><?= htmlspecialchars($title) ?></h1><?php endif; ?>
<?php
}

function layout_foot(bool $show_disclaimer = false): void { ?>
    <?php if ($show_disclaimer): ?>
    <div style="margin-top:3rem;padding:1rem 0;border-top:1px solid #b1b4b6">
      <p class="govuk-body-s" style="color:#505a5f">The data presented is compiled from historical expenditure and contract records obtained from contract registers, local and regional authority websites, and information provided in response to requests made to individual local authorities. It is intended for indicative purposes only and should not be regarded as authoritative. For the most accurate and up-to-date information, always refer to the relevant organisation's official website.</p>
    </div>
    <?php endif; ?>
    </main>
  </div>


  <script src="/contracts/assets/js/govuk-frontend.min.js"></script>
  <script>window.GOVUKFrontend.initAll();</script>
  <script>
    // Toggle expand/collapse for layers and vendor cards
    document.addEventListener('click', function(e) {
      var btn = e.target.closest('[data-toggle]');
      if (!btn) return;
      var target = document.getElementById(btn.dataset.toggle);
      if (!target) return;
      var open = target.classList.contains('ct-layer__body--open') ||
                 target.classList.contains('ct-vendor-card__body--open');
      if (target.classList.contains('ct-layer__body')) {
        target.classList.toggle('ct-layer__body--open');
      } else {
        target.classList.toggle('ct-vendor-card__body--open');
      }
      var chev = btn.querySelector('.ct-layer__chevron');
      if (chev) chev.classList.toggle('ct-layer__chevron--open');
    });
  </script>
  <script>
    // Wrap every data table so it scrolls horizontally within itself on
    // narrow screens, rather than the whole page scrolling sideways.
    document.querySelectorAll('table.govuk-table').forEach(function (t) {
      var p = t.parentElement;
      if (p.classList.contains('ct-table-scroll') || p.style.overflowX === 'auto') return;
      var wrap = document.createElement('div');
      wrap.className = 'ct-table-scroll';
      p.insertBefore(wrap, t);
      wrap.appendChild(t);
    });
  </script>
</body>
</html>
<?php
}

// Helper: format a value in £
function fmt_value(?float $v): string {
    if ($v === null) return '—';
    if ($v >= 1_000_000_000) return '£' . number_format($v / 1_000_000_000, 1) . 'bn';
    if ($v >= 1_000_000)     return '£' . number_format($v / 1_000_000, 1) . 'm';
    if ($v >= 1_000)         return '£' . number_format($v / 1_000, 0) . 'k';
    return '£' . number_format($v, 0);
}

// Helper: status tag HTML
function status_tag(string $status): string {
    $labels = ['complete'=>'Awarded','active'=>'Active','planned'=>'Pipeline',
               'planning'=>'PME','cancelled'=>'Cancelled','withdrawn'=>'Withdrawn'];
    $label = $labels[$status] ?? ucfirst($status);
    $cls   = in_array($status, ['planned','planning']) ? 'planned' : $status;
    return '<span class="ct-tag ct-tag--' . htmlspecialchars($cls) . '">' . $label . '</span>';
}
