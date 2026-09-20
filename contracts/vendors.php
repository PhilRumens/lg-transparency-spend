<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();

// ── View toggle ────────────────────────────────────────────────────────────
$view = $_GET['view'] ?? 'tracked';  // 'tracked' or 'actual'

// ── Tracked vendor definitions (strategic 41) ─────────────────────────────
const VENDOR_DEFS = [
    ['Phoenix Software Ltd',             'Reseller',              106502734.92, 203, 3.748675],
    ['Bytes Technology Group Ltd',       'Reseller',               83145574.87, 121, 2.926551],
    ['Insight Enterprises UK Limited',   'Reseller',               88974126.58, 178, 3.131704],
    ['CDW Limited',                      'Reseller',               75162008.42,  78, 2.645546],
    ['Softcat Limited',                  'Reseller',               53615882.96, 185, 1.887167],
    ['Specialist Computer Centres (SCC) PLC', 'Reseller',         39508398.46,  86, 1.390613],
    ['Trustmarque Solutions (Capita)',   'Reseller',               32762413.02,  67, 1.153168],
    ['Computacenter (UK) Ltd',           'Reseller',               15172863.59,  29, 0.534053],
    ['Xma Ltd',                          'Reseller',               28163422.98,  99, 0.991294],
    ['Ccs Media Ltd',                    'Reseller',                8279408.14, 130, 0.291417],
    ['BOXXE Limited',                    'Reseller',                9282321.65,  54, 0.326718],
    ['British Telecom (BT Group)',       'Networks / Telecoms',    81206023.06, 274, 2.858283],
    ['Virgin Media Business',            'Networks / Telecoms',    41689921.19, 185, 1.467398],
    ['Vodafone',                         'Networks / Telecoms',    21848808.03, 217, 0.769032],
    ['Mll Telecom Ltd',                  'Networks / Telecoms',    18787624.67,  32, 0.661285],
    ['Daisy Group Holdings',             'Networks / Telecoms',    16481454.51, 132, 0.580113],
    ['Telefonica O2 (UK)',               'Networks / Telecoms',    13320792.81, 115, 0.468864],
    ['EE Ltd',                           'Networks / Telecoms',     8934513.81, 109, 0.314476],
    ['Talent Technology Services (Ttsl)','Networks / Telecoms',    18274558.60,  29, 0.643226],
    ['Capita Group',                     'Software, Services & BPO', 124493326.48, 235, 4.381906],
    ['Civica',                           'Software, Services & BPO', 101400578.19, 314, 3.569090],
    ['Wonde Ltd',                        'Software, Services & BPO',  76575532.56,  75, 2.695299],
    ['NEC Software Solutions',           'Software, Services & BPO',  58645730.34, 227, 2.064208],
    ['Agilisys',                         'Software, Services & BPO',  50474134.94,  32, 1.776584],
    ['The Access Group Ltd',             'Software, Services & BPO',  46195353.85, 163, 1.625980],
    ['Liberate',                         'Software, Services & BPO',  44893788.74,  31, 1.580168],
    ['Serco Group',                      'Software, Services & BPO',  44621821.71,  13, 1.570595],
    ['Oracle Corporation (software)',    'Software, Services & BPO',  33258435.86, 109, 1.170627],
    ['Idox Plc',                         'Software, Services & BPO',  31101018.89, 300, 1.094691],
    ['Dell Corporation Limited',         'Software, Services & BPO',  28725378.95, 147, 1.011073],
    ['Liquidlogic (part of Xyster)',     'Software, Services & BPO',  22602287.30,  86, 0.795553],
    ['Paypoint Plc',                     'Software, Services & BPO',  18718849.86,  57, 0.658864],
    ['Unit4 Business Software',          'Software, Services & BPO',  18589267.12,  70, 0.654303],
    ['SAP (UK) Limited',                 'Software, Services & BPO',  15966156.43,  41, 0.561975],
    ['Ringgo Limited (prev PARK NOW)',   'Software, Services & BPO',  13332601.75, 109, 0.469279],
    ['Granicus-Firmstep Ltd',            'Software, Services & BPO',  10719602.58, 154, 0.377307],
    ['Microsoft',                        'Software, Services & BPO',   9966494.07,  59, 0.350799],
    ['Aquilaheywood Limited',            'Software, Services & BPO',   9129000.65,  25, 0.321321],
    ['Esri UK Ltd',                      'Software, Services & BPO',   8726327.41, 180, 0.307148],
    ['Advanced Business Solutions Ltd',  'Software, Services & BPO',   8413309.16,  98, 0.296131],
    ['Pay360 Limited (part of Capita)',  'Software, Services & BPO',   7138161.10, 119, 0.251248],
];

