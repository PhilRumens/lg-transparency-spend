<?php
/**
 * reorg.php — Local Government Reorganisation impact view (logged-in only).
 *
 * Shows each proposed successor unitary from the English Devolution White Paper
 * wave, with the TECH CONTRACTS each new unitary will inherit — rolled up from
 * the contract registers of its predecessor district/county councils.
 *
 * Data: ct_council_lineage (loaded by import_lgr_lineage.php) joined via the
 * predecessor's council name to ct_contract_register_entries (is_tech = 1).
 * Register entries are keyed by council NAME, not lad_code, so we join
 * lineage.pred_lad_code -> ct_council_config.lad_code -> .council -> entries.
 */
session_cache_limiter("");
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_layout.php';

if (!logged_in()) { header('Location: tech_login.php'); exit; }

header_remove("Expires"); header_remove("Pragma"); header("Cache-Control: private, max-age=120", true);
$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// "Active only" toggle — contracts whose end_date is today or later, i.e. the
// live commitments a successor unitary actually inherits. Default off (all).
$active_only = isset($_GET['active']) && $_GET['active'] === '1';
$active_clause = $active_only ? " AND e.end_date >= CURDATE() " : "";

// Per-predecessor tech-contract rollup, joined by council name. One row per
// predecessor council in the lineage; councils with no register entries show 0.
$sql = "
    SELECT l.succ_key, l.succ_name, l.status, l.go_live_year,
           l.pred_lad_code, l.pred_name,
           cc.council      AS pred_council,
           cc.council_type AS pred_type,
           COUNT(e.id)                        AS c_count,
           COALESCE(SUM(e.value_amount), 0)   AS c_value
    FROM ct_council_lineage l
    LEFT JOIN ct_council_config cc ON cc.lad_code = l.pred_lad_code
    LEFT JOIN ct_contract_register_entries e
           ON e.council = cc.council AND e.is_tech = 1 $active_clause
    WHERE l.edge_type = 'Merge/Split'
    GROUP BY l.succ_key, l.pred_lad_code
    ORDER BY l.succ_name, l.pred_name";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Shared suppliers — the consolidation story. A supplier held by 2+ of a
// successor's predecessor councils is a duplicate the new unitary can collapse
// into one deal. Respects the active-only toggle via $active_clause. Joins
// ct_suppliers to resolve a company_number for the supplier.php link (same
// pattern as council.php:517).
$shared_sql = "
    SELECT l.succ_key, e.supplier_canon,
           COUNT(DISTINCT cc.council)      AS councils,
           COUNT(e.id)                     AS contracts,
           COALESCE(SUM(e.value_amount),0) AS val,
           MAX(su.company_number)          AS company_number
    FROM ct_council_lineage l
    JOIN ct_council_config cc ON cc.lad_code = l.pred_lad_code
    JOIN ct_contract_register_entries e
         ON e.council = cc.council AND e.is_tech = 1 $active_clause
    LEFT JOIN ct_suppliers su ON su.canonical_name = e.supplier_canon
    WHERE l.edge_type = 'Merge/Split'
      AND e.supplier_canon IS NOT NULL AND e.supplier_canon <> ''
    GROUP BY l.succ_key, e.supplier_canon
    HAVING councils >= 2
    ORDER BY councils DESC, contracts DESC";
$shared = [];
foreach ($pdo->query($shared_sql) as $r) {
    $shared[$r['succ_key']][] = $r;
}
$SHARED_CAP = 15; // rows shown per card; overflow noted, not silently dropped.

// Combined/strategic authority per successor key (constituent-partner layer).
$ca_by_lad = [];
foreach ($pdo->query("SELECT lad_code, ca_name FROM ct_council_ca") as $c) {
    $ca_by_lad[$c['lad_code']][] = $c['ca_name'];
}

// Group predecessors under each successor.
$succ = [];
foreach ($rows as $r) {
    $k = $r['succ_key'];
    if (!isset($succ[$k])) {
        $succ[$k] = [
            'name'      => $r['succ_name'],
            'status'    => $r['status'],
            'year'      => $r['go_live_year'],
            'preds'     => [],
            'contracts' => 0,
            'value'     => 0.0,
            'ca'        => $ca_by_lad[$k][0] ?? null,
        ];
    }
    $succ[$k]['preds'][]   = $r;
    $succ[$k]['contracts'] += (int)$r['c_count'];
    $succ[$k]['value']     += (float)$r['c_value'];
}

