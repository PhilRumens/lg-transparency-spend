<?php
/**
 * lgam_classify.php — Products mapped to the LGAM.
 * Single unified list grouped by LGAM layer, styled per CDDO architecture colours.
 */
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$selected_node = $_GET['node'] ?? '';
$selected_product = $_GET['product'] ?? '';

// LGAM layers in architectural order (matching CDDO site), excluding Public Channels
$LAYERS = [
    'capabilities' => [
        'title' => 'Capabilities',
        'colour' => '#000080',
        'desc' => 'Shared capabilities that enable council services: forms, payments, booking, identity, workflow.',
        'nodes' => ['forms', 'workflow', 'payments', 'booking', 'identity', 'telephony', 'agentic-ai', 'email-cap', 'messaging-cap'],
    ],
    'business_areas' => [
        'title' => 'Business Areas',
        'colour' => '#0000FF',
        'desc' => 'Service-specific technology for each council function.',
        'nodes' => ['adult-care', 'childrens', 'democratic', 'education', 'highways', 'housing', 'leisure', 'licensing', 'planning', 'public-health', 'revenues', 'waste'],
    ],
    'corporate' => [
        'title' => 'Corporate Areas',
        'colour' => '#696969',
        'desc' => 'Cross-cutting corporate functions: HR, finance, CRM, GIS, legal.',
        'nodes' => ['biz-planning', 'comms', 'governance', 'crm', 'facilities', 'financial', 'geo', 'legal', 'hr', 'procurement'],
    ],
    'foundational' => [
        'title' => 'Foundational Technology',
        'colour' => '#008080',
        'desc' => 'AI, end-user productivity, service management, and infrastructure.',
        'nodes' => ['ai-enablement', 'conversational-ai', 'generative-ai', 'intelligent-auto', 'ml',
                    'release-mgmt', 'monitoring',
                    'unified-comms', 'end-user-devices', 'knowledge-mgmt', 'productivity',
                    'app-portfolio', 'it-ops', 'sw-asset',
                    'compute', 'connectivity', 'virtualisation'],
    ],
    'integration' => [
        'title' => 'Integration',
        'colour' => '#6B6B00',
        'desc' => 'APIs, data pipelines, middleware, file transfer.',
        'nodes' => ['api-mgmt', 'data-pipelines', 'event-streaming', 'file-transfer', 'integration-gov', 'middleware', 'b2b-integration'],
    ],
    'security' => [
        'title' => 'Security',
        'colour' => '#CC0000',
        'desc' => 'Vulnerability management, endpoint security, SOC, IAM, CCTV.',
        'nodes' => ['app-data-sec', 'iam', 'incident-forensics', 'network-sec', 'physical-sec', 'soc', 'vuln-mgmt'],
    ],
    'data_info' => [
        'title' => 'Data & Information',
        'colour' => '#800000',
        'desc' => 'BI & analytics, data science, document management, geospatial.',
        'nodes' => ['bi-analytics', 'data-catalogue', 'data-governance', 'data-science', 'data-storage', 'doc-records', 'enterprise-search', 'geospatial'],
    ],
];

