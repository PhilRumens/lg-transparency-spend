<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();

// ── Filter params ─────────────────────────────────────────────────────────
$filter_layer  = $_GET['layer']  ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['q'] ?? '');

// ── LGAM layer metadata ───────────────────────────────────────────────────
const LGAM_META = [
    'public_channels'     => ['title'=>'Public Channels',                          'icon'=>'📡', 'desc'=>'Online, phone, messaging — how residents interact with councils.'],
    'capabilities'        => ['title'=>'Capabilities',                             'icon'=>'🔧', 'desc'=>'Shared capabilities: forms, payments, booking, identity, workflow.'],
    'business_areas'      => ['title'=>'Business Areas',                           'icon'=>'🏛', 'desc'=>'Service-specific technology for each council function.'],
    'corporate_areas'     => ['title'=>'Corporate Areas',                          'icon'=>'🏢', 'desc'=>'Cross-cutting corporate functions: HR, finance, CRM, GIS, legal.'],
    'foundational_ai'     => ['title'=>'Foundational — Artificial Intelligence',   'icon'=>'🤖', 'desc'=>'Generative AI, intelligent automation, machine learning.'],
    'foundational_devops' => ['title'=>'Foundational — Developer & Operations',    'icon'=>'⚙️', 'desc'=>'DevOps, CI/CD, release management, monitoring.'],
    'foundational_enduser'=> ['title'=>'Foundational — End User & Productivity',   'icon'=>'💻', 'desc'=>'M365/EA, end user devices, productivity suites.'],
    'foundational_svc'    => ['title'=>'Foundational — Service Management',        'icon'=>'🔄', 'desc'=>'IT service desk, software asset management, application portfolio.'],
    'foundational_infra'  => ['title'=>'Foundational — Infrastructure & Hosting',  'icon'=>'🖥', 'desc'=>'Cloud, servers, storage, network, connectivity.'],
    'integration'         => ['title'=>'Integration',                              'icon'=>'🔗', 'desc'=>'APIs, data pipelines, middleware, file transfer.'],
    'security'            => ['title'=>'Security',                                 'icon'=>'🔒', 'desc'=>'Vulnerability management, endpoint security, SOC, IAM, CCTV.'],
    'data_info'           => ['title'=>'Data & Information',                       'icon'=>'📊', 'desc'=>'BI & analytics, data science, document management, geospatial.'],
];

// ── Build WHERE clause ────────────────────────────────────────────────────
$where  = ['lgam_layer IS NOT NULL'];
$params = [];