// Actual top suppliers from BigQuery (by notice count, LG councils, since 2024-01-01)
// Deduplicated/cleaned from the aggregate query
const ACTUAL_SUPPLIERS = [
    // Deduplicated — merged case variants, removed noise entries
    ['24x7 Ltd',                         'Transport (SEND)',     238, 212153912], // merged 24x7 variants
    ['Civica UK Limited',                'Software & IT',        246, null],      // merged Civica variants
    ['SKYLINE TAXIS',                    'Transport (SEND)',     176, null],
    ['Neales Taxis Limited',             'Transport (SEND)',     174, null],
    ['CARE LINE HOMECARE LIMITED',       'Care Services',        168, null],
    ['Chiltern Healthcare',              'Care Services',        163, null],
    ['AZOOM TAXIS',                      'Transport (SEND)',     153, null],      // merged AZOOM variants
    ['SPRINGFIELD HOME CARE SERVICES',   'Care Services',        123, null],
    ['Trust Utility Management Ltd',     'Utilities',            121, null],
    ['Hawk Express Cabs Ltd',            'Transport (SEND)',     117, null],
    ['STL',                              'Transport (SEND)',     108, null],
    ['Fast Cabs Ipswich LTD',            'Transport (SEND)',     106, null],
    ['Heritage Healthcare Milton Keynes','Care Services',        104, null],
    ['Waterman Aspen',                   'Engineering',          103, null],
    ['School Express',                   'Transport (SEND)',     101, null],
    ['Thomas Bow Ltd',                   'Construction',          99, null],
    ['HANSOM AIRPORT TAXIS LTD',         'Transport (SEND)',      99, null],      // merged variants
    ['Emiran HealthCare',                'Care Services',         96, null],
    ['All Day Cars',                     'Transport (SEND)',      91, null],
    ['Lookers Group',                    'Fleet / Vehicles',      89, null],
    ['Bucks Minibus Travel (Swan Rider)','Transport (SEND)',      84, null],
    ['Phoenix Software Ltd',             'IT Reseller',           83, null],      // also in tracked 41
    ['Idox Software Ltd',                'Software & IT',         81, null],
    ['Care MK',                          'Care Services',         81, null],
    ['Constellia Public Ltd',            'Engineering',           80, null],      // merged variants
    ['NEC Software Solutions UK Ltd',    'Software & IT',         51, null],
    ['Insight Direct (UK) Ltd',          'IT Reseller',           50, null],
    ['Colas Ltd',                        'Highways',              49, null],
    ['Softcat PLC',                      'IT Reseller',           47, null],      // merged variants
    ['WSP UK Limited',                   'Engineering',           70, null],
    ['Bago Care Limited',                'Care Services',         67, null],
    ['AECOM Limited',                    'Engineering',           44, null],
    ['Bloom Procurement Services Ltd',   'Professional Services', 42, null],
    ['Access UK Ltd',                    'Software & IT',         37, null],
    ['KPMG LLP',                         'Professional Services', 35, null],
    ['Aggregate Industries UK Ltd',      'Highways',              37, 352569837],
    ['Biffa Waste Services Ltd',         'Waste Management',       9, 618758366],
    ['Mears Limited',                    'Housing / FM',          18, 563282441],
    ['AECOM Limited',                    'Engineering',           44, 200094138],
    ['Comensura Limited',                'Workforce',             14, 1132549494],
    ['Ringway Infrastructure Services',  'Highways',               2, 2350000000],
];

