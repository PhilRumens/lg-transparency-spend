<?php
/**
 * supplier.php — Unified supplier page keyed by Companies House number.
 * URL: /contracts/supplier.php?id=04968437
 * Brings together: payments across all councils, contracts, CH company data.
 */
session_cache_limiter("");
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_layout.php';

header_remove("Expires"); header_remove("Pragma"); header("Cache-Control: public, max-age=300", true);
$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$company_number = $_GET['id'] ?? $_GET['company'] ?? '';
$canonical_name = $_GET['name'] ?? '';

// Resolve supplier: by company_number or by canonical_name
$supplier_info = null;
if ($company_number && preg_match('/^[A-Z]{0,3}[0-9]{5,12}$/i', $company_number)) {
    $stmt = $pdo->prepare("SELECT * FROM ct_suppliers WHERE company_number = ?");
    $stmt->execute([$company_number]);
    $supplier_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$supplier_info && $canonical_name) {
    $stmt = $pdo->prepare("SELECT * FROM ct_suppliers WHERE canonical_name = ?");
    $stmt->execute([$canonical_name]);
    $supplier_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Determine the canonical name to query by
if ($supplier_info) {
    $search_name = $supplier_info['canonical_name'];
    $display_name = $supplier_info['company_name'];
    $company_number = $supplier_info['company_number'];
} elseif ($canonical_name) {
    $search_name = $canonical_name;
    $display_name = $canonical_name;
} elseif (!$company_number && !$canonical_name) {
    // Show supplier index
    $all_suppliers = $pdo->query("
        SELECT s.company_number, s.company_name, s.canonical_name, s.sic_codes,
               COALESCE(ss.total_spend, 0) as total_spend,
               COALESCE(ss.council_count, 0) as council_count,
               COALESCE(ss.payment_count, 0) as payment_count,
               COALESCE(ss.contract_count, 0) as contract_count,
               COALESCE(ss.contract_value, 0) as contract_value
        FROM ct_suppliers s
        LEFT JOIN ct_supplier_summary ss ON ss.canonical_name = s.canonical_name
        ORDER BY ss.total_spend DESC
    ")->fetchAll(PDO::FETCH_ASSOC);









    // Financial year + region filters
    $fy = $_GET['fy'] ?? '2025';
    $fy_options = ['all' => 'All years', '2025' => '2025/26', '2024' => '2024/25', '2023' => '2023/24', '2022' => '2022/23'];
    $region_filter = isset($_GET['region']) ? $_GET['region'] : 'England';
    $lad_prefix_map = ['England' => 'E', 'Scotland' => 'S', 'Wales' => 'W', 'Northern Ireland' => 'N'];
    $lad_prefix = $lad_prefix_map[$region_filter] ?? '';

    if ($fy !== 'all') {
        $stmt = $pdo->prepare("
            SELECT s.company_number, s.company_name, s.canonical_name, s.sic_codes,
                   COALESCE(snf.total_spend, 0) as total_spend,
                   COALESCE(snf.council_count, 0) as council_count,
                   COALESCE(snf.payment_count, 0) as payment_count,
                   COALESCE(ss.contract_count, 0) as contract_count,
                   COALESCE(ss.contract_value, 0) as contract_value
            FROM ct_suppliers s
            LEFT JOIN ct_supplier_summary_nation_fy snf ON snf.canonical_name = s.canonical_name AND snf.fy_year = ? AND snf.lad_prefix = ?
            LEFT JOIN ct_supplier_summary ss ON ss.canonical_name = s.canonical_name
            ORDER BY snf.total_spend DESC
        ");
        $stmt->execute([(int)$fy, $lad_prefix]);
        $all_suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Authoritative totals direct from summary table (avoids dropping suppliers not in ct_suppliers)
        $totals_row = $pdo->prepare('SELECT SUM(payment_count) as p, SUM(total_spend) as s FROM ct_supplier_summary_nation_fy WHERE fy_year=? AND lad_prefix=?');
        $totals_row->execute([(int)$fy, $lad_prefix]);
        $_nat_totals = $totals_row->fetch(PDO::FETCH_ASSOC);
        $raw_total_payments = (int)($_nat_totals['p'] ?? 0);
        $raw_total_spend    = (float)($_nat_totals['s'] ?? 0);
    } else {
        $all_suppliers = $pdo->query("
            SELECT s.company_number, s.company_name, s.canonical_name, s.sic_codes,
                   COALESCE(ss.total_spend, 0) as total_spend,
                   COALESCE(ss.council_count, 0) as council_count,
                   COALESCE(ss.payment_count, 0) as payment_count,
                   COALESCE(ss.contract_count, 0) as contract_count,
                   COALESCE(ss.contract_value, 0) as contract_value
            FROM ct_suppliers s
            LEFT JOIN ct_supplier_summary ss ON ss.canonical_name = s.canonical_name
            ORDER BY ss.total_spend DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    layout_head('Suppliers');
    $sic_labels = [
        '26' => 'Hardware', '46' => 'Reseller',
        '47' => 'Reseller', '58' => 'Software',
        '61' => 'Telecoms', '62' => 'IT services',
        '63' => 'Data services', '70' => 'Consultancy',
        '74' => 'Professional services', '82' => 'IT services',
        '95' => 'IT support',
    ];
    $sic_detail = [
        '62012' => 'Software', '62020' => 'Consultancy',
        '62030' => 'Managed services', '62090' => 'IT services',
        '63110' => 'Hosting', '63120' => 'Web services',
        '63990' => 'Data services', '61100' => 'Telecoms',
        '61200' => 'Telecoms', '61900' => 'Telecoms',
        '58210' => 'Software', '58290' => 'Software',
        '26200' => 'Hardware', '26301' => 'Hardware',
        '26309' => 'Hardware', '30990' => 'Hardware',
        '70229' => 'Consultancy', '70100' => 'Consultancy',
        '46510' => 'Reseller', '46520' => 'Reseller',
        '47410' => 'Reseller', '47990' => 'IT services',
        '82990' => 'IT services', '69201' => 'IT services',
        '69202' => 'IT services', '99999' => 'IT services',
        '64999' => 'Payments', '77330' => 'IT services',
        '28230' => 'Hardware', '33200' => 'Hardware',
        '71129' => 'Software',
    ];
    // Manual overrides for companies where SIC is misleading or missing
    $type_overrides = [
        'Dell' => 'Hardware', 'Xerox' => 'Hardware', 'HPE' => 'Hardware',
        'Capita' => 'Outsourcer', 'Serco' => 'Outsourcer',
        'Agilisys' => 'Outsourcer', 'Liberata' => 'Outsourcer',
        'BT' => 'Telecoms', 'Virgin Media Business' => 'Telecoms',
        'Vodafone' => 'Telecoms', 'EE' => 'Telecoms',
        'Telefonica O2' => 'Telecoms', 'Openreach' => 'Telecoms',
        'MLL Telecom' => 'Telecoms', 'Gamma' => 'Telecoms',
        'Phoenix Software' => 'Reseller', 'Softcat' => 'Reseller',
        'Bytes' => 'Reseller', 'CDW' => 'Reseller',
        'XMA' => 'Reseller', 'Insight Direct' => 'Reseller',
        'Trustmarque' => 'Reseller', 'Boxxe' => 'Reseller',
        'CCS Media' => 'Reseller', 'Probrand' => 'Reseller',
        'SCC' => 'Reseller', 'Computacenter' => 'Reseller',
        'European Electronique' => 'Reseller', 'Jigsaw24' => 'Reseller',
        'Millgate Computer Systems' => 'Reseller',
        'Oracle' => 'Software', 'Microsoft' => 'Software',
        'SAP' => 'Software', 'NEC Software Solutions' => 'Software',
        'Idox Software' => 'Software', 'Civica UK' => 'Software',
        'Liquidlogic' => 'Software', 'The Access Group' => 'Software',
        'Unit4' => 'Software', 'MRI Community Software' => 'Software',
        'Granicus-Firmstep' => 'Software', 'Brightly Software' => 'Software',
        'OLM Systems' => 'Software', 'Exacom' => 'Software',
        'Nitro Software' => 'Software', 'TeamViewer' => 'Software',
        'MHR' => 'Software', 'Fujitsu' => 'Managed services',
        'Mastek' => 'Consultancy', 'Cormac Solutions' => 'Managed services',
        'Amazon Web Services' => 'Cloud', 'Nominet' => 'Internet',
        'Synectics Security' => 'Hardware', 'Altia Intel' => 'Software',
        'Learning Pool' => 'Software', 'Trix' => 'Software',
    ];
    $visible_suppliers = array_values(array_filter($all_suppliers, fn($s) => (float)$s['total_spend'] > 0 || (int)$s['contract_count'] > 0));
    $sort_col = $_GET['sort'] ?? 'spend';
    $sort_dir = $_GET['dir'] ?? 'desc';
    usort($visible_suppliers, function($a, $b) use ($sort_col, $sort_dir) {
        $cmp = match($sort_col) {
            'name' => strcasecmp($a['canonical_name'], $b['canonical_name']),
            'spend' => ((float)$b['total_spend'] <=> (float)$a['total_spend'])
                       ?: ((int)$b['contract_count'] <=> (int)$a['contract_count'])
                       ?: ((int)$b['council_count'] <=> (int)$a['council_count']),
            'contracts' => ((int)$b['contract_count'] <=> (int)$a['contract_count'])
                       ?: ((float)$b['total_spend'] <=> (float)$a['total_spend'])
                       ?: ((int)$b['council_count'] <=> (int)$a['council_count']),
            'councils' => ((int)$b['council_count'] <=> (int)$a['council_count'])
                       ?: ((float)$b['total_spend'] <=> (float)$a['total_spend'])
                       ?: ((int)$b['contract_count'] <=> (int)$a['contract_count']),
            default => ((float)$b['total_spend'] <=> (float)$a['total_spend'])
                       ?: ((int)$b['contract_count'] <=> (int)$a['contract_count'])
                       ?: ((int)$b['council_count'] <=> (int)$a['council_count']),
        };
        return $sort_dir === 'asc' ? -$cmp : $cmp;
    });
    $search_q = trim($_GET['q'] ?? '');
    if ($search_q) {
        $search_lower = strtolower($search_q);
        $visible_suppliers = array_values(array_filter($visible_suppliers, fn($s) =>
            str_contains(strtolower($s['canonical_name']), $search_lower) ||
            str_contains(strtolower($s['company_name']), $search_lower)
        ));
    }

    $s_per_page = 50;
    $s_total_pages = max(1, (int)ceil(count($visible_suppliers) / $s_per_page));
    $s_page = max(1, min($s_total_pages, (int)($_GET['page'] ?? 1)));
    $s_slice = array_slice($visible_suppliers, ($s_page - 1) * $s_per_page, $s_per_page);

    echo '<form method="get" style="margin-bottom:16px;display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap">';
    echo '<div class="govuk-form-group govuk-!-margin-bottom-0"><label class="govuk-label govuk-!-font-size-16" for="fy">Financial year</label>';
    echo '<select class="govuk-select" id="fy" name="fy" onchange="this.form.submit()">';
    foreach ($fy_options as $val => $label) {
        echo '<option value="' . $val . '"' . ($fy == $val ? ' selected' : '') . '>' . $label . '</option>';
    }
    echo '</select></div>';
    echo '<div class="govuk-form-group govuk-!-margin-bottom-0"><label class="govuk-label govuk-!-font-size-16" for="region">Area</label>';
    echo '<select class="govuk-select" id="region" name="region" onchange="this.form.submit()">';
    foreach (['England' => 'England', 'Scotland' => 'Scotland', 'Wales' => 'Wales', 'Northern Ireland' => 'Northern Ireland', '' => 'All areas'] as $val => $label) {
        echo '<option value="' . $val . '"' . ($region_filter === $val ? ' selected' : '') . '>' . $label . '</option>';
    }
    echo '</select></div>';
    echo '<div class="govuk-form-group govuk-!-margin-bottom-0"><label class="govuk-label govuk-!-font-size-16" for="q">Search</label>';
    echo '<input class="govuk-input govuk-input--width-20" id="q" name="q" type="text" value="' . htmlspecialchars($search_q) . '"></div>';
    echo '<button class="govuk-button govuk-!-margin-bottom-0" type="submit">Filter</button>';
    if ($search_q || $fy !== '2025' || $region_filter !== 'England') echo ' <a href="/contracts/supplier.php" class="govuk-link" style="line-height:40px">Clear</a>';
    echo '</form>';

    $list_total_contracts = array_sum(array_column($visible_suppliers, 'contract_count'));
    $list_total_spend    = isset($raw_total_spend)    && !$search_q ? $raw_total_spend    : array_sum(array_column($visible_suppliers, 'total_spend'));
    $list_total_payments = isset($raw_total_payments) && !$search_q ? $raw_total_payments : array_sum(array_column($visible_suppliers, 'payment_count'));
    $spend_fmt = $list_total_spend >= 1e9 ? '£' . number_format($list_total_spend/1e9,1) . 'bn'
               : ($list_total_spend >= 1e6 ? '£' . number_format($list_total_spend/1e6,1) . 'm' : '£' . number_format($list_total_spend/1e3,0) . 'k');
    echo '<div class="govuk-grid-row govuk-!-margin-bottom-4">';
    foreach ([
        [count($visible_suppliers), 'Tech suppliers',   $search_q ? 'Matching search' : 'In spend data'],
        [$spend_fmt,                'Total spend',       'Across all filtered suppliers'],
        [number_format($list_total_payments), 'Payments', 'Transaction count'],
        [number_format($list_total_contracts),'Contracts','From contract registers'],
    ] as [$n, $l, $s]) {
        echo '<div class="govuk-grid-column-one-quarter govuk-!-margin-bottom-4"><div class="ct-stat-card"><div class="ct-stat-card__number">' . $n . '</div><div class="ct-stat-card__label">' . $l . '</div><div class="ct-stat-card__sub">' . $s . '</div></div></div>';
    }
    echo '</div>';
    $qs_base = ($region_filter !== 'England' ? '&region=' . urlencode($region_filter) : '') . ($search_q ? '&q=' . urlencode($search_q) : '');
    $sort_link = function($col, $label) use ($sort_col, $sort_dir, $qs_base) {
        $new_dir = ($sort_col === $col && $sort_dir === 'desc') ? 'asc' : 'desc';
        $arrow = $sort_col === $col ? ($sort_dir === 'desc' ? ' ▾' : ' ▴') : '';
        return '<a href="?sort=' . $col . '&dir=' . $new_dir . $qs_base . '" class="govuk-link" style="text-decoration:none;color:inherit">' . $label . $arrow . '</a>';
    };
    echo '<table class="govuk-table"><thead class="govuk-table__head"><tr>';
    echo '<th class="govuk-table__header">' . $sort_link('name', 'Supplier') . '</th>';
    echo '<th class="govuk-table__header">Type</th>';
    echo '<th class="govuk-table__header govuk-table__header--numeric">' . $sort_link('councils', 'Councils') . '</th>';
    echo '<th class="govuk-table__header govuk-table__header--numeric">' . $sort_link('contracts', 'Contracts') . '</th>';
    echo '<th class="govuk-table__header govuk-table__header--numeric">' . $sort_link('spend', 'Payments') . '</th>';
    echo '</tr></thead><tbody class="govuk-table__body">';
    foreach ($s_slice as $s) {
        $spend_val = (float)$s['total_spend'];
        if (!$spend_val) $spend = '—';
        elseif ($spend_val >= 1e6) $spend = '£' . number_format($spend_val / 1e6, 1) . 'M';
        elseif ($spend_val >= 1e3) $spend = '£' . number_format($spend_val / 1e3, 0) . 'k';
        else $spend = '£' . number_format($spend_val, 0);
        $sic = trim($s['sic_codes'] ?? '');
        $primary_sic = explode(',', $sic)[0] ?? '';
        $type_label = $type_overrides[$s['canonical_name']] ?? $sic_detail[$primary_sic] ?? ($sic_labels[substr($primary_sic, 0, 2)] ?? '');
        echo '<tr class="govuk-table__row">';
        echo '<td class="govuk-table__cell"><a class="govuk-link" href="/contracts/supplier.php?id=' . htmlspecialchars($s['company_number']) . '">' . htmlspecialchars($s['canonical_name']) . '</a></td>';
        echo '<td class="govuk-table__cell" style="font-size:0.8rem;color:#505a5f">' . htmlspecialchars($type_label) . '</td>';
        echo '<td class="govuk-table__cell govuk-table__cell--numeric">' . (int)$s['council_count'] . '</td>';
        echo '<td class="govuk-table__cell govuk-table__cell--numeric">' . ((int)$s['contract_count'] ?: '—') . '</td>';
        echo '<td class="govuk-table__cell govuk-table__cell--numeric">' . $spend . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    if ($s_total_pages > 1) {
        echo '<nav class="govuk-pagination" role="navigation" aria-label="Suppliers pagination">';
        $pg_qs = '?sort=' . $sort_col . '&dir=' . $sort_dir . $qs_base;
        if ($s_page > 1) echo '<div class="govuk-pagination__prev"><a class="govuk-link govuk-pagination__link" href="' . $pg_qs . '&page=' . ($s_page-1) . '" rel="prev"><span class="govuk-pagination__link-title">Previous</span></a></div>';
        echo '<ul class="govuk-pagination__list">';
        for ($p = 1; $p <= $s_total_pages; $p++) {
            if ($p === 1 || $p === $s_total_pages || abs($p - $s_page) <= 2) {
                echo '<li class="govuk-pagination__item' . ($p===$s_page?' govuk-pagination__item--current':'') . '"><a class="govuk-link govuk-pagination__link" href="' . $pg_qs . '&page=' . $p . '"' . ($p===$s_page?' aria-current="page"':'') . '>' . $p . '</a></li>';
            } elseif ($p === 2 || $p === $s_total_pages - 1) {
                echo '<li class="govuk-pagination__item govuk-pagination__item--ellipses">&ctdot;</li>';
            }
        }
        echo '</ul>';
        if ($s_page < $s_total_pages) echo '<div class="govuk-pagination__next"><a class="govuk-link govuk-pagination__link" href="' . $pg_qs . '&page=' . ($s_page+1) . '" rel="next"><span class="govuk-pagination__link-title">Next</span></a></div>';
        echo '</nav>';
    }
    layout_foot(true);
    exit;
} else {
    layout_head('Supplier not found');
    echo '<p class="govuk-body">Supplier not found.</p>';
    layout_foot(true);
    exit;
}

// ── Payments across all councils ─────────────────────────────────────────
$pay_stmt = $pdo->prepare("
    SELECT t.lad_code, t.council, cc.council_type, cc.region,
           SUM(t.amount) as total, COUNT(*) as cnt,
           MIN(t.paid_date) as first_pay, MAX(t.paid_date) as last_pay
    FROM ct_transparency_spend t
    LEFT JOIN ct_council_config cc ON cc.lad_code = t.lad_code
    WHERE t.supplier_canon = ? AND t.internal_provider = 0
      AND (t.validation_status IS NULL OR t.validation_status != 'invalid')
      AND NOT (cc.council_type = 'Strategic Authority' AND cc.region IN ('Scotland','Wales','Northern Ireland'))
    GROUP BY t.lad_code, t.council, cc.council_type, cc.region
    ORDER BY total DESC
");
$pay_stmt->execute([$search_name]);
$councils_paying = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Adur/Worthing shared "Joint" spend ─────────────────────────────────────
// Fold this supplier's joint-entity payments 50/50 into the real councils and
// drop the internal phantom row. PHP post-process on the already-fetched rows
// (no view — full-table view scans took the site down 2026-08-18).
$_jrows = array(); $_kept = array();
foreach ($councils_paying as $_r) {
    if (is_joint_council($_r['council'] ?? '')) $_jrows[] = $_r; else $_kept[] = $_r;
}
foreach ($_jrows as $_jr) {
    foreach (JOINT_SPLITS[$_jr['council']] as $_lad => $_share) {
        $_found = false;
        foreach ($_kept as &$_row) {
            if (($_row['lad_code'] ?? '') !== $_lad) continue;
            $_row['total'] = (float)$_row['total'] + (float)$_jr['total'] * $_share;
            $_row['cnt']   = (int)$_row['cnt'] + (int)round($_jr['cnt'] * $_share);
            if (!empty($_jr['first_pay']) && (empty($_row['first_pay']) || $_jr['first_pay'] < $_row['first_pay'])) $_row['first_pay'] = $_jr['first_pay'];
            if (!empty($_jr['last_pay'])  && (empty($_row['last_pay'])  || $_jr['last_pay']  > $_row['last_pay']))  $_row['last_pay']  = $_jr['last_pay'];
            $_found = true; break;
        }
        unset($_row);
        if (!$_found) {
            $_cfg = $pdo->prepare("SELECT council, council_type, region FROM ct_council_config WHERE lad_code = ?");
            $_cfg->execute([$_lad]);
            $_c = $_cfg->fetch(PDO::FETCH_ASSOC) ?: array('council' => $_lad, 'council_type' => null, 'region' => null);
            $_kept[] = array('lad_code' => $_lad, 'council' => $_c['council'], 'council_type' => $_c['council_type'],
                             'region' => $_c['region'], 'total' => (float)$_jr['total'] * $_share,
                             'cnt' => (int)round($_jr['cnt'] * $_share), 'first_pay' => $_jr['first_pay'], 'last_pay' => $_jr['last_pay']);
        }
    }
}
usort($_kept, function ($a, $b) { return (float)$b['total'] <=> (float)$a['total']; });
$councils_paying = $_kept;

$grand_total = array_sum(array_column($councils_paying, 'total'));
$total_payments = array_sum(array_column($councils_paying, 'cnt'));

// ── Contract register entries ────────────────────────────────────────────
$reg_stmt = $pdo->prepare("
    SELECT council, contract_title, value_amount, start_date, end_date, department
    FROM ct_contract_register_entries
    WHERE supplier_canon = ? AND is_tech = 1
    ORDER BY value_amount DESC
");
$reg_stmt->execute([$search_name]);
$register_entries = $reg_stmt->fetchAll(PDO::FETCH_ASSOC);
$reg_total = array_sum(array_map(fn($r) => (float)($r['value_amount'] ?? 0), $register_entries));

// ── Contract notices (Contracts Finder / FTS) ───────────────────────────
$con_stmt = $pdo->prepare("
    SELECT buyer_name, buyer_lad_code, title, value_amount, published_date, source, stage
    FROM ct_contracts
    WHERE supplier_names LIKE ?
    ORDER BY published_date DESC
    LIMIT 50
");
$con_stmt->execute(['%' . $search_name . '%']);
$contracts = $con_stmt->fetchAll(PDO::FETCH_ASSOC);
$contracts_total = array_sum(array_map(fn($c) => (float)($c['value_amount'] ?? 0), $contracts));
$con_dates = array_filter(array_column($contracts, 'published_date'));
$con_earliest = $con_dates ? min($con_dates) : null;
$con_latest = $con_dates ? max($con_dates) : null;

// ── Monthly trend across all councils ────────────────────────────────────
$trend_stmt = $pdo->prepare("
    SELECT period, SUM(amount) as total
    FROM ct_transparency_spend WHERE supplier_canon = ? AND internal_provider = 0 AND (validation_status IS NULL OR validation_status != 'invalid')
    GROUP BY period ORDER BY period
");
$trend_stmt->execute([$search_name]);
$trend = [];
foreach ($trend_stmt->fetchAll() as $r) $trend[$r['period']] = (float)$r['total'];

// ── Render ───────────────────────────────────────────────────────────────
layout_head($display_name . ' – Supplier Overview');
?>

<?php
$view = $_GET['view'] ?? 'payments';
$s_fy = in_array($_GET['fy'] ?? '', ['2025','2024','2023','2022']) ? $_GET['fy'] : '';
$today = date('Y-m-d');
$valid_first = array_filter(array_column($councils_paying, 'first_pay'), fn($d) => $d && $d <= $today);
$valid_last = array_filter(array_column($councils_paying, 'last_pay'), fn($d) => $d && $d <= $today);
$pay_first = $valid_first ? min($valid_first) : null;
$pay_last = $valid_last ? max($valid_last) : null;
$reg_starts = array_filter(array_column($register_entries, 'start_date'));
$reg_ends = array_filter(array_column($register_entries, 'end_date'));
$reg_first = $reg_starts ? min($reg_starts) : null;
$reg_last = $reg_ends ? max($reg_ends) : null;
$base_url = '?id=' . urlencode($company_number ?: $search_name) . ($s_fy ? '&fy=' . $s_fy : '');
$c_per_page = 25;
$c_total_pages = max(1, (int)ceil(count($councils_paying) / $c_per_page));
$c_page = max(1, min($c_total_pages, (int)($_GET['cpage'] ?? 1)));
$c_slice = array_slice($councils_paying, ($c_page - 1) * $c_per_page, $c_per_page);
$r_per_page = 25;
$r_total_pages = max(1, (int)ceil(count($register_entries) / $r_per_page));
$r_page = max(1, min($r_total_pages, (int)($_GET['rpage'] ?? 1)));
$r_slice = array_slice($register_entries, ($r_page - 1) * $r_per_page, $r_per_page);
?>

<div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:16px">
  <h1 class="govuk-heading-xl govuk-!-margin-bottom-2"><?= htmlspecialchars($display_name) ?></h1>
  <a href="/contracts/supplier.php" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0">&larr; All suppliers</a>
</div>
<p class="govuk-body-s" style="color:#505a5f;margin-bottom:24px">
  <?php if ($supplier_info): ?>
    <?php if ($display_name !== $search_name): ?>
      Trading as <strong><?= htmlspecialchars($search_name) ?></strong> ·
    <?php endif; ?>
    Company #<?= htmlspecialchars($company_number) ?>
    · <?= htmlspecialchars($supplier_info['company_status']) ?>
    <?php if ($supplier_info['sic_codes']): ?>
      · SIC: <?= htmlspecialchars($supplier_info['sic_codes']) ?>
    <?php endif; ?>
    <?php if ($supplier_info['incorporation_date']): ?>
      · Inc. <?= date('Y', strtotime($supplier_info['incorporation_date'])) ?>
    <?php endif; ?>
    <?php
      // Registry-aware external link: UK Companies House by default; foreign registers by `registry` flag.
      $reg = strtolower($supplier_info['registry'] ?? '') ?: 'uk';
      if ($reg === 'dk') {
          $ext_url = 'https://datacvr.virk.dk/enhed/virksomhed/' . urlencode($company_number);
          $ext_label = 'CVR (Denmark) &rarr;';
      } elseif ($reg === 'ca') {
          $ext_url = 'https://ised-isde.canada.ca/cbr-rec/results?q=' . urlencode($company_number);
          $ext_label = 'Canadian Registry &rarr;';
      } elseif ($reg === 'ie') {
          $ext_url = 'https://core.cro.ie/search?searchType=company&searchText=' . urlencode($company_number);
          $ext_label = 'CRO (Ireland) &rarr;';
      } elseif ($reg === 'be') {
          $ext_url = 'https://kbopub.economie.fgov.be/kbopub/zoeknummerform.html?nummer=' . urlencode(preg_replace('/\D/', '', $company_number)) . '&actionLu=Search';
          $ext_label = 'KBO/BCE (Belgium) &rarr;';
      } elseif ($reg === 'il') {
          $ext_url = 'https://ica.justice.gov.il/GenericCorporarion/SearchCorporation?unitType=1&mispar_ta=' . urlencode($company_number);
          $ext_label = 'Companies Registrar (Israel) &rarr;';
      } else {
          $ext_url = 'https://find-and-update.company-information.service.gov.uk/company/' . urlencode($company_number);
          $ext_label = 'Companies House &rarr;';
      }
    ?>
    · <a class="govuk-link" href="<?= $ext_url ?>" target="_blank" rel="noopener"><?= $ext_label ?></a>
  <?php else: ?>
    Canonical name: <?= htmlspecialchars($search_name) ?>
  <?php endif; ?>
</p>

<?php if ($supplier_info && $supplier_info['previous_names']): ?>
  <?php $prev = json_decode($supplier_info['previous_names'], true); ?>
  <?php if ($prev): ?>
  <details class="govuk-details govuk-!-margin-bottom-4">
    <summary class="govuk-details__summary"><span class="govuk-details__summary-text">Previous company names (<?= count($prev) ?>)</span></summary>
    <div class="govuk-details__text">
      <ul class="govuk-list govuk-list--bullet">
        <?php foreach ($prev as $pn): ?>
          <li><?= htmlspecialchars($pn) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </details>
  <?php endif; ?>
<?php endif; ?>

<!-- Summary cards with view links -->
<style>.ct-stat-card--active { border-left:4px solid #1d70b8; background:#f0f4f8; }</style>
<div class="govuk-grid-row govuk-!-margin-bottom-6">
  <div class="govuk-grid-column-one-third">
    <a href="<?= $base_url ?>&view=payments" class="ct-stat-card<?= $view==='payments'?' ct-stat-card--active':'' ?>" style="display:block;text-decoration:none;color:inherit">
      <div class="ct-stat-card__number"><?= fmt_value($grand_total) ?></div>
      <div class="ct-stat-card__label">Total payments</div>
      <div class="ct-stat-card__sub"><?= number_format($total_payments) ?> transactions · <?= count($councils_paying) ?> councils<?php if ($pay_first && $pay_last): ?><br><?= date('M Y', strtotime($pay_first)) ?> – <?= date('M Y', strtotime($pay_last)) ?><?php endif; ?></div>
    </a>
  </div>
  <div class="govuk-grid-column-one-third">
    <a href="<?= $base_url ?>&view=contracts" class="ct-stat-card<?= $view==='contracts'?' ct-stat-card--active':'' ?>" style="display:block;text-decoration:none;color:inherit">
      <div class="ct-stat-card__number"><?= fmt_value($reg_total) ?></div>
      <div class="ct-stat-card__label">Contracts</div>
      <div class="ct-stat-card__sub"><?= count($register_entries) ?> contracts · <?= count(array_unique(array_column($register_entries, 'council'))) ?> councils<?php if ($reg_first && $reg_last): ?><br><?= date('M Y', strtotime($reg_first)) ?> – <?= date('M Y', strtotime($reg_last)) ?><?php endif; ?></div>
    </a>
  </div>
  <div class="govuk-grid-column-one-third">
    <a href="<?= $base_url ?>&view=notices" class="ct-stat-card<?= $view==='notices'?' ct-stat-card--active':'' ?>" style="display:block;text-decoration:none;color:inherit">
      <div class="ct-stat-card__number"><?= fmt_value($contracts_total) ?></div>
      <div class="ct-stat-card__label">Contract notices</div>
      <div class="ct-stat-card__sub"><?= count($contracts) ?> on Contracts Finder / FTS<?php if ($con_earliest && $con_latest): ?><br><?= date('M Y', strtotime($con_earliest)) ?> – <?= date('M Y', strtotime($con_latest)) ?><?php endif; ?></div>
    </a>
  </div>
</div>


<?php if ($view === 'payments'): ?>
<!-- Monthly trend -->
<?php if ($trend): ?>
<h2 class="govuk-heading-m">Monthly payment trend</h2>
<?php
$tmax = max($trend);
$y_top = $tmax >= 1e6 ? '£' . number_format($tmax / 1e6, 1) . 'M' : ($tmax >= 1e3 ? '£' . number_format($tmax / 1e3, 0) . 'k' : '£' . number_format($tmax, 0));
$y_mid_val = $tmax / 2;
$y_mid = $y_mid_val >= 1e6 ? '£' . number_format($y_mid_val / 1e6, 1) . 'M' : ($y_mid_val >= 1e3 ? '£' . number_format($y_mid_val / 1e3, 0) . 'k' : '£' . number_format($y_mid_val, 0));
?>
<div style="position:relative;margin-bottom:32px">
  <div style="display:flex;gap:0">
    <div style="width:50px;height:100px;display:flex;flex-direction:column;justify-content:space-between;font-size:0.65rem;color:#505a5f;text-align:right;padding-right:6px">
      <span><?= $y_top ?></span>
      <span><?= $y_mid ?></span>
      <span>£0</span>
    </div>
    <div style="flex:1;display:flex;align-items:flex-end;gap:2px;height:100px;background:#f3f2f1;padding:8px 8px 0;border-radius:4px">
      <?php foreach ($trend as $p => $v): ?>
      <div style="flex:1;background:#1d70b8;min-width:3px;height:<?= $tmax ? round(100 * $v / $tmax) : 0 ?>%" title="<?= $p ?>: £<?= number_format($v, 0) ?>"></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div style="display:flex;gap:2px;padding:4px 0 0 50px;font-size:0.7rem;color:#505a5f">
    <?php
    $last_year = '';
    $mo_labels = ['01'=>'Jan','04'=>'Apr','07'=>'Jul','10'=>'Oct'];
    foreach ($trend as $p => $v):
        $yr = substr($p,0,4); $mo = substr($p,5,2);
        if ($mo === '04') { $lbl = $yr; }
        elseif (isset($mo_labels[$mo])) { $lbl = $mo_labels[$mo]; }
        else { $lbl = ''; }
    ?>
    <div style="flex:1;text-align:center;min-width:3px;overflow:hidden;font-size:0.6rem"><?= $lbl ?></div>
    <?php $last_year = $yr; endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Councils paying this supplier -->
<h2 class="govuk-heading-m">Councils (<?= count($councils_paying) ?>)</h2>
<table class="govuk-table">
  <thead class="govuk-table__head">
    <tr>
      <th class="govuk-table__header">Council</th>
      <th class="govuk-table__header">Type</th>
      <th class="govuk-table__header">Region</th>
      <th class="govuk-table__header govuk-table__header--numeric">Total paid</th>
      <th class="govuk-table__header govuk-table__header--numeric">Payments</th>
      <th class="govuk-table__header">Period</th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
    <?php foreach ($c_slice as $cp): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell">
        <a class="govuk-link" href="/contracts/council.php?id=<?= htmlspecialchars($cp['lad_code']) ?><?= $s_fy ? '&fy=' . $s_fy : '' ?>"><?= htmlspecialchars($cp['council']) ?></a>
      </td>
      <td class="govuk-table__cell" style="font-size:0.8rem"><?php
        $devolved_regions = ['Scotland','Wales','Northern Ireland'];
        $ctype = ($cp['council_type'] === 'Strategic Authority' && in_array($cp['region'] ?? '', $devolved_regions))
            ? 'National Government'
            : ($cp['council_type'] ?? '');
        echo htmlspecialchars($ctype);
      ?></td>
      <td class="govuk-table__cell" style="font-size:0.8rem"><?= htmlspecialchars($cp['region'] ?? '') ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= fmt_value((float)$cp['total']) ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= number_format((int)$cp['cnt']) ?></td>
      <td class="govuk-table__cell" style="font-size:0.8rem"><?= $cp['first_pay'] ? date('M Y', strtotime($cp['first_pay'])) : '' ?> – <?= $cp['last_pay'] ? date('M Y', strtotime($cp['last_pay'])) : '' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php if ($c_total_pages > 1): $base_url = '?id=' . urlencode($company_number ?: $search_name); ?>
<nav class="govuk-pagination" role="navigation" aria-label="Councils pagination">
  <?php if ($c_page > 1): ?>
  <div class="govuk-pagination__prev"><a class="govuk-link govuk-pagination__link" href="<?= $base_url ?>&cpage=<?= $c_page-1 ?>" rel="prev"><span class="govuk-pagination__link-title">Previous</span></a></div>
  <?php endif; ?>
  <ul class="govuk-pagination__list">
    <?php for ($p = 1; $p <= $c_total_pages; $p++): ?>
      <?php if ($p === 1 || $p === $c_total_pages || abs($p - $c_page) <= 2): ?>
      <li class="govuk-pagination__item<?= $p===$c_page?' govuk-pagination__item--current':'' ?>"><a class="govuk-link govuk-pagination__link" href="<?= $base_url ?>&cpage=<?= $p ?>"<?= $p===$c_page?' aria-current="page"':'' ?>><?= $p ?></a></li>
      <?php elseif ($p === 2 || $p === $c_total_pages - 1): ?>
      <li class="govuk-pagination__item govuk-pagination__item--ellipses">&ctdot;</li>
      <?php endif; ?>
    <?php endfor; ?>
  </ul>
  <?php if ($c_page < $c_total_pages): ?>
  <div class="govuk-pagination__next"><a class="govuk-link govuk-pagination__link" href="<?= $base_url ?>&cpage=<?= $c_page+1 ?>" rel="next"><span class="govuk-pagination__link-title">Next</span></a></div>
  <?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; // end view=payments ?>

<?php if ($view === 'contracts'): ?>
<!-- Contract register entries -->
<?php
$reg_councils = array_unique(array_column($r_slice, 'council'));
$lad_map = [];
if ($reg_councils) {
    $in = implode(',', array_map(fn($c) => $pdo->quote($c), $reg_councils));
    foreach ($pdo->query("SELECT council, lad_code FROM ct_council_config WHERE council IN ($in)")->fetchAll() as $row) {
        $lad_map[$row['council']] = $row['lad_code'];
    }
}
?>
<?php if ($register_entries): ?>
<h2 class="govuk-heading-m">Contracts (<?= count($register_entries) ?>)</h2>
<table class="govuk-table">
  <thead class="govuk-table__head">
    <tr>
      <th class="govuk-table__header">Council</th>
      <th class="govuk-table__header">Contract</th>
      <th class="govuk-table__header govuk-table__header--numeric">Value</th>
      <th class="govuk-table__header">Start</th>
      <th class="govuk-table__header">End</th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
    <?php foreach ($r_slice as $r): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><?php if (isset($lad_map[$r['council']])): ?><a class="govuk-link" href="/contracts/council.php?id=<?= htmlspecialchars($lad_map[$r['council']]) ?><?= $s_fy ? '&fy=' . $s_fy : '' ?>"><?= htmlspecialchars($r['council']) ?></a><?php else: ?><?= htmlspecialchars($r['council']) ?><?php endif; ?></td>
      <td class="govuk-table__cell" style="font-size:0.85rem;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($r['contract_title'] ?? '') ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric" style="font-size:0.85rem"><?= $r['value_amount'] ? '£' . number_format((float)$r['value_amount'], 0) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8rem"><?= $r['start_date'] ? date('M Y', strtotime($r['start_date'])) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8rem"><?= $r['end_date'] ? date('M Y', strtotime($r['end_date'])) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php if ($r_total_pages > 1): ?>
<nav class="govuk-pagination" role="navigation" aria-label="Contracts pagination">
  <?php if ($r_page > 1): ?>
  <div class="govuk-pagination__prev"><a class="govuk-link govuk-pagination__link" href="<?= $base_url ?>&view=contracts&rpage=<?= $r_page-1 ?>" rel="prev"><span class="govuk-pagination__link-title">Previous</span></a></div>
  <?php endif; ?>
  <ul class="govuk-pagination__list">
    <?php for ($p = 1; $p <= $r_total_pages; $p++): ?>
      <?php if ($p === 1 || $p === $r_total_pages || abs($p - $r_page) <= 2): ?>
      <li class="govuk-pagination__item<?= $p===$r_page?' govuk-pagination__item--current':'' ?>"><a class="govuk-link govuk-pagination__link" href="<?= $base_url ?>&view=contracts&rpage=<?= $p ?>"<?= $p===$r_page?' aria-current="page"':'' ?>><?= $p ?></a></li>
      <?php elseif ($p === 2 || $p === $r_total_pages - 1): ?>
      <li class="govuk-pagination__item govuk-pagination__item--ellipses">&ctdot;</li>
      <?php endif; ?>
    <?php endfor; ?>
  </ul>
  <?php if ($r_page < $r_total_pages): ?>
  <div class="govuk-pagination__next"><a class="govuk-link govuk-pagination__link" href="<?= $base_url ?>&view=contracts&rpage=<?= $r_page+1 ?>" rel="next"><span class="govuk-pagination__link-title">Next</span></a></div>
  <?php endif; ?>
</nav>
<?php endif; ?>
<?php else: ?>
  <p class="govuk-body">No contract register entries found for this supplier.</p>
<?php endif; ?>
<?php endif; // end view=contracts ?>

<?php if ($view === 'notices'): ?>
<!-- Contract notices (Contracts Finder / FTS) -->
<?php if ($contracts): ?>
<h2 class="govuk-heading-m">Contract notices (<?= count($contracts) ?>)</h2>
<table class="govuk-table">
  <thead class="govuk-table__head">
    <tr>
      <th class="govuk-table__header">Buyer</th>
      <th class="govuk-table__header">Title</th>
      <th class="govuk-table__header govuk-table__header--numeric">Value</th>
      <th class="govuk-table__header">Date</th>
      <th class="govuk-table__header">Source</th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
    <?php foreach ($contracts as $c): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell">
        <?php if ($c['buyer_lad_code']): ?>
          <a class="govuk-link" href="/contracts/council.php?id=<?= htmlspecialchars($c['buyer_lad_code']) ?><?= $s_fy ? '&fy=' . $s_fy : '' ?>"><?= htmlspecialchars(mb_substr($c['buyer_name'], 0, 40)) ?></a>
        <?php else: ?>
          <?= htmlspecialchars(mb_substr($c['buyer_name'], 0, 40)) ?>
        <?php endif; ?>
      </td>
      <td class="govuk-table__cell" style="font-size:0.85rem"><?= htmlspecialchars(mb_substr($c['title'] ?? '', 0, 60)) ?><?= mb_strlen($c['title'] ?? '') > 60 ? '&hellip;' : '' ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= $c['value_amount'] ? '£' . number_format((float)$c['value_amount'], 0) : '—' ?></td>
      <td class="govuk-table__cell" style="font-size:0.8rem"><?= $c['published_date'] ?? '—' ?></td>
      <td class="govuk-table__cell"><span class="govuk-tag govuk-tag--grey" style="font-size:0.7rem"><?= htmlspecialchars($c['source']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?>
  <p class="govuk-body">No contract notices found on Contracts Finder or Find a Tender for this supplier.</p>
<?php endif; ?>
<?php endif; // end view=notices ?>

<?php
layout_foot(true);