// Node labels
$NODE_LABELS = [
    'forms' => 'Forms', 'workflow' => 'Workflow', 'payments' => 'Payments',
    'booking' => 'Booking', 'identity' => 'Identity', 'telephony' => 'Telephony & Fax',
    'agentic-ai' => 'Agentic AI', 'email-cap' => 'Email', 'messaging-cap' => 'Messaging',
    'adult-care' => 'Adult Social Care', 'childrens' => "Children's Social Care",
    'democratic' => 'Democratic Services', 'education' => 'Education',
    'highways' => 'Highways & Transport', 'housing' => 'Housing',
    'leisure' => 'Leisure & Culture', 'licensing' => 'Licensing & Regulation',
    'planning' => 'Planning & Development', 'public-health' => 'Public Health',
    'revenues' => 'Revenues & Benefits', 'waste' => 'Waste Management',
    'biz-planning' => 'Business Planning', 'comms' => 'Communications & PR',
    'governance' => 'Corporate Governance', 'crm' => 'Customer Relations',
    'facilities' => 'Facilities', 'financial' => 'Financial',
    'geo' => 'Geographical', 'legal' => 'Legal & Compliance',
    'hr' => 'HR & Workforce', 'procurement' => 'Procurement',
    'ai-enablement' => 'AI Enablement', 'conversational-ai' => 'Conversational AI',
    'generative-ai' => 'Generative AI', 'intelligent-auto' => 'Intelligent Automation',
    'ml' => 'Machine Learning', 'release-mgmt' => 'Release Management',
    'monitoring' => 'Monitoring', 'unified-comms' => 'Unified Communications',
    'end-user-devices' => 'End User Devices', 'knowledge-mgmt' => 'Knowledge Management',
    'productivity' => 'Productivity', 'app-portfolio' => 'Application Portfolio',
    'it-ops' => 'IT Operations', 'sw-asset' => 'Software Asset & Licence',
    'compute' => 'Compute & Storage', 'connectivity' => 'Connectivity',
    'virtualisation' => 'Virtualisation',
    'api-mgmt' => 'API Management', 'data-pipelines' => 'Data Pipelines',
    'event-streaming' => 'Event Streaming', 'file-transfer' => 'File Transfer',
    'integration-gov' => 'Integration Governance', 'middleware' => 'Middleware',
    'b2b-integration' => 'B2B Integration',
    'app-data-sec' => 'App & Data Security', 'iam' => 'Identity & Access Management',
    'incident-forensics' => 'Incident Forensics', 'network-sec' => 'Network Security',
    'physical-sec' => 'Physical Security', 'soc' => 'Security Operations',
    'vuln-mgmt' => 'Vulnerability Management',
    'bi-analytics' => 'BI & Analytics', 'data-catalogue' => 'Data Catalogue',
    'data-governance' => 'Data Governance', 'data-science' => 'Data Science',
    'data-storage' => 'Data Storage', 'doc-records' => 'Documents & Records',
    'enterprise-search' => 'Enterprise Search', 'geospatial' => 'Geospatial Data',
];

// Reverse lookup: node_id → layer key
$NODE_TO_LAYER = [];
foreach ($LAYERS as $lk => $ldef) {
    foreach ($ldef['nodes'] as $nid) {
        $NODE_TO_LAYER[$nid] = $lk;
    }
}

function fmt_lgam_value(float $v): string {
    if ($v >= 1e9) return '£' . number_format($v / 1e9, 1) . 'bn';
    if ($v >= 1e6) return '£' . number_format($v / 1e6, 1) . 'M';
    if ($v >= 1e3) return '£' . number_format($v / 1e3, 0) . 'k';
    return '£' . number_format($v, 0);
}

function get_layer_colour(string $node_id): string {
    global $NODE_TO_LAYER, $LAYERS;
    $lk = $NODE_TO_LAYER[$node_id] ?? '';
    return $LAYERS[$lk]['colour'] ?? '#1d70b8';
}