// ── Fetch notice counts from local DB for tracked vendors ─────────────────
$vendor_names = array_column(VENDOR_DEFS, 0);
$placeholders = implode(',', array_fill(0, count($vendor_names), '?'));
$counts_stmt  = $pdo->prepare("
    SELECT vendor_name, COUNT(*) AS n,
           SUM(COALESCE(c.value_amount,0)) AS tv,
           SUM(CASE WHEN c.status='active' THEN 1 ELSE 0 END) AS active_n
    FROM ct_vendor_contracts vc
    JOIN ct_contracts c ON c.ocid = vc.ocid
    WHERE vendor_name IN ({$placeholders})
    GROUP BY vendor_name
");
$counts_stmt->execute($vendor_names);
$db_counts = array_column($counts_stmt->fetchAll(), null, 'vendor_name');

// ── Expand vendor for tracked view ────────────────────────────────────────
$expand_vendor = $_GET['vendor'] ?? '';
$vendor_contracts = [];
if ($expand_vendor && $view === 'tracked') {
    $stmt = $pdo->prepare("
        SELECT c.title, c.buyer_name, c.status, c.value_amount, c.published_date,
               c.official_url, c.lgam_sublayer_name
        FROM ct_vendor_contracts vc
        JOIN ct_contracts c ON c.ocid = vc.ocid
        WHERE vc.vendor_name = ?
        ORDER BY c.published_date DESC
        LIMIT 200
    ");
    $stmt->execute([$expand_vendor]);
    $vendor_contracts = $stmt->fetchAll();
}

const FW_NOTES = [
    'Reseller'              => 'Most reseller transactions are placed via CCS Technology Products (RM6068), G-Cloud, and manufacturer agreements. Award notices typically name the framework lot rather than the reseller — so procurement notice counts significantly understate true volume.',
    'Networks / Telecoms'   => 'Telecoms contracts are predominantly placed via CCS Network Services 3 (RM6116) and PSBA frameworks. Individual award notices often name the framework rather than the carrier, explaining low notice counts.',
    'Software, Services & BPO' => 'Software awards appear in notices more frequently, but many are placed via G-Cloud, DOS/DDAT frameworks, or council DPS arrangements where individual call-offs may not be published separately.',
];

layout_head('By vendor');
?>

<!-- View toggle -->
<div class="govuk-tabs" data-module="govuk-tabs">
  <ul class="govuk-tabs__list">
    <li class="govuk-tabs__list-item <?= $view==='tracked'?'govuk-tabs__list-item--selected':'' ?>">
      <a class="govuk-tabs__tab" href="?view=tracked">Strategic tracker (41 vendors)</a>
    </li>
    <li class="govuk-tabs__list-item <?= $view==='actual'?'govuk-tabs__list-item--selected':'' ?>">
      <a class="govuk-tabs__tab" href="?view=actual">Top suppliers from data</a>
    </li>
  </ul>

<?php if ($view === 'actual'): ?>
  <!-- ── ACTUAL SUPPLIERS VIEW ──────────────────────────────────────────── -->
  <div class="govuk-tabs__panel">
    <p class="govuk-body govuk-!-colour-secondary">
      Ranked by award notice count for local authority buyers, Jan 2024–present.
      Source: BigQuery public procurement index. Note: supplier names are not normalised in the
      raw data — the same company may appear under multiple name variants.
    </p>

    <?php
    // Group by category
    $by_cat = [];
    foreach (ACTUAL_SUPPLIERS as $s) {
        $by_cat[$s[1]][] = $s;
    }
    // Sort each category by notice count
    foreach ($by_cat as &$cat) {
        usort($cat, fn($a,$b) => $b[2] <=> $a[2]);
    }
    // Sort categories by total notices
    uksort($by_cat, function($a, $b) use ($by_cat) {
        return array_sum(array_column($by_cat[$b], 2)) <=> array_sum(array_column($by_cat[$a], 2));
    });
    ?>

    <?php foreach ($by_cat as $cat => $suppliers): ?>
    <h2 class="govuk-heading-m govuk-!-margin-top-6"><?= htmlspecialchars($cat) ?></h2>
    <table class="govuk-table">
      <thead class="govuk-table__head">
        <tr class="govuk-table__row">
          <th class="govuk-table__header">Supplier</th>
          <th class="govuk-table__header govuk-table__header--numeric">Award notices</th>
          <th class="govuk-table__header govuk-table__header--numeric">Stated value</th>
          <th class="govuk-table__header">In local DB</th>
        </tr>
      </thead>
      <tbody class="govuk-table__body">
        <?php foreach ($suppliers as [$name, $cat2, $notices, $value]): ?>
        <tr class="govuk-table__row">
          <td class="govuk-table__cell"><?= htmlspecialchars($name) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric"><?= number_format($notices) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric"><?= $value ? fmt_value((float)$value) : '—' ?></td>
          <td class="govuk-table__cell">
            <?php
            $dbCount = 0;
            foreach ($db_counts as $vname => $row) {
                if (stripos($vname, explode(' ', $name)[0]) !== false) {
                    $dbCount = (int)$row['n'];
                    break;
                }
            }
            echo $dbCount ? number_format($dbCount) . ' notices' : '<span style="color:#6f777b">—</span>';
            ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endforeach; ?>

    <div class="govuk-inset-text govuk-!-margin-top-6">
      <p class="govuk-body-s">
        Data covers all LG contracts in the public procurement index since Jan 2024.
        Notice counts are from award notices where the supplier name is recorded.
        Many contracts are awarded via frameworks where individual call-offs are not published,
        so these figures substantially undercount true volume for framework-heavy suppliers.
      </p>
    </div>
  </div>

<?php else: ?>
  <!-- ── TRACKED VENDORS VIEW ──────────────────────────────────────────── -->
  <div class="govuk-tabs__panel">

  <!-- Summary -->
  <div class="govuk-grid-row govuk-!-margin-bottom-6">
    <?php
    $total_spend   = array_sum(array_column(VENDOR_DEFS, 2));
    $total_notices = array_sum(array_column($db_counts, 'n'));
    ?>
    <div class="govuk-grid-column-one-quarter">
      <div class="ct-stat-card"><div class="ct-stat-card__number">41</div><div class="ct-stat-card__label">Vendors tracked</div></div>
    </div>
    <div class="govuk-grid-column-one-quarter">
      <div class="ct-stat-card"><div class="ct-stat-card__number"><?= fmt_value($total_spend) ?></div><div class="ct-stat-card__label">Total sector spend</div><div class="ct-stat-card__sub">From screenshot data</div></div>
    </div>
    <div class="govuk-grid-column-one-quarter">
      <div class="ct-stat-card"><div class="ct-stat-card__number"><?= number_format($total_notices) ?></div><div class="ct-stat-card__label">Notices in database</div></div>
    </div>
    <div class="govuk-grid-column-one-quarter">
      <div class="ct-stat-card"><div class="ct-stat-card__number"><?= number_format(array_sum(array_column(VENDOR_DEFS, 3))) ?></div><div class="ct-stat-card__label">Orgs buying (est.)</div></div>
    </div>
  </div>

  <?php
  $categories = ['Reseller', 'Networks / Telecoms', 'Software, Services & BPO'];
  foreach ($categories as $cat):
    $cat_vendors = array_filter(VENDOR_DEFS, fn($v) => $v[1] === $cat);
  ?>
  <h2 class="govuk-heading-m govuk-!-margin-top-6"><?= htmlspecialchars($cat) ?></h2>
  <p class="govuk-body-s govuk-!-colour-secondary" style="border-left:4px solid #b1b4b6;padding-left:12px;margin-bottom:20px">
    <?= htmlspecialchars(FW_NOTES[$cat]) ?>
  </p>

  <?php foreach ($cat_vendors as [$name, , $spend, $orgs, $ws]):
    $counts  = $db_counts[$name] ?? ['n'=>0,'tv'=>0,'active_n'=>0];
    $notices = (int)$counts['n'];
    $tv      = (float)$counts['tv'];
    $active  = (int)$counts['active_n'];
    $max_val = max($spend, $tv, 1);
    $is_open = ($expand_vendor === $name);
  ?>
  <div class="ct-vendor-card">
    <a href="?view=tracked&<?= http_build_query(array_merge($_GET, ['vendor'=>$is_open?'':$name,'view'=>'tracked'])) ?>"
       style="text-decoration:none;display:block">
      <div class="ct-vendor-card__header">
        <div>
          <div class="ct-vendor-card__name"><?= htmlspecialchars($name) ?></div>
          <div class="ct-vendor-card__cat"><?= $notices ?> procurement notice<?= $notices!==1?'s':'' ?> in database</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <?php if ($active): ?><span class="ct-tag ct-tag--active"><?= $active ?> active</span><?php endif; ?>
          <span style="font-size:0.875rem;color:#505a5f"><?= fmt_value($spend) ?> sector spend</span>
          <span style="font-size:0.875rem;color:#505a5f"><?= $ws ?>% wallet share</span>
          <span style="color:#505a5f">▼</span>
        </div>
      </div>
    </a>
    <?php if ($is_open): ?>
    <div class="ct-vendor-card__body ct-vendor-card__body--open">
      <div class="ct-kpis">
        <div class="ct-kpi"><div class="ct-kpi__n"><?= fmt_value($spend) ?></div><div class="ct-kpi__l">Sector spend</div></div>
        <div class="ct-kpi"><div class="ct-kpi__n"><?= number_format($orgs) ?></div><div class="ct-kpi__l">Organisations</div></div>
        <div class="ct-kpi"><div class="ct-kpi__n"><?= number_format($ws, 2) ?>%</div><div class="ct-kpi__l">Wallet share</div></div>
        <div class="ct-kpi"><div class="ct-kpi__n"><?= number_format($notices) ?></div><div class="ct-kpi__l">Notices in DB</div></div>
        <div class="ct-kpi"><div class="ct-kpi__n"><?= $tv > 0 ? fmt_value($tv) : '—' ?></div><div class="ct-kpi__l">Notices value</div></div>
      </div>
      <div class="ct-spend-bar">
        <div class="ct-spend-row"><span class="ct-spend-row__label">Sector spend</span><span class="ct-spend-row__track"><span class="ct-spend-row__fill" style="width:<?= round($spend/$max_val*100) ?>%;opacity:0.4"></span></span><span class="ct-spend-row__val"><?= fmt_value($spend) ?></span></div>
        <div class="ct-spend-row"><span class="ct-spend-row__label">Notices value</span><span class="ct-spend-row__track"><span class="ct-spend-row__fill" style="width:<?= round($tv/$max_val*100) ?>%"></span></span><span class="ct-spend-row__val"><?= $tv > 0 ? fmt_value($tv) : '—' ?></span></div>
      </div>
      <?php if ($vendor_contracts): ?>
      <div style="padding:15px 20px;border-top:1px solid #b1b4b6">
        <h3 class="govuk-heading-s">Procurement notices (<?= count($vendor_contracts) ?><?= count($vendor_contracts)>=200?'+':'' ?>)</h3>
        <table class="govuk-table govuk-!-margin-bottom-0">
          <thead class="govuk-table__head">
            <tr class="govuk-table__row">
              <th class="govuk-table__header">Title</th>
              <th class="govuk-table__header">Buyer</th>
              <th class="govuk-table__header">LGAM layer</th>
              <th class="govuk-table__header govuk-table__header--numeric">Value</th>
              <th class="govuk-table__header">Published</th>
              <th class="govuk-table__header">Status</th>
            </tr>
          </thead>
          <tbody class="govuk-table__body">
            <?php foreach ($vendor_contracts as $c): ?>
            <tr class="govuk-table__row">
              <td class="govuk-table__cell" style="max-width:260px">
                <?php if ($c['official_url']): ?><a href="<?= htmlspecialchars($c['official_url']) ?>" class="govuk-link" target="_blank" rel="noopener"><?= htmlspecialchars(mb_strimwidth($c['title'] ?? '—', 0, 70, '…')) ?></a>
                <?php else: ?><?= htmlspecialchars(mb_strimwidth($c['title'] ?? '—', 0, 70, '…')) ?><?php endif; ?>
              </td>
              <td class="govuk-table__cell"><?= htmlspecialchars(mb_strimwidth($c['buyer_name'] ?? '—', 0, 35, '…')) ?></td>
              <td class="govuk-table__cell"><?= htmlspecialchars($c['lgam_sublayer_name'] ?? '—') ?></td>
              <td class="govuk-table__cell govuk-table__cell--numeric"><?= fmt_value($c['value_amount']) ?></td>
              <td class="govuk-table__cell"><?= htmlspecialchars($c['published_date'] ?? '—') ?></td>
              <td class="govuk-table__cell"><?= status_tag($c['status'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div style="padding:15px 20px;border-top:1px solid #b1b4b6">
        <p class="govuk-body govuk-!-colour-secondary">No procurement notices found where this vendor is named in the title or description.</p>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<?php layout_foot(); ?>
