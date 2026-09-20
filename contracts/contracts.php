<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();

// ── CPV division labels ───────────────────────────────────────────────────
const CPV_LABELS = [
    '03'=>'Agriculture & Forestry','09'=>'Petroleum Products','14'=>'Mining',
    '15'=>'Food & Drink','16'=>'Agricultural Machinery','18'=>'Clothing',
    '19'=>'Leather Goods','22'=>'Printed Matter','24'=>'Chemicals',
    '30'=>'IT Hardware','31'=>'Electrical Equipment','32'=>'Communications Equipment',
    '33'=>'Medical Equipment','34'=>'Transport Equipment','35'=>'Security Equipment',
    '37'=>'Musical & Sport','38'=>'Lab Equipment','39'=>'Furniture',
    '41'=>'Water','42'=>'Industrial Machinery','43'=>'Mining Machinery',
    '44'=>'Construction Materials','45'=>'Construction Works','48'=>'Software',
    '50'=>'Repair & Maintenance','51'=>'Installation','55'=>'Hotel & Catering',
    '60'=>'Transport Services','63'=>'Transport Support','64'=>'Post & Telecom',
    '65'=>'Utilities','66'=>'Financial Services','70'=>'Real Estate',
    '71'=>'Architecture & Engineering','72'=>'IT Services','73'=>'R&D',
    '75'=>'Public Administration','76'=>'Oil & Gas','77'=>'Agricultural Services',
    '79'=>'Business Services','80'=>'Education','85'=>'Health & Social Care',
    '90'=>'Waste & Environment','92'=>'Recreation & Culture','98'=>'Other Community Services',
];

// ── Filter params ─────────────────────────────────────────────────────────
$q         = trim($_GET['q']       ?? '');
$status    = $_GET['status']       ?? '';
$stage     = $_GET['stage']        ?? '';
$cpv       = $_GET['cpv']          ?? '';
$source    = $_GET['source']       ?? '';
$buyer     = trim($_GET['buyer']    ?? '');
$supplier  = trim($_GET['supplier'] ?? '');
$lgam      = $_GET['lgam']         ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 50;
$offset    = ($page - 1) * $per_page;