// Sort successors by inherited contract count desc.
uasort($succ, fn($a, $b) => $b['contracts'] <=> $a['contracts']);

// Headline figures.
$total_succ       = count($succ);
$total_preds      = count(array_unique(array_column($rows, 'pred_lad_code')));
$preds_with_data  = count(array_unique(array_filter(array_map(
    fn($r) => (int)$r['c_count'] > 0 ? $r['pred_lad_code'] : null, $rows))));
$total_contracts  = array_sum(array_column($succ, 'contracts'));
$total_value      = array_sum(array_column($succ, 'value'));

// Status → tag class + label.
function reorg_tag(string $status): string {
    switch ($status) {
        case 'live':     return '<span class="ct-tag ct-tag--active">Live</span>';
        case 'tbd':      return '<span class="ct-tag ct-tag--planned">Boundary TBD</span>';
        default:         return '<span class="ct-tag ct-tag--planned">Proposed</span>';
    }
}

// En-dash suppresses the auto-<h1> in _layout.php; this page renders its own.
layout_head('Reorganisation – Experimental', 'reorg.php');
?>

<h1 class="govuk-heading-xl" style="margin-bottom:5px">Local government reorganisation</h1>
<p class="govuk-body-l" style="color:#505a5f;margin-bottom:25px">
  Proposed unitary authorities from the English Devolution White Paper, showing the
  tech contracts each new unitary will inherit — rolled up from the contract
  registers of its predecessor councils.
</p>

<div class="govuk-grid-row" style="margin-bottom:25px">
  <div class="govuk-grid-column-one-quarter">
    <div class="ct-stat-card">
      <div class="ct-stat-card__number"><?= number_format($total_succ) ?></div>
      <div class="ct-stat-card__label">Proposed unitaries</div>
    </div>
  </div>
  <div class="govuk-grid-column-one-quarter">
    <div class="ct-stat-card">
      <div class="ct-stat-card__number"><?= number_format($total_preds) ?></div>
      <div class="ct-stat-card__label">Councils affected</div>
      <div class="ct-stat-card__sub"><?= number_format($preds_with_data) ?> with contract data</div>
    </div>
  </div>
  <div class="govuk-grid-column-one-quarter">
    <div class="ct-stat-card">
      <div class="ct-stat-card__number"><?= number_format($total_contracts) ?></div>
      <div class="ct-stat-card__label"><?= $active_only ? 'Active tech contracts' : 'Tech contracts' ?></div>
      <div class="ct-stat-card__sub">in scope for reorganisation</div>
    </div>
  </div>
  <div class="govuk-grid-column-one-quarter">
    <div class="ct-stat-card">
      <div class="ct-stat-card__number"><?= fmt_value($total_value) ?></div>
      <div class="ct-stat-card__label">Combined value</div>
      <div class="ct-stat-card__sub">where a contract value is recorded</div>
    </div>
  </div>
</div>

<form method="get" class="app-filter-bar">
  <div class="govuk-form-group">
    <label class="govuk-label" for="active">Contract scope</label>
    <select class="govuk-select" id="active" name="active" onchange="this.form.submit()">
      <option value="0"<?= !$active_only ? ' selected' : '' ?>>All tech contracts</option>
      <option value="1"<?= $active_only ? ' selected' : '' ?>>Active only (not yet expired)</option>
    </select>
  </div>
  <noscript><button class="govuk-button" style="margin-bottom:0">Apply</button></noscript>
</form>