if ($filter_layer) {
    $where[]  = 'lgam_layer = :layer';
    $params[':layer'] = $filter_layer;
}
if ($filter_status) {
    $where[]  = 'status = :status';
    $params[':status'] = $filter_status;
}
if ($filter_search) {
    $where[]  = '(title LIKE :q OR buyer_name LIKE :q2)';
    $params[':q']  = '%' . $filter_search . '%';
    $params[':q2'] = '%' . $filter_search . '%';
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ── Get layer counts ──────────────────────────────────────────────────────
$layer_counts_stmt = $pdo->prepare("
    SELECT lgam_layer, lgam_sublayer, lgam_sublayer_name,
           COUNT(*) AS n,
           SUM(COALESCE(value_amount,0)) AS tv,
           SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_n,
           SUM(CASE WHEN status IN ('planned','planning') THEN 1 ELSE 0 END) AS pipeline_n
    FROM ct_contracts
    {$whereSQL}
    GROUP BY lgam_layer, lgam_sublayer, lgam_sublayer_name
    ORDER BY lgam_layer, n DESC
");
$layer_counts_stmt->execute($params);
$sublayer_rows = $layer_counts_stmt->fetchAll();

// Group by layer
$layers = [];
foreach ($sublayer_rows as $row) {
    $lid = $row['lgam_layer'];
    if (!isset($layers[$lid])) {
        $layers[$lid] = ['n'=>0, 'tv'=>0, 'active'=>0, 'pipeline'=>0, 'sublayers'=>[]];
    }
    $layers[$lid]['n']        += $row['n'];
    $layers[$lid]['tv']       += $row['tv'];
    $layers[$lid]['active']   += $row['active_n'];
    $layers[$lid]['pipeline'] += $row['pipeline_n'];
    if ($row['lgam_sublayer']) {
        $layers[$lid]['sublayers'][$row['lgam_sublayer']] = [
            'name'     => $row['lgam_sublayer_name'] ?: $row['lgam_sublayer'],
            'n'        => $row['n'],
            'tv'       => $row['tv'],
            'active'   => $row['active_n'],
            'pipeline' => $row['pipeline_n'],
        ];
    }
}

// ── Get contracts for expanded layer/sublayer ─────────────────────────────
// We load contracts lazily via a separate query only when needed
function get_contracts(PDO $pdo, string $layer, ?string $sublayer, array $base_params): array {
    $extra_where = 'AND lgam_layer = :expand_layer';
    $p = array_merge($base_params, [':expand_layer' => $layer]);
    if ($sublayer) {
        $extra_where .= ' AND lgam_sublayer = :expand_sub';
        $p[':expand_sub'] = $sublayer;
    }
    $base_where = count($base_params) > 0
        ? str_replace('lgam_layer IS NOT NULL', 'lgam_layer IS NOT NULL ' . $extra_where, 'WHERE lgam_layer IS NOT NULL')
        : "WHERE lgam_layer IS NOT NULL {$extra_where}";

    $stmt = $pdo->prepare("
        SELECT ocid, title, buyer_name, status, value_amount, published_date,
               official_url, lgam_sublayer_name, region
        FROM ct_contracts
        WHERE lgam_layer IS NOT NULL {$extra_where}
        " . (array_key_exists(':status', $p) ? 'AND status = :status' : '') . "
        " . (array_key_exists(':q', $p) ? 'AND (title LIKE :q OR buyer_name LIKE :q2)' : '') . "
        ORDER BY published_date DESC
        LIMIT 200
    ");
    $stmt->execute($p);
    return $stmt->fetchAll();
}

// Which layer/sublayer is expanded?
$expand_layer = $_GET['expand'] ?? ($filter_layer ?: '');
$expand_sub   = $_GET['sub'] ?? '';

$expanded_contracts = [];
if ($expand_layer) {
    $expanded_contracts = get_contracts($pdo, $expand_layer, $expand_sub ?: null, $params);
}

$total_classified = array_sum(array_column(array_values($layers), 'n'));

layout_head('By architecture layer');
?>

<!-- Filter bar -->
<form method="get" action="/contracts/lgam.php" class="app-filter-bar">
  <div class="govuk-form-group">
    <label class="govuk-label" for="q">Search</label>
    <input class="govuk-input govuk-input--width-20" type="text" id="q" name="q"
           value="<?= htmlspecialchars($filter_search) ?>" placeholder="Title or buyer…">
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="status">Status</label>
    <select class="govuk-select" id="status" name="status">
      <option value="">All</option>
      <?php foreach (['complete'=>'Awarded','active'=>'Active tender','planned'=>'Pipeline','planning'=>'PME'] as $v=>$l): ?>
        <option value="<?= $v ?>"<?= $filter_status===$v?' selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="layer">Layer</label>
    <select class="govuk-select" id="layer" name="layer">
      <option value="">All layers</option>
      <?php foreach (LGAM_META as $lid => $meta): ?>
        <option value="<?= $lid ?>"<?= $filter_layer===$lid?' selected':'' ?>><?= $meta['title'] ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="govuk-button govuk-!-margin-bottom-0" type="submit">Filter</button>
  <?php if ($filter_layer || $filter_status || $filter_search): ?>
    <a href="/contracts/lgam.php" class="govuk-link govuk-!-margin-top-auto govuk-!-margin-bottom-2">Clear</a>
  <?php endif; ?>
</form>

<p class="govuk-body govuk-!-colour-secondary govuk-!-margin-bottom-6">
  <?= number_format($total_classified) ?> contracts mapped across <?= count($layers) ?> LGAM layers.
</p>

<?php foreach (LGAM_META as $lid => $meta):
  if (!isset($layers[$lid])) continue;
  $layer = $layers[$lid];
  $is_expanded = ($expand_layer === $lid);
  $body_id = 'layer-body-' . $lid;
?>
<div class="ct-layer">
  <button class="ct-layer__header" data-toggle="<?= $body_id ?>"
          onclick="window.location='/contracts/lgam.php?<?= http_build_query(array_merge($_GET, ['expand'=>$is_expanded?'':$lid,'sub'=>''])) ?>'">
    <span><?= $meta['icon'] ?> <?= htmlspecialchars($meta['title']) ?></span>
    <span class="ct-layer__header-right">
      <?php if ($layer['active']): ?>
        <span class="ct-tag ct-tag--active"><?= $layer['active'] ?> active</span>
      <?php endif; ?>
      <?php if ($layer['pipeline']): ?>
        <span class="ct-tag ct-tag--planned"><?= $layer['pipeline'] ?> pipeline</span>
      <?php endif; ?>
      <span class="ct-layer__value"><?= fmt_value($layer['tv']) ?></span>
      <span class="ct-layer__count"><?= number_format($layer['n']) ?></span>
      <span class="ct-layer__chevron <?= $is_expanded ? 'ct-layer__chevron--open' : '' ?>">▼</span>
    </span>
  </button>

  <div class="ct-layer__body <?= $is_expanded ? 'ct-layer__body--open' : '' ?>" id="<?= $body_id ?>">
    <p class="govuk-body-s govuk-!-colour-secondary"><?= htmlspecialchars($meta['desc']) ?></p>

    <?php if ($layer['sublayers']): ?>
    <!-- Sublayer summary -->
    <div class="govuk-grid-row govuk-!-margin-bottom-4">
      <?php foreach ($layer['sublayers'] as $sid => $sub): ?>
      <div class="govuk-grid-column-one-third govuk-!-margin-bottom-2">
        <a href="/contracts/lgam.php?<?= http_build_query(array_merge($_GET, ['expand'=>$lid,'sub'=>$sid])) ?>"
           class="govuk-link" style="text-decoration:none">
          <div style="border:1px solid #b1b4b6; padding:10px 12px; background:<?= $expand_sub===$sid?'#e8f1fb':'#fff' ?>">
            <div style="font-weight:700;font-size:0.9375rem"><?= htmlspecialchars($sub['name']) ?></div>
            <div style="font-size:0.875rem;color:#505a5f;margin-top:3px">
              <?= number_format($sub['n']) ?> contracts · <?= fmt_value($sub['tv']) ?>
              <?php if ($sub['active']): ?> · <span class="ct-tag ct-tag--active"><?= $sub['active'] ?> active</span><?php endif; ?>
            </div>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($is_expanded && $expanded_contracts): ?>
    <!-- Contract table -->
    <h3 class="govuk-heading-s">
      <?= $expand_sub && isset($layer['sublayers'][$expand_sub]) ? htmlspecialchars($layer['sublayers'][$expand_sub]['name']) : 'All sublayers' ?>
      (<?= count($expanded_contracts) ?><?= count($expanded_contracts)>=200 ? '+' : '' ?>)
    </h3>
    <table class="govuk-table">
      <thead class="govuk-table__head">
        <tr class="govuk-table__row">
          <th class="govuk-table__header">Title</th>
          <th class="govuk-table__header">Buyer</th>
          <th class="govuk-table__header">Sub-layer</th>
          <th class="govuk-table__header govuk-table__header--numeric">Value</th>
          <th class="govuk-table__header">Published</th>
          <th class="govuk-table__header">Status</th>
        </tr>
      </thead>
      <tbody class="govuk-table__body">
        <?php foreach ($expanded_contracts as $c): ?>
        <tr class="govuk-table__row">
          <td class="govuk-table__cell" style="max-width:280px">
            <?php if ($c['official_url']): ?>
              <a href="<?= htmlspecialchars($c['official_url']) ?>" class="govuk-link" target="_blank" rel="noopener"><?= htmlspecialchars($c['title'] ?? '—') ?></a>
            <?php else: ?>
              <?= htmlspecialchars($c['title'] ?? '—') ?>
            <?php endif; ?>
          </td>
          <td class="govuk-table__cell"><?= htmlspecialchars($c['buyer_name'] ?? '—') ?></td>
          <td class="govuk-table__cell"><?= htmlspecialchars($c['lgam_sublayer_name'] ?? '—') ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric"><?= fmt_value($c['value_amount']) ?></td>
          <td class="govuk-table__cell"><?= htmlspecialchars($c['published_date'] ?? '—') ?></td>
          <td class="govuk-table__cell"><?= status_tag($c['status'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php elseif ($is_expanded): ?>
      <p class="govuk-body govuk-!-colour-secondary">No contracts match the current filters.</p>
    <?php else: ?>
      <p class="govuk-body-s govuk-!-colour-secondary">
        Click the layer header to expand and see contracts, or click a sub-layer card above.
      </p>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php layout_foot(); ?>