// ─── PRODUCT DETAIL VIEW ────────────────────────────────────────────────
if ($selected_product) {
    $prod = $pdo->prepare("SELECT * FROM ct_lgam_products WHERE id = ?");
    $prod->execute([$selected_product]);
    $product = $prod->fetch();
    if (!$product) { header('Location: /contracts/lgam_classify.php'); exit; }

    $caps = $pdo->prepare("SELECT node_id FROM ct_lgam_product_capabilities WHERE product_id = ?");
    $caps->execute([$selected_product]);
    $product_caps = $caps->fetchAll(PDO::FETCH_COLUMN);

    $areas = $pdo->prepare("SELECT node_id FROM ct_lgam_product_areas WHERE product_id = ?");
    $areas->execute([$selected_product]);
    $product_areas = $areas->fetchAll(PDO::FETCH_COLUMN);

    $entries = $pdo->prepare("
        SELECT e.council, e.contract_title, e.value_amount, e.start_date, e.end_date, e.department
        FROM ct_lgam_entry_products ep
        JOIN ct_contract_register_entries e ON e.id = ep.entry_id
        WHERE ep.product_id = ?
        ORDER BY e.value_amount DESC
    ");
    $entries->execute([$selected_product]);
    $entry_rows = $entries->fetchAll();

    $council_count = $pdo->prepare("SELECT COUNT(DISTINCT e.council) FROM ct_lgam_entry_products ep JOIN ct_contract_register_entries e ON e.id = ep.entry_id WHERE ep.product_id = ?");
    $council_count->execute([$selected_product]);
    $num_councils = $council_count->fetchColumn();

    $spend_stmt = $pdo->prepare("SELECT COUNT(*) as payments, COUNT(DISTINCT council) as spend_councils, SUM(amount) as total_spend FROM ct_transparency_spend WHERE supplier_canon = ? AND internal_provider = 0");
    $spend_stmt->execute([$product['supplier_canon']]);
    $spend = $spend_stmt->fetch();

    layout_head($product['display_name'] . ' – LGAM');
?>
<style>
.lgam-tag { display:inline-block; padding:3px 10px; margin:3px 4px 3px 0; font-size:0.8125rem; border-radius:3px; color:#fff; }
</style>

<p class="govuk-body"><a href="/contracts/lgam_classify.php" class="govuk-link">&larr; All products</a></p>
<h1 class="govuk-heading-l"><?= htmlspecialchars($product['display_name']) ?></h1>
<p class="govuk-body"><?= htmlspecialchars($product['description'] ?? '') ?></p>

<?php if ($product['company_number']): ?>
<p class="govuk-body-s" style="color:#505a5f">
    <a href="/contracts/supplier.php?id=<?= urlencode($product['company_number']) ?>" class="govuk-link"><?= htmlspecialchars($product['supplier_canon']) ?></a>
    · Companies House: <?= htmlspecialchars($product['company_number']) ?>
</p>
<?php endif; ?>

<div class="govuk-grid-row govuk-!-margin-bottom-6">
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= number_format($num_councils) ?></div><div class="ct-stat-card__label">Councils (contracts)</div></div></div>
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= count($entry_rows) ?></div><div class="ct-stat-card__label">Contract entries</div></div></div>
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= (int)$spend['spend_councils'] ?></div><div class="ct-stat-card__label">Councils (spend)</div></div></div>
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= $spend['total_spend'] ? fmt_lgam_value((float)$spend['total_spend']) : '—' ?></div><div class="ct-stat-card__label">Total spend tracked</div></div></div>
</div>

<h3 class="govuk-heading-s">LGAM mapping</h3>
<div class="govuk-!-margin-bottom-6">
<?php foreach (array_merge($product_caps, $product_areas) as $nid): ?>
  <a href="/contracts/lgam_classify.php?node=<?= urlencode($nid) ?>" class="lgam-tag govuk-link" style="text-decoration:none;background:<?= get_layer_colour($nid) ?>"><?= htmlspecialchars($NODE_LABELS[$nid] ?? $nid) ?></a>
<?php endforeach; ?>
<?php if (!$product_caps && !$product_areas): ?><span class="govuk-body-s" style="color:#505a5f">None mapped</span><?php endif; ?>
</div>

<?php if ($entry_rows): ?>
<h3 class="govuk-heading-m">Contract register entries (<?= count($entry_rows) ?>)</h3>
<table class="govuk-table" style="font-size:0.875rem">
  <thead class="govuk-table__head"><tr>
    <th class="govuk-table__header">Council</th>
    <th class="govuk-table__header">Contract</th>
    <th class="govuk-table__header">Department</th>
    <th class="govuk-table__header govuk-table__header--numeric">Value</th>
    <th class="govuk-table__header">End date</th>
  </tr></thead>
  <tbody class="govuk-table__body">
  <?php foreach ($entry_rows as $e): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><?= htmlspecialchars($e['council']) ?></td>
      <td class="govuk-table__cell" style="max-width:250px"><?= htmlspecialchars($e['contract_title'] ?? '—') ?></td>
      <td class="govuk-table__cell"><?= htmlspecialchars($e['department'] ?? '—') ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= $e['value_amount'] ? fmt_lgam_value((float)$e['value_amount']) : '—' ?></td>
      <td class="govuk-table__cell"><?= $e['end_date'] ? date('M Y', strtotime($e['end_date'])) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php layout_foot(true); exit; }

// ─── NODE DETAIL VIEW ───────────────────────────────────────────────────
if ($selected_node) {
    $node_label = $NODE_LABELS[$selected_node] ?? $selected_node;
    $layer_key = $NODE_TO_LAYER[$selected_node] ?? '';
    $layer_colour = $LAYERS[$layer_key]['colour'] ?? '#1d70b8';

    // Check if this node has application type taxonomy defined
    $app_types_stmt = $pdo->prepare("SELECT * FROM ct_lgam_app_types WHERE node_id = ? ORDER BY sort_order, id");
    $app_types_stmt->execute([$selected_node]);
    $app_types = $app_types_stmt->fetchAll();

    // Get products that have this node in EITHER capabilities or areas
    $products_stmt = $pdo->prepare("
        SELECT p.*,
            COUNT(DISTINCT ep.entry_id) as entries,
            COUNT(DISTINCT e.council) as councils,
            SUM(e.value_amount) as total_value
        FROM ct_lgam_products p
        LEFT JOIN ct_lgam_entry_products ep ON ep.product_id = p.id
        LEFT JOIN ct_contract_register_entries e ON e.id = ep.entry_id
        WHERE p.id IN (
            SELECT product_id FROM ct_lgam_product_capabilities WHERE node_id = ?
            UNION
            SELECT product_id FROM ct_lgam_product_areas WHERE node_id = ?
        )
        GROUP BY p.id
        ORDER BY councils DESC, entries DESC
    ");
    $products_stmt->execute([$selected_node, $selected_node]);
    $node_products = $products_stmt->fetchAll();

    layout_head($node_label . ' – LGAM');
?>
<style>
.product-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:16px; margin-bottom:32px; }
.product-card { background:#fff; border:1px solid #b1b4b6; border-left:4px solid; padding:16px; text-decoration:none; color:inherit; display:block; }
.product-card:hover { background:#f8f8f8; }
.product-card h3 { margin:0 0 4px; font-size:1rem; color:#0b0c0c; }
.product-card .supplier { font-size:0.8125rem; color:#505a5f; margin-bottom:8px; }
.product-card .stats { font-size:0.8125rem; color:#0b0c0c; }
.lgam-tag { display:inline-block; padding:2px 8px; margin:2px 3px 2px 0; font-size:0.75rem; border-radius:3px; color:#fff; }
/* App type taxonomy styles */
.app-type-section { margin-bottom:36px; border:1px solid #b1b4b6; border-top:4px solid; border-radius:2px; }
.app-type-section__header { padding:16px 20px 12px; background:#f8f8f8; border-bottom:1px solid #e8e8e8; }
.app-type-section__header h2 { margin:0 0 4px; font-size:1.0625rem; font-weight:700; color:#0b0c0c; }
.app-type-section__header p { margin:0; font-size:0.875rem; color:#505a5f; }
.app-type-section__body { padding:16px 20px; }
.market-share-table { width:100%; border-collapse:collapse; font-size:0.875rem; }
.market-share-table th { text-align:left; padding:6px 8px; border-bottom:2px solid #b1b4b6; font-weight:600; font-size:0.8125rem; color:#505a5f; text-transform:uppercase; letter-spacing:0.05em; white-space:nowrap; }
.market-share-table th.num { text-align:right; }
.market-share-table td { padding:8px 8px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.market-share-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
.market-share-table tr:last-child td { border-bottom:none; }
.market-share-table tr:hover td { background:#f8f8f8; }
.bar-cell { width:160px; }
.bar-wrap { background:#e8e8e8; border-radius:2px; height:12px; position:relative; overflow:hidden; }
.bar-fill { height:100%; border-radius:2px; transition:width 0.3s ease; }
.pct-label { font-size:0.75rem; color:#505a5f; margin-left:6px; white-space:nowrap; }
.product-name-link { color:#1d70b8; text-decoration:underline; font-weight:500; }
.product-name-link:hover { color:#003078; }
.empty-type { padding:12px 0; color:#505a5f; font-size:0.875rem; font-style:italic; }
.uncategorised-section { margin-top:8px; }
</style>

<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px">
  <h1 class="govuk-heading-l" style="border-left:5px solid <?= $layer_colour ?>;padding-left:15px;margin-bottom:0"><?= htmlspecialchars($node_label) ?></h1>
  <a href="/contracts/lgam_classify.php" class="govuk-link govuk-body" style="white-space:nowrap;flex-shrink:0">&larr; All layers</a>
</div>
<p class="govuk-body"><?= count($node_products) ?> product<?= count($node_products) !== 1 ? 's' : '' ?> mapped to this category.</p>

<?php if ($app_types):
    // ── Grouped by application type ─────────────────────────────────────

    // Build a product index keyed by product id for quick lookup
    $product_index = [];
    foreach ($node_products as $p) {
        $product_index[$p['id']] = $p;
    }

    // For each app type, fetch the products mapped to it (in this node)
    // plus compute market share within the type
    $app_type_products = []; // app_type_id => [products]
    foreach ($app_types as $at) {
        $stmt = $pdo->prepare("
            SELECT p.*,
                COUNT(DISTINCT ep.entry_id) as entries,
                COUNT(DISTINCT e.council) as councils,
                SUM(e.value_amount) as total_value
            FROM ct_lgam_product_app_types pat
            JOIN ct_lgam_products p ON p.id = pat.product_id
            LEFT JOIN ct_lgam_entry_products ep ON ep.product_id = p.id
            LEFT JOIN ct_contract_register_entries e ON e.id = ep.entry_id
            WHERE pat.app_type_id = ?
            GROUP BY p.id
            ORDER BY councils DESC, entries DESC
        ");
        $stmt->execute([$at['id']]);
        $app_type_products[$at['id']] = $stmt->fetchAll();
    }

    // Find products NOT mapped to any app type in this node
    $all_typed_product_ids = [];
    foreach ($app_type_products as $plist) {
        foreach ($plist as $p) {
            $all_typed_product_ids[$p['id']] = true;
        }
    }
    $uncategorised = array_filter($node_products, fn($p) => !isset($all_typed_product_ids[$p['id']]));

    foreach ($app_types as $at):
        $type_products = $app_type_products[$at['id']];
        $type_total_councils = array_sum(array_column($type_products, 'councils'));
        $max_councils = $type_products ? max(array_column($type_products, 'councils')) : 0;
?>
<div class="app-type-section" style="border-top-color:<?= $layer_colour ?>">
  <div class="app-type-section__header">
    <h2><?= htmlspecialchars($at['label']) ?></h2>
    <?php if ($at['description']): ?><p><?= htmlspecialchars($at['description']) ?></p><?php endif; ?>
  </div>
  <div class="app-type-section__body">
<?php if ($type_products): ?>
    <table class="market-share-table">
      <thead>
        <tr>
          <th>Product</th>
          <th>Supplier</th>
          <th class="num">Councils</th>
          <th class="num">Contracts</th>
          <th class="num">Total value</th>
          <th>Market share</th>
        </tr>
      </thead>
      <tbody>
<?php   foreach ($type_products as $p):
            $councils = (int)$p['councils'];
            $pct = $type_total_councils > 0 ? round($councils / $type_total_councils * 100) : 0;
            $bar_pct = $max_councils > 0 ? round($councils / $max_councils * 100) : 0;
?>
        <tr>
          <td><a href="?product=<?= $p['id'] ?>" class="product-name-link"><?= htmlspecialchars($p['display_name']) ?></a></td>
          <td><?= htmlspecialchars($p['vendor'] ?? $p['supplier_canon']) ?></td>
          <td class="num"><?= $councils ?></td>
          <td class="num"><?= (int)$p['entries'] ?></td>
          <td class="num"><?= (float)$p['total_value'] > 0 ? fmt_lgam_value((float)$p['total_value']) : '—' ?></td>
          <td class="bar-cell">
            <div style="display:flex;align-items:center;">
              <div class="bar-wrap" style="flex:1">
                <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $layer_colour ?>"></div>
              </div>
              <span class="pct-label"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
<?php   endforeach; ?>
      </tbody>
    </table>
<?php else: ?>
    <p class="empty-type">No products yet mapped to this application type.</p>
<?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if ($uncategorised): ?>
<div class="app-type-section uncategorised-section" style="border-top-color:#b1b4b6">
  <div class="app-type-section__header">
    <h2 style="color:#505a5f">Uncategorised</h2>
    <p>Products mapped to this area but not yet assigned to an application type.</p>
  </div>
  <div class="app-type-section__body">
    <div class="product-grid" style="margin-bottom:0">
<?php   foreach ($uncategorised as $p):
            $pcaps = $pdo->prepare("SELECT node_id FROM ct_lgam_product_capabilities WHERE product_id = ?");
            $pcaps->execute([$p['id']]);
            $pcap_ids = $pcaps->fetchAll(PDO::FETCH_COLUMN);
            $pareas = $pdo->prepare("SELECT node_id FROM ct_lgam_product_areas WHERE product_id = ?");
            $pareas->execute([$p['id']]);
            $parea_ids = $pareas->fetchAll(PDO::FETCH_COLUMN);
?>
      <a href="?product=<?= $p['id'] ?>" class="product-card govuk-link" style="border-left-color:#b1b4b6">
        <h3><?= htmlspecialchars($p['display_name']) ?></h3>
        <div class="supplier"><?= htmlspecialchars($p['supplier_canon']) ?></div>
        <div class="stats">
          <?= (int)$p['councils'] ?> council<?= (int)$p['councils'] !== 1 ? 's' : '' ?>
          · <?= (int)$p['entries'] ?> contract<?= (int)$p['entries'] !== 1 ? 's' : '' ?>
          <?php if ((float)$p['total_value'] > 0): ?> · <?= fmt_lgam_value((float)$p['total_value']) ?><?php endif; ?>
        </div>
      </a>
<?php   endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php else:
    // ── Flat list (no app types defined) ────────────────────────────────
?>
<div class="product-grid">
<?php foreach ($node_products as $p):
    $pcaps = $pdo->prepare("SELECT node_id FROM ct_lgam_product_capabilities WHERE product_id = ?");
    $pcaps->execute([$p['id']]);
    $pcap_ids = $pcaps->fetchAll(PDO::FETCH_COLUMN);
    $pareas = $pdo->prepare("SELECT node_id FROM ct_lgam_product_areas WHERE product_id = ?");
    $pareas->execute([$p['id']]);
    $parea_ids = $pareas->fetchAll(PDO::FETCH_COLUMN);
?>
  <a href="?product=<?= $p['id'] ?>" class="product-card govuk-link" style="border-left-color:<?= $layer_colour ?>">
    <h3><?= htmlspecialchars($p['display_name']) ?></h3>
    <div class="supplier"><?= htmlspecialchars($p['supplier_canon']) ?></div>
    <div class="stats">
      <?= (int)$p['councils'] ?> council<?= (int)$p['councils'] !== 1 ? 's' : '' ?>
      · <?= (int)$p['entries'] ?> contract<?= (int)$p['entries'] !== 1 ? 's' : '' ?>
      <?php if ((float)$p['total_value'] > 0): ?> · <?= fmt_lgam_value((float)$p['total_value']) ?><?php endif; ?>
    </div>
    <div style="margin-top:8px">
      <?php foreach ($pcap_ids as $c): ?>
        <span class="lgam-tag" style="background:<?= get_layer_colour($c) ?>"><?= htmlspecialchars($NODE_LABELS[$c] ?? $c) ?></span>
      <?php endforeach; ?>
      <?php foreach ($parea_ids as $a): ?>
        <span class="lgam-tag" style="background:<?= get_layer_colour($a) ?>"><?= htmlspecialchars($NODE_LABELS[$a] ?? $a) ?></span>
      <?php endforeach; ?>
    </div>
  </a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php layout_foot(true); exit; }

// ─── OVERVIEW: single unified list of all layers ────────────────────────
// En-dash in the title suppresses _layout.php's auto-<h1> (see the
// str_contains($title,'–') guard) — this page renders its own <h1> below.
layout_head('Products by LGAM Layer – Experimental');
?>
<style>
.lgam-layer { margin-bottom:32px; }
.lgam-layer__heading { display:flex; align-items:center; gap:12px; margin-bottom:4px; }
.lgam-layer__bar { width:6px; height:28px; border-radius:3px; }
.lgam-layer__title { font-size:1.25rem; font-weight:700; color:#0b0c0c; margin:0; }
.lgam-layer__desc { font-size:0.875rem; color:#505a5f; margin:0 0 16px 18px; }
.lgam-node-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:10px; margin-left:18px; }
.lgam-node-card { background:#fff; border:1px solid #b1b4b6; border-left:4px solid; padding:12px 14px; text-decoration:none; color:inherit; display:block; }
.lgam-node-card:hover { background:#f8f8f8; border-color:#505a5f; }
.lgam-node-card h3 { margin:0 0 4px; font-size:0.9375rem; color:#0b0c0c; }
.lgam-node-card .stats { font-size:0.8125rem; color:#505a5f; }
</style>

<h1 class="govuk-heading-l">Products by LGAM Layer</h1>
<p class="govuk-body">Technology products used by councils, mapped to the <a href="https://architecture.cddo.cabinetoffice.gov.uk/gds-local/" class="govuk-link" target="_blank" rel="noopener">Local Government Application Map</a>. Derived from contract register entries across 46 councils.</p>

<?php
$total_products  = $pdo->query("SELECT COUNT(*) FROM ct_lgam_products")->fetchColumn();
$total_links     = $pdo->query("SELECT COUNT(*) FROM ct_lgam_entry_products")->fetchColumn();
$total_suppliers = $pdo->query("SELECT COUNT(DISTINCT supplier_canon) FROM ct_lgam_products")->fetchColumn();
$total_payments  = $pdo->query("SELECT COUNT(*) FROM ct_transparency_spend ts JOIN ct_lgam_products lp ON lp.supplier_canon = ts.supplier_canon WHERE ts.internal_provider = 0")->fetchColumn();
$total_contracts = $pdo->query("SELECT COUNT(DISTINCT e.id) FROM ct_lgam_entry_products ep JOIN ct_contract_register_entries e ON e.id = ep.entry_id")->fetchColumn();
?>
<div class="govuk-grid-row govuk-!-margin-bottom-6">
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= number_format($total_products) ?></div><div class="ct-stat-card__label">Products mapped</div></div></div>
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= number_format($total_suppliers) ?></div><div class="ct-stat-card__label">Suppliers</div></div></div>
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= number_format($total_payments) ?></div><div class="ct-stat-card__label">Payments included</div></div></div>
  <div class="govuk-grid-column-one-quarter"><div class="ct-stat-card"><div class="ct-stat-card__number"><?= number_format($total_contracts) ?></div><div class="ct-stat-card__label">Contracts included</div></div></div>
</div>

<?php foreach ($LAYERS as $layer_key => $layer):
    $colour = $layer['colour'];
    // Get node stats for this layer
    $node_stats = [];
    foreach ($layer['nodes'] as $nid) {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as products, COUNT(DISTINCT ep.entry_id) as entries
            FROM ct_lgam_products p
            LEFT JOIN ct_lgam_entry_products ep ON ep.product_id = p.id
            WHERE p.id IN (
                SELECT product_id FROM ct_lgam_product_capabilities WHERE node_id = ?
                UNION
                SELECT product_id FROM ct_lgam_product_areas WHERE node_id = ?
            )
        ");
        $stmt->execute([$nid, $nid]);
        $row = $stmt->fetch();
        if ((int)$row['products'] > 0) {
            $node_stats[$nid] = $row;
        }
    }
    if (!$node_stats) continue;
?>
<div class="lgam-layer">
  <div class="lgam-layer__heading">
    <div class="lgam-layer__bar" style="background:<?= $colour ?>"></div>
    <h2 class="lgam-layer__title"><?= htmlspecialchars($layer['title']) ?></h2>
  </div>
  <p class="lgam-layer__desc"><?= htmlspecialchars($layer['desc']) ?></p>
  <div class="lgam-node-grid">
  <?php foreach ($node_stats as $nid => $ns): ?>
    <a href="?node=<?= urlencode($nid) ?>" class="lgam-node-card govuk-link" style="border-left-color:<?= $colour ?>">
      <h3><?= htmlspecialchars($NODE_LABELS[$nid] ?? $nid) ?></h3>
      <div class="stats"><?= (int)$ns['products'] ?> product<?= (int)$ns['products'] !== 1 ? 's' : '' ?> · <?= (int)$ns['entries'] ?> contract<?= (int)$ns['entries'] !== 1 ? 's' : '' ?></div>
    </a>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php layout_foot(true); ?>