<?php $n = 0; foreach ($succ as $key => $s): $n++; $bid = 'succ-' . $n; ?>
  <div class="ct-layer">
    <button type="button" class="ct-layer__header" data-toggle="<?= $bid ?>" aria-expanded="false">
      <span>
        <?= htmlspecialchars($s['name']) ?>
        <?= reorg_tag($s['status']) ?>
        <?php if ($s['year']): ?><span class="ct-layer__value">go-live <?= (int)$s['year'] ?></span><?php endif; ?>
      </span>
      <span class="ct-layer__header-right">
        <span class="ct-layer__value"><?= count($s['preds']) ?> councils</span>
        <?php $n_shared = count($shared[$key] ?? []); if ($n_shared): ?>
          <span class="ct-layer__value"><?= number_format($n_shared) ?> shared supplier<?= $n_shared === 1 ? '' : 's' ?></span>
        <?php endif; ?>
        <span class="ct-layer__count"><?= number_format($s['contracts']) ?> contracts</span>
        <span class="ct-layer__value"><?= fmt_value($s['value']) ?></span>
        <span class="ct-layer__chevron">▼</span>
      </span>
    </button>
    <div class="ct-layer__body" id="<?= $bid ?>">
      <?php if ($s['ca']): ?>
        <p class="govuk-body-s" style="color:#505a5f;margin-bottom:12px">
          Proposed strategic authority: <strong><?= htmlspecialchars($s['ca']) ?></strong>
        </p>
      <?php endif; ?>

      <?php $sh = $shared[$key] ?? []; if ($sh): $shown = array_slice($sh, 0, $SHARED_CAP); ?>
        <h3 class="govuk-heading-s" style="margin-bottom:8px">Shared suppliers <span style="font-weight:400;color:#505a5f">(held by 2+ predecessor councils — consolidation candidates)</span></h3>
        <table class="govuk-table" style="margin-bottom:25px">
          <thead class="govuk-table__head">
            <tr class="govuk-table__row">
              <th class="govuk-table__header" scope="col">Supplier</th>
              <th class="govuk-table__header govuk-table__header--numeric" scope="col">Councils</th>
              <th class="govuk-table__header govuk-table__header--numeric" scope="col">Contracts</th>
              <th class="govuk-table__header govuk-table__header--numeric" scope="col">Combined value</th>
            </tr>
          </thead>
          <tbody class="govuk-table__body">
            <?php foreach ($shown as $sup): ?>
              <tr class="govuk-table__row">
                <td class="govuk-table__cell">
                  <?php if (!empty($sup['company_number'])): ?>
                    <a class="govuk-link" href="/contracts/supplier.php?id=<?= htmlspecialchars($sup['company_number']) ?>"><?= htmlspecialchars($sup['supplier_canon']) ?></a>
                  <?php else: ?>
                    <?= htmlspecialchars($sup['supplier_canon']) ?>
                  <?php endif; ?>
                </td>
                <td class="govuk-table__cell govuk-table__cell--numeric"><?= (int)$sup['councils'] ?></td>
                <td class="govuk-table__cell govuk-table__cell--numeric"><?= number_format((int)$sup['contracts']) ?></td>
                <td class="govuk-table__cell govuk-table__cell--numeric"><?= (float)$sup['val'] > 0 ? fmt_value((float)$sup['val']) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (count($sh) > $SHARED_CAP): ?>
          <p class="govuk-body-s" style="color:#505a5f;margin-top:-15px;margin-bottom:25px">Showing top <?= $SHARED_CAP ?> of <?= count($sh) ?> shared suppliers.</p>
        <?php endif; ?>
      <?php endif; ?>

      <h3 class="govuk-heading-s" style="margin-bottom:8px">Predecessor councils</h3>
      <table class="govuk-table">
        <thead class="govuk-table__head">
          <tr class="govuk-table__row">
            <th class="govuk-table__header" scope="col">Predecessor council</th>
            <th class="govuk-table__header" scope="col">Current type</th>
            <th class="govuk-table__header govuk-table__header--numeric" scope="col">Tech contracts</th>
            <th class="govuk-table__header govuk-table__header--numeric" scope="col">Value</th>
          </tr>
        </thead>
        <tbody class="govuk-table__body">
          <?php foreach ($s['preds'] as $p): ?>
            <tr class="govuk-table__row">
              <td class="govuk-table__cell">
                <?php if ($p['pred_lad_code'] && $p['pred_type'] !== null): ?>
                  <a class="govuk-link" href="council.php?id=<?= htmlspecialchars($p['pred_lad_code']) ?>"><?= htmlspecialchars($p['pred_name']) ?></a>
                <?php else: ?>
                  <?= htmlspecialchars($p['pred_name']) ?>
                  <span class="ct-tag ct-tag--cancelled" title="Predecessor GSS code not matched in council config">no data</span>
                <?php endif; ?>
              </td>
              <td class="govuk-table__cell"><?= htmlspecialchars($p['pred_type'] ?? '—') ?></td>
              <td class="govuk-table__cell govuk-table__cell--numeric"><?= (int)$p['c_count'] > 0 ? number_format((int)$p['c_count']) : '—' ?></td>
              <td class="govuk-table__cell govuk-table__cell--numeric"><?= (float)$p['c_value'] > 0 ? fmt_value((float)$p['c_value']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<?php layout_foot(true); ?>