// ── Build WHERE ───────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($q) {
    // Use FULLTEXT search (with LIKE fallback for very short terms)
    if (strlen($q) >= 3) {
        $ft_q = "+" . preg_replace("/[^a-zA-Z0-9 ]/", "", $q) . "*";
        $where[]       = "MATCH(title, buyer_name, description) AGAINST(:q IN BOOLEAN MODE)";
        $params[":q"] = $ft_q;
    } else {
        $where[]        = "(title LIKE :q OR buyer_name LIKE :q2 OR description LIKE :q3)";
        $params[":q"]  = "%" . $q . "%";
        $params[":q2"] = "%" . $q . "%";
        $params[":q3"] = "%" . $q . "%";
    }
}
if ($status) {
    $where[]           = 'status = :status';
    $params[':status'] = $status;
}
if ($stage) {
    $where[]          = 'stage = :stage';
    $params[':stage'] = $stage;
}
if ($cpv) {
    $where[]        = 'cpv_division = :cpv';
    $params[':cpv'] = $cpv;
}
if ($source) {
    $where[]           = 'source = :source';
    $params[':source'] = $source;
}
if ($buyer) {
    $where[]          = 'buyer_name LIKE :buyer';
    $params[':buyer'] = '%' . $buyer . '%';
}
if ($supplier) {
    // Build OR query matching all known name variants for this canonical supplier
    // Map canonical name → all raw variants that normalise to it
    $supplier_variants = [$supplier]; // always include the search term itself
    // Common variants to check
    $variant_map = [
        'Civica'               => ['Civica UK Limited','CIVICA UK LIMITED','Civica UK Ltd','Civica UK ltd','Civica Uk Ltd','Civica UK LTD','CIVICA UK LTD','Civica UK limited','Civica UK Ltd.','Civica UK Limmited','Civica UK Litd','Civica Group Ltd','Civica Ltd','Civica Limited','CIVICA LIMITED','Civica Xpress','Civica Election Services','Civica Elections Services','Civica'],
        'Phoenix Software'     => ['Phoenix Software Ltd','Phoenix Software Limited','PHOENIX SOFTWARE LIMITED','PHOENIX SOFTWARE LTD','Phoenix Software Ltd.','Phoenix Software'],
        'Softcat'              => ['Softcat Plc','Softcat plc','Softcat PLC','SOFTCAT PLC','SOFTCAT LTD','Softcat Ltd','Softcat'],
        'Idox Software'        => ['Idox Software Ltd','Idox Software Limited','IDOX Software Ltd','Idox Software'],
        'NEC Software Solutions'=> ['NEC Software Solutions UK Limited','NEC Software Solutions UK Ltd','NEC Software Solutions Ltd','NEC Software Solutions Limited','NEC Software Solutions'],
        'The Access Group'     => ['Access UK Limited','Access UK Ltd','ACCESS UK LIMITED','ACCESS UK LTD','The Access Group','Access Group'],
        'Insight Direct'       => ['Insight Direct (UK) Ltd','Insight Direct (UK) Limited','INSIGHT DIRECT (UK) LTD','INSIGHT DIRECT (GB) LIMITED','Insight Direct (Uk) Limited','Insight Direct UK'],
        'Bytes'                => ['Bytes Software Services','Bytes Software Services Limited','Bytes Software Services Ltd','BYTES SOFTWARE SERVICES LIMITED'],
        'SCC'                  => ['Specialist Computer Centres PLC','SPECIALIST COMPUTER CENTRES PLC','Specialist Computer Centres (SCC) Plc','Specialist Computer Centres Plc','Specialist Computer Centres'],
        'XMA'                  => ['XMA Limited','XMA Ltd','XMA LIMITED','XMA LTD'],
        'Dell'                 => ['Dell Corporation Limited','Dell Corporation Ltd','DELL CORPORATION LIMITED','DELL CORPORATION LTD'],
        'CDW'                  => ['CDW Limited','CDW Ltd','CDW INTERNATIONAL LIMITED'],
        'Oracle'               => ['Oracle Corporation UK Limited','Oracle Corporation Uk Limited','ORACLE CORPORATION UK LIMITED'],
        'Capita'               => ['Capita Business Services Limited','Capita Business Services Ltd'],
        'BT'                   => ['British Telecommunications PLC','British Telecommunications plc','BRITISH TELECOMMUNICATIONS PLC','British Telecommunication PLC'],
        'Virgin Media Business'=> ['Virgin Media Business Limited','Virgin Media Business Ltd','Virgin Media Business'],
        'Liquidlogic'          => ['Liquidlogic Limited','Liquidlogic Ltd','Liquidlogic','Liquid Logic'],
        'Unit4'                => ['Unit4 Business Software Limited','Unit4 Business Software Ltd','Unit 4 Business Software Limited','Unit4 Business Software'],
        'Boxxe'                => ['Boxxe Limited','Boxxe Ltd','BOXXE LIMITED'],
        'Granicus-Firmstep'    => ['Granicus-Firmstep Limited','Granicus-Firmstep Ltd','Granicus-Firmstep'],
        'MRI Software'         => ['MRI Software Limited','MRI SOFTWARE LIMITED'],
        'Trustmarque'          => ['Trustmarque Solutions Limited','Trustmarque Solutions Ltd','TRUSTMARQUE SOLUTIONS LTD','Trustmarque'],
        'Causeway Technologies'=> ['Causeway Technologies Limited','Causeway Technologies Ltd'],
        'Symology'             => ['Symology Ltd','Symology Limited'],
        'Netcall'              => ['Netcall Technology Limited','Netcall UK Limited'],
        'Brightly Software'    => ['Brightly Software Limited','Brightly Software Ltd'],
        'CACI'                 => ['CACI Limited','Caci Limited'],
        'Esri UK'              => ['ESRI (UK) Limited','Esri (UK) Ltd'],
        'Agilisys'             => ['Agilisys Limited','Agilisys'],
        'Microsoft'            => ['Microsoft Limited','Microsoft'],
        'Vodafone'             => ['Vodafone Limited','Vodafone'],
        'SAP'                  => ['SAP (UK) Ltd','SAP (UK) Limited'],
        'Computacenter'        => ['Computacenter (UK) Ltd','Computacenter UK Limited'],
        'Totalmobile'          => ['Totalmobile Ltd','Totalmobile Limited','TotalMobile Limited','Total Mobile Ltd'],
        'Zellis'               => ['Zellis UK Limited','Zellis UK Ltd'],
        'Arcus Global'         => ['Arcus Global Limited','Arcus Global Ltd'],
        'Beam Up'              => ['Beam Up Ltd','Beam Up Limited'],
    ];
    // Find canonical match (case-insensitive)
    $supplier_lower = strtolower($supplier);
    foreach ($variant_map as $canon => $variants) {
        if (strtolower($canon) === $supplier_lower) {
            $supplier_variants = $variants;
            break;
        }
    }
    // Use MATCH...AGAINST with quoted phrases for each variant
    $or_parts = [];
    foreach ($supplier_variants as $idx => $variant) {
        $key = ":supplier_v" . $idx;
        $or_parts[] = "MATCH(supplier_names) AGAINST(" . $key . " IN BOOLEAN MODE)";
        $params[$key] = "\"" . $variant . "\"";
    }
    $where[] = "(" . implode(" OR ", $or_parts) . ")";
}
if ($lgam === 'classified') {
    $where[] = 'lgam_layer IS NOT NULL';
} elseif ($lgam === 'unclassified') {
    $where[] = 'lgam_layer IS NULL';
}

