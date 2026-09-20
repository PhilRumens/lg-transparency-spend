<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// --- Devolved nations ---
// Government entities (type=Strategic Authority, in devolved region)
$devolved_govts = $pdo->query("
    SELECT cc.council, cc.lad_code, cc.region,
           COALESCE(cs.total_spend,0)   AS total_spend,
           COALESCE(cs.payment_count,0) AS payment_count
    FROM ct_council_config cc
    LEFT JOIN ct_council_summary cs ON cs.lad_code = cc.lad_code
    WHERE cc.council_type = 'Strategic Authority'
      AND cc.region IN ('Scotland','Wales','Northern Ireland')
    ORDER BY cc.region
")->fetchAll(PDO::FETCH_ASSOC);
$govts_by_region = [];
foreach ($devolved_govts as $g) $govts_by_region[$g['region']] = $g;

// Member councils per devolved region
$devolved_councils = $pdo->query("
    SELECT cc.council, cc.lad_code, cc.region, cc.council_type,
           COALESCE(cs.total_spend,0)   AS total_spend,
           COALESCE(cs.payment_count,0) AS payment_count
    FROM ct_council_config cc
    LEFT JOIN ct_council_summary cs ON cs.lad_code = cc.lad_code
    WHERE cc.council_type != 'Strategic Authority'
      AND cc.region IN ('Scotland','Wales','Northern Ireland')
    ORDER BY cs.total_spend DESC
")->fetchAll(PDO::FETCH_ASSOC);
$devolved_by_region = [];
foreach ($devolved_councils as $c) $devolved_by_region[$c['region']][] = $c;

// Devolved region spend totals (incl govt entity)
$devolved_totals = $pdo->query("
    SELECT cc.region,
           COUNT(DISTINCT cc.lad_code)         AS council_count,
           COALESCE(SUM(cs.total_spend),0)      AS total_spend,
           COALESCE(SUM(cs.payment_count),0)    AS payment_count
    FROM ct_council_config cc
    LEFT JOIN ct_council_summary cs ON cs.lad_code = cc.lad_code
    WHERE cc.region IN ('Scotland','Wales','Northern Ireland')
    GROUP BY cc.region
")->fetchAll(PDO::FETCH_ASSOC);
$devolved_stats = [];
foreach ($devolved_totals as $r) $devolved_stats[$r['region']] = $r;

// --- English combined authorities ---
// SA body's own direct spend
$ca_direct = $pdo->query("
    SELECT ca_name,
           SUM(amount)  AS ca_spend,
           COUNT(*)     AS ca_payments
    FROM ct_ca_spend
    GROUP BY ca_name
")->fetchAll(PDO::FETCH_ASSOC);
$ca_direct_by_name = [];
foreach ($ca_direct as $r) $ca_direct_by_name[$r['ca_name']] = $r;

$ca_groups = $pdo->query("
    SELECT cc.combined_authority,
           COUNT(DISTINCT cc.lad_code)         AS council_count,
           COALESCE(SUM(cs.total_spend),0)      AS total_spend,
           COALESCE(SUM(cs.payment_count),0)    AS payment_count
    FROM ct_council_config cc
    LEFT JOIN ct_council_summary cs ON cs.lad_code = cc.lad_code
    WHERE cc.council_type != 'Strategic Authority'
      AND cc.region NOT IN ('Scotland','Wales','Northern Ireland')
      AND cc.combined_authority IS NOT NULL AND cc.combined_authority != ''
      AND cc.combined_authority != 'No current strategic authority'
    GROUP BY cc.combined_authority
    ORDER BY SUM(cs.total_spend) DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Councils per CA
$ca_councils = $pdo->query("
    SELECT cc.council, cc.lad_code, cc.region, cc.council_type, cc.combined_authority,
           COALESCE(cs.total_spend,0)   AS total_spend,
           COALESCE(cs.payment_count,0) AS payment_count
    FROM ct_council_config cc
    LEFT JOIN ct_council_summary cs ON cs.lad_code = cc.lad_code
    WHERE cc.council_type != 'Strategic Authority'
      AND cc.region NOT IN ('Scotland','Wales','Northern Ireland')
      AND cc.combined_authority IS NOT NULL AND cc.combined_authority != ''
      AND cc.combined_authority != 'No current strategic authority'
    ORDER BY cs.total_spend DESC
")->fetchAll(PDO::FETCH_ASSOC);
$ca_councils_by_name = [];
foreach ($ca_councils as $c) $ca_councils_by_name[$c['combined_authority']][] = $c;

// No-CA councils
$no_ca = $pdo->query("
    SELECT cc.council, cc.lad_code, cc.region, cc.council_type,
           COALESCE(cs.total_spend,0)   AS total_spend,
           COALESCE(cs.payment_count,0) AS payment_count
    FROM ct_council_config cc
    LEFT JOIN ct_council_summary cs ON cs.lad_code = cc.lad_code
    WHERE cc.council_type != 'Strategic Authority'
      AND cc.council_type != 'Joint Committee'  -- hide the internal Adur/Worthing joint entity (spend attributed 50/50 to the two councils)
      AND cc.region NOT IN ('Scotland','Wales','Northern Ireland')
      AND (cc.combined_authority = 'No current strategic authority'
           OR cc.combined_authority IS NULL OR cc.combined_authority = '')
    ORDER BY cs.total_spend DESC
")->fetchAll(PDO::FETCH_ASSOC);
$no_ca_spend    = array_sum(array_column($no_ca, 'total_spend'));
$no_ca_payments = array_sum(array_column($no_ca, 'payment_count'));

// Grand totals (councils + CA direct spend)
$total_ca_direct_spend    = array_sum(array_column($ca_direct, 'ca_spend'));
$total_ca_direct_payments = array_sum(array_column($ca_direct, 'ca_payments'));
$all_spend = array_sum(array_column($ca_groups, 'total_spend'))
           + $total_ca_direct_spend
           + array_sum(array_column($devolved_totals, 'total_spend'))
           + $no_ca_spend;
$all_count = array_sum(array_column($ca_groups, 'council_count'))
           + count($ca_direct_by_name)   // SA bodies themselves
           + array_sum(array_column($devolved_totals, 'council_count'))
           + count($no_ca);
$all_payments = array_sum(array_column($ca_groups, 'payment_count'))
              + $total_ca_direct_payments
              + array_sum(array_column($devolved_totals, 'payment_count'))
              + $no_ca_payments;

function fmt_spend(float $v): string {
    if ($v >= 1e9) return '£' . number_format($v/1e9, 1) . 'bn';
    if ($v >= 1e6) return '£' . number_format($v/1e6, 0) . 'm';
    if ($v > 0)    return '£' . number_format($v, 0);
    return '—';
}
function fmt_payments(int $v): string {
    if ($v >= 1e6) return number_format($v/1e6, 1) . 'm';
    if ($v >= 1e3) return number_format($v/1e3, 0) . 'k';
    return $v ? (string)$v : '—';
}

layout_head('Nations and Regions');
?>
<style>
.nr-section { margin-bottom: 2.5rem; }
.nr-section__header {
    display: flex; justify-content: space-between; align-items: baseline;
    border-bottom: 3px solid #1d70b8; margin-bottom: 1.25rem; padding-bottom: 0.5rem;
}
.nr-section__title { font-size: 1.25rem; font-weight: 700; margin: 0; }
.nr-section__meta { font-size: 0.875rem; color: #505a5f; }
.nr-stat-row { display: flex; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.nr-stat { flex: 1 1 120px; background: #f3f2f1; padding: 12px 14px; }
.nr-stat__num { font-size: 1.5rem; font-weight: 700; color: #1d70b8; line-height: 1; }
.nr-stat__label { font-size: 0.75rem; color: #505a5f; margin-top: 3px; }
.nr-councils { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px,1fr)); gap: 0.6rem; }
.nr-council-card {
    background: #fff; border: 1px solid #b1b4b6; padding: 10px 12px;
    display: flex; flex-direction: column; gap: 3px;
}
.nr-council-card--govt { border-color: #1d70b8; border-width: 2px; background: #f0f4fa; }
.nr-council-card__name { font-weight: 600; font-size: 0.875rem; }
.nr-council-card__type { font-size: 0.7rem; color: #6f777b; text-transform: uppercase; letter-spacing: 0.02em; }
.nr-council-card__spend { font-size: 1rem; font-weight: 700; color: #1d70b8; }
.nr-council-card__payments { font-size: 0.7rem; color: #505a5f; }
.nr-council-card a { text-decoration: none; color: inherit; }
.nr-council-card a:hover .nr-council-card__name { text-decoration: underline; color: #1d70b8; }
.nr-h2 { font-size: 1.75rem; font-weight: 700; margin: 2.5rem 0 0.25rem; padding-bottom: 0.5rem; border-bottom: 4px solid #0b0c0c; }
.nr-h2-sub { font-size: 0.875rem; color: #505a5f; margin-bottom: 1.75rem; }
.nr-top-stats { display: flex; gap: 1.25rem; flex-wrap: wrap; margin-bottom: 2.5rem; }
.nr-top-stat { flex: 1 1 150px; }
</style>

<!-- Grand totals -->
<div class="nr-top-stats">
<?php foreach ([
    [fmt_spend($all_spend),       'Total tech spend',         'All nations and regions'],
    [(string)$all_count,          'Councils and governments', 'With published spend data'],
    [fmt_payments($all_payments), 'Payments recorded',        'Across all periods'],
] as [$n, $l, $s]): ?>
<div class="nr-top-stat">
  <div class="ct-stat-card">
    <div class="ct-stat-card__number"><?= $n ?></div>
    <div class="ct-stat-card__label"><?= $l ?></div>
    <div class="ct-stat-card__sub"><?= $s ?></div>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- Devolved nations -->
<h2 class="nr-h2">Devolved nations</h2>
<p class="nr-h2-sub">Devolved governments and their local councils.</p>

<?php foreach (['Scotland','Wales','Northern Ireland'] as $nation):
    $stats   = $devolved_stats[$nation]  ?? ['total_spend'=>0,'payment_count'=>0,'council_count'=>0];
    $govt    = $govts_by_region[$nation] ?? null;
    $members = $devolved_by_region[$nation] ?? [];
?>
<div class="nr-section">
  <div class="nr-section__header">
    <span class="nr-section__title"><?= htmlspecialchars($nation) ?></span>
    <span class="nr-section__meta"><?= (int)$stats['council_count'] ?> entries</span>
  </div>
  <div class="nr-stat-row">
    <div class="nr-stat">
      <div class="nr-stat__num"><?= fmt_spend((float)$stats['total_spend']) ?></div>
      <div class="nr-stat__label">Total spend</div>
    </div>
    <div class="nr-stat">
      <div class="nr-stat__num"><?= fmt_payments((int)$stats['payment_count']) ?></div>
      <div class="nr-stat__label">Payments</div>
    </div>
    <div class="nr-stat">
      <div class="nr-stat__num"><?= (int)$stats['council_count'] ?></div>
      <div class="nr-stat__label">Councils / govts</div>
    </div>
  </div>
  <div class="nr-councils">
    <?php if ($govt): ?>
    <div class="nr-council-card nr-council-card--govt">
      <?php if ($govt['lad_code']): ?><a href="/contracts/council.php?id=<?= urlencode($govt['lad_code']) ?>"><?php endif; ?>
        <div class="nr-council-card__name"><?= htmlspecialchars($govt['council']) ?></div>
        <div class="nr-council-card__type">Devolved Government</div>
        <?php if ($govt['total_spend'] > 0): ?>
        <div class="nr-council-card__spend"><?= fmt_spend((float)$govt['total_spend']) ?></div>
        <div class="nr-council-card__payments"><?= fmt_payments((int)$govt['payment_count']) ?> payments</div>
        <?php endif; ?>
      <?php if ($govt['lad_code']): ?></a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php foreach ($members as $c): ?>
    <div class="nr-council-card">
      <?php if ($c['lad_code']): ?><a href="/contracts/council.php?id=<?= urlencode($c['lad_code']) ?>"><?php endif; ?>
        <div class="nr-council-card__name"><?= htmlspecialchars($c['council']) ?></div>
        <div class="nr-council-card__type"><?= htmlspecialchars($c['council_type'] ?? '') ?></div>
        <?php if ($c['total_spend'] > 0): ?>
        <div class="nr-council-card__spend"><?= fmt_spend((float)$c['total_spend']) ?></div>
        <div class="nr-council-card__payments"><?= fmt_payments((int)$c['payment_count']) ?> payments</div>
        <?php endif; ?>
      <?php if ($c['lad_code']): ?></a><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<!-- England by Strategic Authority -->
<h2 class="nr-h2">England — by strategic authority</h2>
<p class="nr-h2-sub">English councils grouped by their strategic authority or mayoral region.</p>

<?php foreach ($ca_groups as $ca):
    $ca_name   = $ca['combined_authority'];
    $ca_mems   = $ca_councils_by_name[$ca_name] ?? [];
    $ca_body   = $ca_direct_by_name[$ca_name]   ?? null;
    $sec_spend = (float)$ca['total_spend'] + (float)($ca_body['ca_spend'] ?? 0);
    $sec_pays  = (int)$ca['payment_count'] + (int)($ca_body['ca_payments'] ?? 0);
    $sec_count = (int)$ca['council_count'] + ($ca_body ? 1 : 0);
?>
<div class="nr-section">
  <div class="nr-section__header">
    <span class="nr-section__title"><?= htmlspecialchars($ca_name) ?></span>
    <span class="nr-section__meta"><?= $sec_count ?> entr<?= $sec_count != 1 ? 'ies' : 'y' ?></span>
  </div>
  <div class="nr-stat-row">
    <div class="nr-stat">
      <div class="nr-stat__num"><?= fmt_spend($sec_spend) ?></div>
      <div class="nr-stat__label">Total spend</div>
    </div>
    <div class="nr-stat">
      <div class="nr-stat__num"><?= fmt_payments($sec_pays) ?></div>
      <div class="nr-stat__label">Payments</div>
    </div>
    <div class="nr-stat">
      <div class="nr-stat__num"><?= $sec_count ?></div>
      <div class="nr-stat__label">Councils / authority</div>
    </div>
  </div>
  <div class="nr-councils">
    <?php if ($ca_body): ?>
    <div class="nr-council-card nr-council-card--govt">
      <div class="nr-council-card__name"><?= htmlspecialchars($ca_name) ?></div>
      <div class="nr-council-card__type">Strategic Authority</div>
      <div class="nr-council-card__spend"><?= fmt_spend((float)$ca_body['ca_spend']) ?></div>
      <div class="nr-council-card__payments"><?= fmt_payments((int)$ca_body['ca_payments']) ?> payments</div>
    </div>
    <?php endif; ?>
    <?php foreach ($ca_mems as $c): ?>
    <div class="nr-council-card">
      <?php if ($c['lad_code']): ?><a href="/contracts/council.php?id=<?= urlencode($c['lad_code']) ?>"><?php endif; ?>
        <div class="nr-council-card__name"><?= htmlspecialchars($c['council']) ?></div>
        <div class="nr-council-card__type"><?= htmlspecialchars($c['council_type'] ?? '') ?></div>
        <?php if ($c['total_spend'] > 0): ?>
        <div class="nr-council-card__spend"><?= fmt_spend((float)$c['total_spend']) ?></div>
        <div class="nr-council-card__payments"><?= fmt_payments((int)$c['payment_count']) ?> payments</div>
        <?php endif; ?>
      <?php if ($c['lad_code']): ?></a><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<!-- No CA -->
<div class="nr-section">
  <div class="nr-section__header">
    <span class="nr-section__title">No strategic authority</span>
    <span class="nr-section__meta"><?= count($no_ca) ?> councils</span>
  </div>
  <div class="nr-stat-row">
    <div class="nr-stat">
      <div class="nr-stat__num"><?= fmt_spend($no_ca_spend) ?></div>
      <div class="nr-stat__label">Total spend</div>
    </div>
    <div class="nr-stat">
      <div class="nr-stat__num"><?= fmt_payments((int)$no_ca_payments) ?></div>
      <div class="nr-stat__label">Payments</div>
    </div>
    <div class="nr-stat">
      <div class="nr-stat__num"><?= count($no_ca) ?></div>
      <div class="nr-stat__label">Councils</div>
    </div>
  </div>
  <div class="nr-councils">
    <?php foreach ($no_ca as $c): ?>
    <div class="nr-council-card">
      <?php if ($c['lad_code']): ?><a href="/contracts/council.php?id=<?= urlencode($c['lad_code']) ?>"><?php endif; ?>
        <div class="nr-council-card__name"><?= htmlspecialchars($c['council']) ?></div>
        <div class="nr-council-card__type"><?= htmlspecialchars($c['council_type'] ?? '') ?> · <?= htmlspecialchars($c['region'] ?? '') ?></div>
        <?php if ($c['total_spend'] > 0): ?>
        <div class="nr-council-card__spend"><?= fmt_spend((float)$c['total_spend']) ?></div>
        <div class="nr-council-card__payments"><?= fmt_payments((int)$c['payment_count']) ?> payments</div>
        <?php endif; ?>
      <?php if ($c['lad_code']): ?></a><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php layout_foot(true); ?>