$whereSQL = implode(' AND ', $where);

// ── Count ─────────────────────────────────────────────────────────────────
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ct_contracts WHERE {$whereSQL}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

// ── Results ───────────────────────────────────────────────────────────────
$dataStmt = $pdo->prepare("
    SELECT ocid, title, buyer_name, status, stage, value_amount,
           published_date, tender_end_date, official_url, source,
           cpv_division, lgam_sublayer_name, supplier_names
    FROM ct_contracts
    WHERE {$whereSQL}
    ORDER BY published_date DESC
    LIMIT {$per_page} OFFSET {$offset}
");
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll();

// ── CPV counts for filter sidebar ─────────────────────────────────────────
$cpvCounts = $pdo->query("
    SELECT cpv_division, COUNT(*) AS n
    FROM ct_contracts
    WHERE cpv_division IS NOT NULL
    GROUP BY cpv_division
    ORDER BY n DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Buyer autocomplete list (top 50) ──────────────────────────────────────
$topBuyers = $pdo->query("
    SELECT buyer_name, COUNT(*) AS n
    FROM ct_contracts
    GROUP BY buyer_name
    ORDER BY n DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_COLUMN);

// ── Build pagination URL helper ───────────────────────────────────────────
function page_url(int $p): string {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}

layout_head('All contracts');
?>

<style>
.ct-contracts-grid { display:grid; grid-template-columns:220px 1fr; gap:24px; align-items:start; }
.ct-contracts-grid__main { min-width:0; }
@media (max-width:640px) { .ct-contracts-grid { grid-template-columns:1fr; } }
</style>

<div class="ct-contracts-grid">

<!-- ── Sidebar filters ─────────────────────────────────────────────────── -->
<aside>
  <form method="get" action="/contracts/contracts.php">

    <div class="govuk-form-group">
      <label class="govuk-label govuk-!-font-weight-bold" for="q">Search</label>
      <input class="govuk-input" type="text" id="q" name="q"
             value="<?= htmlspecialchars($q) ?>" placeholder="Title, buyer, description…">
    </div>

    <div class="govuk-form-group">
      <label class="govuk-label govuk-!-font-weight-bold" for="buyer">Buyer</label>
      <input class="govuk-input" type="text" id="buyer" name="buyer"
             value="<?= htmlspecialchars($buyer) ?>" list="buyer-list" placeholder="Council name…">
      <datalist id="buyer-list">
        <?php foreach ($topBuyers as $b): ?>
          <option value="<?= htmlspecialchars($b) ?>">
        <?php endforeach; ?>
      </datalist>
    </div>

    <div class="govuk-form-group">
      <label class="govuk-label govuk-!-font-weight-bold" for="status">Status</label>
      <select class="govuk-select" id="status" name="status" style="width:100%">
        <option value="">All</option>
        <?php foreach (['complete'=>'Awarded','active'=>'Active tender','planned'=>'Pipeline','planning'=>'PME','cancelled'=>'Cancelled'] as $v=>$l): ?>
          <option value="<?= $v ?>"<?= $status===$v?' selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="govuk-form-group">
      <label class="govuk-label govuk-!-font-weight-bold" for="stage">Stage</label>
      <select class="govuk-select" id="stage" name="stage" style="width:100%">
        <option value="">All</option>
        <?php foreach (['award'=>'Award','tender'=>'Tender','planning'=>'Planning'] as $v=>$l): ?>
          <option value="<?= $v ?>"<?= $stage===$v?' selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="govuk-form-group">
      <label class="govuk-label govuk-!-font-weight-bold" for="source">Source</label>
      <select class="govuk-select" id="source" name="source" style="width:100%">
        <option value="">All</option>
        <option value="contracts_finder"<?= $source==='contracts_finder'?' selected':'' ?>>Contracts Finder</option>
        <option value="fts"<?= $source==='fts'?' selected':'' ?>>Find a Tender</option>
      </select>
    </div>

    <div class="govuk-form-group">
      <label class="govuk-label govuk-!-font-weight-bold" for="lgam">LGAM</label>
      <select class="govuk-select" id="lgam" name="lgam" style="width:100%">
        <option value="">All</option>
        <option value="classified"<?= $lgam==='classified'?' selected':'' ?>>Classified only</option>
        <option value="unclassified"<?= $lgam==='unclassified'?' selected':'' ?>>Unclassified only</option>
      </select>
    </div>

    <div class="govuk-form-group">
      <label class="govuk-label govuk-!-font-weight-bold">CPV category</label>
      <?php foreach ($cpvCounts as $div => $n):
        $label = CPV_LABELS[$div] ?? "CPV {$div}";
      ?>
      <div>
        <label style="display:flex;align-items:center;gap:6px;font-size:0.875rem;padding:2px 0;cursor:pointer">
          <input type="radio" name="cpv" value="<?= $div ?>"<?= $cpv===$div?' checked':'' ?>>
          <?= htmlspecialchars($label) ?>
          <span style="color:#6f777b;margin-left:auto"><?= number_format($n) ?></span>
        </label>
      </div>
      <?php endforeach; ?>
      <?php if ($cpv): ?>
        <div style="margin-top:4px">
          <a href="<?= htmlspecialchars(page_url(1)) ?>&cpv=" class="govuk-link" style="font-size:0.875rem">Clear CPV filter</a>
        </div>
      <?php endif; ?>
    </div>

    <button class="govuk-button govuk-button--secondary" type="submit" style="width:100%">Apply filters</button>
    <?php if (array_filter([$q,$status,$stage,$cpv,$source,$buyer,$supplier,$lgam])): ?>
      <a href="/contracts/contracts.php" class="govuk-link" style="display:block;text-align:center;font-size:0.875rem">Clear all filters</a>
    <?php endif; ?>
  </form>
</aside>

<!-- ── Main results ────────────────────────────────────────────────────── -->
<div class="ct-contracts-grid__main">
  <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
    <p class="govuk-body govuk-!-margin-bottom-0">
      <strong><?= number_format($total) ?></strong> contract<?= $total !== 1 ? 's' : '' ?>
      <?php if ($q || $buyer || $status || $cpv || $source || $lgam): ?>
        matching current filters
      <?php endif; ?>
    </p>
    <p class="govuk-body-s govuk-!-colour-secondary govuk-!-margin-bottom-0">
      Page <?= $page ?> of <?= $total_pages ?>
    </p>
  </div>

  <?php if (empty($rows)): ?>
    <p class="govuk-body govuk-!-colour-secondary">No contracts match the current filters.</p>
  <?php else: ?>

  <table class="govuk-table" style="font-size:0.875rem">
    <thead class="govuk-table__head">
      <tr class="govuk-table__row">
        <th class="govuk-table__header" style="width:35%">Title</th>
        <th class="govuk-table__header">Buyer</th>
        <th class="govuk-table__header">CPV</th>
        <th class="govuk-table__header">LGAM</th>
        <th class="govuk-table__header govuk-table__header--numeric">Value</th>
        <th class="govuk-table__header">Published</th>
        <th class="govuk-table__header">Status</th>
      </tr>
    </thead>
    <tbody class="govuk-table__body">
      <?php foreach ($rows as $row): ?>
      <tr class="govuk-table__row">
        <td class="govuk-table__cell" style="max-width:0;overflow:hidden">
          <?php if ($row['official_url']): ?>
            <a href="<?= htmlspecialchars($row['official_url']) ?>" class="govuk-link"
               target="_blank" rel="noopener" title="<?= htmlspecialchars($row['title'] ?? '') ?>">
              <?= htmlspecialchars(mb_strimwidth($row['title'] ?? '—', 0, 80, '…')) ?>
            </a>
          <?php else: ?>
            <span title="<?= htmlspecialchars($row['title'] ?? '') ?>">
              <?= htmlspecialchars(mb_strimwidth($row['title'] ?? '—', 0, 80, '…')) ?>
            </span>
          <?php endif; ?>
          <?php if ($row['supplier_names']): ?>
            <div style="color:#6f777b;font-size:0.8rem;margin-top:2px">
              → <?= htmlspecialchars(mb_strimwidth($row['supplier_names'], 0, 60, '…')) ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="govuk-table__cell" style="font-size:0.8rem">
          <?= htmlspecialchars(mb_strimwidth($row['buyer_name'] ?? '—', 0, 40, '…')) ?>
        </td>
        <td class="govuk-table__cell" style="font-size:0.8rem;white-space:nowrap">
          <?php $div = $row['cpv_division'] ?? null; ?>
          <?php if ($div): ?>
            <abbr title="<?= htmlspecialchars(CPV_LABELS[$div] ?? '') ?>"><?= $div ?></abbr>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="govuk-table__cell" style="font-size:0.8rem">
          <?= htmlspecialchars($row['lgam_sublayer_name'] ?? '—') ?>
        </td>
        <td class="govuk-table__cell govuk-table__cell--numeric" style="white-space:nowrap">
          <?= fmt_value($row['value_amount']) ?>
        </td>
        <td class="govuk-table__cell" style="white-space:nowrap">
          <?= htmlspecialchars($row['published_date'] ?? '—') ?>
        </td>
        <td class="govuk-table__cell">
          <?= status_tag($row['status'] ?? '') ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <nav class="govuk-!-margin-top-4" aria-label="Pagination">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?php if ($page > 1): ?>
        <a href="<?= htmlspecialchars(page_url($page - 1)) ?>" class="govuk-link">← Previous</a>
      <?php endif; ?>

      <?php
      $start = max(1, $page - 3);
      $end   = min($total_pages, $page + 3);
      for ($p = $start; $p <= $end; $p++):
      ?>
        <?php if ($p === $page): ?>
          <strong style="padding:4px 8px;background:#1d70b8;color:#fff;border-radius:3px"><?= $p ?></strong>
        <?php else: ?>
          <a href="<?= htmlspecialchars(page_url($p)) ?>" class="govuk-link" style="padding:4px 8px"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($page < $total_pages): ?>
        <a href="<?= htmlspecialchars(page_url($page + 1)) ?>" class="govuk-link">Next →</a>
      <?php endif; ?>

      <span class="govuk-body-s govuk-!-colour-secondary" style="margin-left:8px">
        <?= number_format($total) ?> total · <?= $per_page ?> per page
      </span>
    </div>
  </nav>
  <?php endif; ?>

  <?php endif; ?>
</div>
</div>

<?php layout_foot(); ?>
