<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// Exclude internal joint/shared-service entities (Adur/Worthing) from the count.
$_joint_ex = '';
if (joint_council_names()) {
    $_q = array();
    foreach (joint_council_names() as $_n) $_q[] = "'" . str_replace("'", "''", $_n) . "'";
    $_joint_ex = ' WHERE council NOT IN (' . implode(',', $_q) . ')';
}
$all_councils_count = (int)$pdo->query("SELECT COUNT(DISTINCT council) FROM ct_transparency_spend" . $_joint_ex)->fetchColumn();
$all_cas_count      = (int)$pdo->query("SELECT COUNT(DISTINCT ca_name) FROM ct_ca_spend")->fetchColumn();

// Ensure table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS ct_transparency_spend (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        council        VARCHAR(100)  NOT NULL,
        supplier_raw   VARCHAR(300)  NOT NULL,
        supplier_canon VARCHAR(200)  NOT NULL,
        service        VARCHAR(200),
        amount         DECIMAL(14,2) NOT NULL,
        paid_date      DATE,
        period         VARCHAR(7)    NOT NULL,
        source_id      INT UNSIGNED  NULL,
        fetched_at     DATETIME      NOT NULL,
        INDEX idx_council  (council(80)),
        INDEX idx_supplier (supplier_canon(100)),
        INDEX idx_period   (period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$section   = $_GET['section']   ?? 'overview';
$council   = $_GET['council']   ?? '';
// Council links use the ONS lad_code where one exists (stable, no urlencoding
// needed) rather than the free-text council name. Resolve it back to the name
// every other query/comparison in this file still expects. Councils with no
// lad_code (the 21 two-tier county councils, which span multiple LADs) keep
// using the name directly, so this still works unresolved for those.
if ($council !== '' && preg_match('/^[ESWN]\d{8}$/', $council)) {
    $lookup = $pdo->prepare('SELECT council FROM ct_council_config WHERE lad_code = ?');
    $lookup->execute([$council]);
    $council = $lookup->fetchColumn() ?: $council;
}
$supplier  = $_GET['supplier']  ?? '';
// Used to build council-detail links: lad_code where available, else the
// name (the 21 two-tier county councils have no lad_code -- they span
// multiple LADs so there's no single code to use).
function council_url_id(string $name, ?string $lad_code): string {
    return $lad_code !== null && $lad_code !== '' ? $lad_code : urlencode($name);
}
$period    = $_GET['period']    ?? '';
$ctype_selected  = $_GET['ctype']  ?? '';
$region_selected = $_GET['region'] ?? '';
$ca_selected     = $_GET['ca']     ?? '';
$nation_selected = $_GET['nation']  ?? '';
$nation_prefixes = ['England' => 'E', 'Scotland' => 'S', 'Wales' => 'W', 'Northern Ireland' => 'N'];
$council_search  = trim($_GET['q'] ?? '');
$council_sort_options = [
    'total_desc'    => 'Total spend (high to low)',
    'total_asc'     => 'Total spend (low to high)',
    'payments_desc' => 'Transactions (high to low)',
    'payments_asc'  => 'Transactions (low to high)',
    'name_asc'      => 'Council name (A-Z)',
    'name_desc'     => 'Council name (Z-A)',
];
$council_sort = $_GET['sort'] ?? 'total_desc';
if (!array_key_exists($council_sort, $council_sort_options)) $council_sort = 'total_desc';
// ── Financial year helper ─────────────────────────────────────────────────
$fy_options = [
    ''        => 'All time',
    '2026-27' => '2026/27',
    '2025-26' => '2025/26',
    '2024-25' => '2024/25',
    '2023-24' => '2023/24',
    '2022-23' => '2022/23',
];
function fy_dates(string $fy): array {
    if (!$fy) return ['', ''];
    $y1 = (int)substr($fy, 0, 4);
    $y2 = $y1 + 1;
    return ["{$y1}-04-01", "{$y2}-03-31"];
}
$fy_selected = $_GET['fy'] ?? '2025-26';
if (!array_key_exists($fy_selected, $fy_options)) $fy_selected = '2025-26';
$year_selected = $_GET['year'] ?? '';
if ($year_selected !== '') {
    [$date_from, $date_to] = ["{$year_selected}-01-01", "{$year_selected}-12-31"];
} else {
    [$date_from, $date_to] = fy_dates($fy_selected);
}

// ── Shared data ────────────────────────────────────────────────────────────
// Build overview date filter (applies to all sections including overview)
$ov_where  = ["internal_provider = 0", "(validation_status IS NULL OR validation_status != 'invalid')"];
$ov_params = [];
if ($date_from) { $ov_where[] = 'paid_date >= :date_from'; $ov_params[':date_from'] = $date_from; }
if ($date_to)   { $ov_where[] = 'paid_date <= :date_to';   $ov_params[':date_to']   = $date_to; }
$ovWhereSQL = implode(' AND ', $ov_where);
// CA-safe version of ovWhereSQL — ct_ca_spend has no internal_provider column
$ca_ov_where  = ['1=1'];
if ($date_from) { $ca_ov_where[] = 'paid_date >= :date_from'; }
if ($date_to)   { $ca_ov_where[] = 'paid_date <= :date_to'; }
$caOvWhereSQL = implode(' AND ', $ca_ov_where);

// Council type / region filters (applied via a join to ct_council_config)
$cf_where  = $ov_where;
$cf_params = $ov_params;
// Exclude devolved/strategic-authority entities (e.g. Welsh Government) from the
// stat-card totals, mirroring the council-list filter ($cc_where below) so the
// "Councils" count and the other aggregates agree with the list beneath them.
// Without this the Wales/Scotland/NI nation filters over-count by including the
// national government entity. Matches council.php / supplier.php SA exclusion.
$cf_where[] = "cc.council_type != 'Strategic Authority'";
if ($ctype_selected)  { $cf_where[] = 'cc.council_type = :ctype';  $cf_params[':ctype']  = $ctype_selected; }
if ($region_selected) { $cf_where[] = 'cc.region = :region';       $cf_params[':region'] = $region_selected; }
if ($nation_selected && isset($nation_prefixes[$nation_selected])) {
    $cf_where[] = "cc.lad_code LIKE :nation_pfx_cf";
    $cf_params[':nation_pfx_cf'] = $nation_prefixes[$nation_selected] . '%';
}
if ($ca_selected) {
    $cf_where[] = 'cc.combined_authority = :ca';
    $cf_params[':ca'] = $ca_selected;
    if ($ca_selected === 'No current strategic authority') {
        $cf_where[] = "cc.council_type != 'Strategic Authority'";
    }
}
if ($section === 'councils' && $council_search !== '') {
    $cf_where[] = 'ct.council LIKE :csearch';
    $cf_params[':csearch'] = '%' . $council_search . '%';
}
// England-only default must also apply to the stat-card count ($cf_where),
// not just the list below -- else the Councils count includes all nations
// while the list shows England only.
$england_only = !$region_selected && !$ca_selected && !$nation_selected && $council === '';
if ($england_only) { $cf_where[] = 'cc.lad_code LIKE \'E%\''; }
$cfWhereSQL = implode(' AND ', $cf_where);

// Fast path: push the amount/date/search filter into the GROUP BY aggregation
// over ct_transparency_spend alone (single table, single-column GROUP BY),
// then join the much smaller ct_council_config (162 rows) onto the already-
// aggregated result. Joining before grouping (as the single-query version
// above used to do) forces a 6-column GROUP BY across the join output and
// makes COUNT(DISTINCT supplier_canon) per group dramatically more expensive
// — ~6s vs ~0.1s on this table size — even though the result is identical.
$sp_where  = $ov_where;
$sp_params = $ov_params;
if ($section === 'councils' && $council_search !== '') {
    $sp_where[] = 'council LIKE :csearch';
    $sp_params[':csearch'] = '%' . $council_search . '%';
}
// Default to England-only unless a region, CA, or nation filter is set, or a
// specific council is requested (view its breakdown regardless of nation).
$england_only = !$region_selected && !$ca_selected && !$nation_selected && $council === '';
if ($england_only) { $sp_where[] = "lad_code LIKE 'E%'"; }
elseif ($nation_selected && isset($nation_prefixes[$nation_selected])) {
    $sp_where[] = "lad_code LIKE :nation_pfx";
    $sp_params[':nation_pfx'] = $nation_prefixes[$nation_selected] . '%';
}
$spWhereSQL = implode(' AND ', $sp_where);

$cc_where  = [];
$cc_where[] = "cc.council_type != 'Strategic Authority'";
if ($england_only)    { $cc_where[] = "cc.lad_code LIKE 'E%'"; }
elseif ($nation_selected && isset($nation_prefixes[$nation_selected])) {
    $cc_where[] = "cc.lad_code LIKE :nation_pfx2";
}
$cc_params = [];
if ($nation_selected && isset($nation_prefixes[$nation_selected])) {
    $cc_params[':nation_pfx2'] = $nation_prefixes[$nation_selected] . '%';
}
if ($ctype_selected)  { $cc_where[] = 'cc.council_type = :ctype';  $cc_params[':ctype']  = $ctype_selected; }
if ($region_selected) { $cc_where[] = 'cc.region = :region';       $cc_params[':region'] = $region_selected; }
if ($ca_selected) {
    $cc_where[] = 'cc.combined_authority = :ca';
    $cc_params[':ca'] = $ca_selected;
    if ($ca_selected === 'No current strategic authority') {
        $cc_where[] = "cc.council_type != 'Strategic Authority'";
    }
}
$ccWhereSQL = $cc_where ? ('WHERE ' . implode(' AND ', $cc_where)) : '';

$council_sort_sql_map = [
    'total_desc'    => 'total DESC',
    'total_asc'     => 'total ASC',
    'payments_desc' => 'payments DESC',
    'payments_asc'  => 'payments ASC',
    'name_asc'      => 's.council ASC',
    'name_desc'     => 's.council DESC',
];
$council_order_by = $section === 'councils' ? $council_sort_sql_map[$council_sort] : 'total DESC';

$council_totals_stmt = $pdo->prepare("
    SELECT s.council,
           cc.lad_code,
           cc.council_type,
           cc.region,
           cc.combined_authority,
           cc.population,
           cc.listing_url,
           r.net_revenue_exp, r.budget_year, r.imd_rank,
           s.total, s.payments, s.suppliers, s.avg_payment, s.max_payment, s.from_date, s.to_date
    FROM (
        SELECT council,
               SUM(amount) AS total,
               COUNT(*) AS payments,
               COUNT(DISTINCT supplier_canon) AS suppliers,
               AVG(amount) AS avg_payment,
               MAX(amount) AS max_payment,
               MIN(paid_date) AS from_date,
               MAX(paid_date) AS to_date
        FROM ct_transparency_spend
        WHERE {$spWhereSQL}
        GROUP BY council
    ) s
    LEFT JOIN ct_council_config cc ON cc.council = s.council
    LEFT JOIN ct_la_reference r ON r.lad_code = cc.lad_code
    {$ccWhereSQL}
    ORDER BY {$council_order_by}
");
$council_totals_stmt->execute(array_merge($sp_params, $cc_params));
$council_totals = $council_totals_stmt->fetchAll();

// ── Adur/Worthing shared "Joint" spend ─────────────────────────────────────
// Drop the internal joint entity (phantom council) from the ranking and fold
// its spend 50/50 into the real councils. PHP post-process on the fetched rows
// + one tiny base-table query per joint entity — NEVER query ct_spend_display
// here: an unfiltered full-table view scan took the whole site down 2026-08-18.
$council_totals = array_values(array_filter($council_totals, function ($r) {
    return !is_joint_council($r['council'] ?? '');
}));
foreach (JOINT_SPLITS as $jointName => $targets) {
    $jstmt = $pdo->prepare(
        "SELECT SUM(amount) total, COUNT(*) payments, COUNT(DISTINCT supplier_canon) suppliers,
                MAX(amount) max_payment, MIN(paid_date) from_date, MAX(paid_date) to_date
           FROM ct_transparency_spend WHERE council = :jc AND {$ovWhereSQL}"
    );
    $jstmt->execute(array_merge([':jc' => $jointName], $ov_params));
    $j = $jstmt->fetch(PDO::FETCH_ASSOC);
    if (!$j || $j['total'] === null) continue;
    foreach ($targets as $lad => $share) {
        foreach ($council_totals as &$row) {
            if (($row['lad_code'] ?? '') !== $lad) continue;
            $row['total']       = (float)$row['total'] + (float)$j['total'] * $share;
            $row['payments']    = (int)$row['payments'] + (int)round($j['payments'] * $share);
            $row['max_payment'] = max((float)$row['max_payment'], (float)$j['max_payment'] * $share);
            $row['avg_payment'] = $row['payments'] > 0 ? $row['total'] / $row['payments'] : ($row['avg_payment'] ?? 0);
            if (!empty($j['from_date']) && (empty($row['from_date']) || $j['from_date'] < $row['from_date'])) $row['from_date'] = $j['from_date'];
            if (!empty($j['to_date'])   && (empty($row['to_date'])   || $j['to_date']   > $row['to_date']))   $row['to_date']   = $j['to_date'];
            break;
        }
        unset($row);
    }
}
// Re-sort to reflect the folded totals (mirrors the SQL ORDER BY options).
usort($council_totals, function ($a, $b) use ($council_sort) {
    switch ($council_sort) {
        case 'total_asc':     return (float)$a['total']    <=> (float)$b['total'];
        case 'payments_desc': return (int)$b['payments']   <=> (int)$a['payments'];
        case 'payments_asc':  return (int)$a['payments']   <=> (int)$b['payments'];
        case 'name_asc':      return strcasecmp($a['council'], $b['council']);
        case 'name_desc':     return strcasecmp($b['council'], $a['council']);
        default:              return (float)$b['total']    <=> (float)$a['total'];
    }
});

// total_row only needs the join when filtering by council type/region/CA —
// those live on ct_council_config and can't be resolved without it. The
// common case (no such filter) skips the join entirely.
if ($cc_where) {
    $total_row_stmt = $pdo->prepare("
        SELECT COUNT(*) AS payments,
               COUNT(DISTINCT ct.supplier_canon) AS suppliers,
               COUNT(DISTINCT ct.council) AS councils,
               SUM(ct.amount) AS total
        FROM ct_transparency_spend ct
        LEFT JOIN ct_council_config cc ON cc.council = ct.council
        WHERE {$cfWhereSQL}
    ");
    $total_row_stmt->execute($cf_params);
} else {
    $total_row_stmt = $pdo->prepare("
        SELECT COUNT(*) AS payments,
               COUNT(DISTINCT supplier_canon) AS suppliers,
               COUNT(DISTINCT council) AS councils,
               SUM(amount) AS total
        FROM ct_transparency_spend
        WHERE {$spWhereSQL}
    ");
    $total_row_stmt->execute($sp_params);
}
$total_row = $total_row_stmt->fetch();


// Period coverage for the selected financial year
$coverage_pct = null;
if ($fy_selected && !$year_selected) {
    $y1 = (int)substr($fy_selected, 0, 4);
    $y2 = $y1 + 1;
    $period_from   = "{$y1}-04";
    $date_cov_from = "{$y1}-04-01";
    $fy_end_ym     = "{$y2}-03";
    // Only part of an in-progress FY has elapsed, so dividing by a full 12
    // months would cap coverage far below 100% until 31 March regardless of how
    // complete the published data is. Cap the window at the last FULLY-COMPLETED
    // calendar month (no publishing-lag allowance) and measure coverage over
    // just those elapsed months. A past/completed FY is unaffected: its own
    // March is <= last-complete-month, so the cutoff stays March and $months=12.
    $last_complete_ym = date('Y-m', strtotime('first day of previous month'));
    $cutoff_ym = min($fy_end_ym, $last_complete_ym); // YYYY-MM sorts lexically
    if ($cutoff_ym < $period_from) {
        $months = 0; // FY only just begun -- no complete month yet, coverage n/a
    } else {
        $cy = (int)substr($cutoff_ym, 0, 4);
        $cm = (int)substr($cutoff_ym, 5, 2);
        $months = ($cy - $y1) * 12 + ($cm - 4) + 1;
    }
    $period_to   = $cutoff_ym;
    $date_cov_to = date('Y-m-t', strtotime("{$cutoff_ym}-01")); // last day of cutoff month
    $total_shown = (int)$total_row['councils'];
    $possible    = $total_shown * $months;
    if ($possible > 0) {
        // A period is "covered" either by real data rows OR by a source that
        // was actively polled and genuinely returned zero tech-spend matches
        // (ct_spend_sources.active=1 AND last_count=0 -- checked, confirmed
        // empty, not an unfetched gap). Deactivated sources are deliberately
        // NOT included here -- those are excluded-by-design, not "covered".
        if ($cc_where) {
            $cov_stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT council_period) FROM (
                    SELECT CONCAT(ct.council, '|', DATE_FORMAT(ct.paid_date,'%Y-%m')) AS council_period
                    FROM ct_transparency_spend ct
                    LEFT JOIN ct_council_config cc ON cc.council = ct.council
                    WHERE ct.paid_date BETWEEN :df AND :dt
                    AND " . implode(' AND ', $cc_where) . "
                    UNION
                    SELECT CONCAT(s.council, '|', s.period) AS council_period
                    FROM ct_spend_sources s
                    LEFT JOIN ct_council_config cc ON cc.council = s.council
                    WHERE s.active = 1 AND s.last_count = 0
                    AND s.period BETWEEN :pf AND :pt
                    AND " . implode(' AND ', $cc_where) . "
                ) combined");
            $cov_stmt->execute(array_merge([':pf' => $period_from, ':pt' => $period_to, ':df' => $date_cov_from, ':dt' => $date_cov_to], $cc_params));
        } else {
            $cov_stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT council_period) FROM (
                    SELECT CONCAT(council, '|', DATE_FORMAT(paid_date,'%Y-%m')) AS council_period
                    FROM ct_transparency_spend
                    WHERE paid_date BETWEEN :df AND :dt
                    UNION
                    SELECT CONCAT(council, '|', period) AS council_period
                    FROM ct_spend_sources
                    WHERE active = 1 AND last_count = 0
                    AND period BETWEEN :pf AND :pt
                ) combined
            ");
            $cov_stmt->execute([':pf' => $period_from, ':pt' => $period_to, ':df' => $date_cov_from, ':dt' => $date_cov_to]);
        }
        $actual = (int)$cov_stmt->fetchColumn();
        $coverage_pct = round($actual / $possible * 100);
    }
} else {
    // All-time coverage: actual council+period combos vs theoretical max
    // Theoretical max = councils in view × months from 2022-04 to latest period
    $earliest = '2022-04';
    // Cap at end of previous financial year (March) so outlier councils publishing
    // current-FY data early don't inflate the denominator.
    $cur_month = (int)date('n');
    $cur_year  = (int)date('Y');
    $prev_fy_end = ($cur_month >= 4 ? $cur_year : $cur_year - 1) . '-03';
    $latest_period = $pdo->query("SELECT LEAST(MAX(period), '$prev_fy_end') FROM ct_transparency_spend WHERE lad_code LIKE 'E%'")->fetchColumn();
    if ($latest_period) {
        [$ly, $lm] = explode('-', $latest_period);
        [$ey, $em] = explode('-', $earliest);
        $earliest_date = $earliest . '-01';
        $latest_date   = date('Y-m-t', strtotime($latest_period . '-01'));
        $total_months = ($ly - $ey) * 12 + ($lm - $em) + 1;
        // Use English-only council count so numerator and denominator match.
        // $total_row['councils'] includes Scottish/Welsh/NI councils which are
        // excluded from the numerator's lad_code LIKE 'E%' filter.
        if ($cc_where) {
            $eng_count_stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT ct.council)
                FROM ct_transparency_spend ct
                LEFT JOIN ct_council_config cc ON cc.council = ct.council
                WHERE ct.lad_code LIKE 'E%' AND " . implode(' AND ', $cc_where));
            $eng_count_stmt->execute($cc_params);
        } else {
            $eng_count_stmt = $pdo->query("
                SELECT COUNT(DISTINCT council)
                FROM ct_transparency_spend
                WHERE lad_code LIKE 'E%'");
        }
        $english_councils = (int)$eng_count_stmt->fetchColumn();
        $possible_all = $english_councils * $total_months;
        if ($possible_all > 0) {
            // Same checked-empty-counts-as-covered treatment as the FY block
            // above, bounded to the same $earliest..$latest_period window the
            // denominator uses.
            if ($cc_where) {
                $cov_stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT council_period) FROM (
                        SELECT CONCAT(ct.council, '|', DATE_FORMAT(ct.paid_date,'%Y-%m')) AS council_period
                        FROM ct_transparency_spend ct
                        LEFT JOIN ct_council_config cc ON cc.council = ct.council
                        WHERE ct.lad_code LIKE 'E%' AND ct.paid_date BETWEEN :df AND :dt AND " . implode(' AND ', $cc_where) . "
                        UNION
                        SELECT CONCAT(s.council, '|', s.period) AS council_period
                        FROM ct_spend_sources s
                        LEFT JOIN ct_council_config cc ON cc.council = s.council
                        WHERE s.active = 1 AND s.last_count = 0
                        AND s.lad_code LIKE 'E%'
                        AND s.period BETWEEN :pf AND :pt
                        AND " . implode(' AND ', $cc_where) . "
                    ) combined");
                $cov_stmt->execute(array_merge([':pf' => $earliest, ':pt' => $latest_period, ':df' => $earliest_date, ':dt' => $latest_date], $cc_params));
            } else {
                $cov_stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT council_period) FROM (
                        SELECT CONCAT(council, '|', DATE_FORMAT(paid_date,'%Y-%m')) AS council_period
                        FROM ct_transparency_spend
                        WHERE lad_code LIKE 'E%' AND paid_date BETWEEN :df AND :dt
                        UNION
                        SELECT CONCAT(council, '|', period) AS council_period
                        FROM ct_spend_sources
                        WHERE active = 1 AND last_count = 0
                        AND lad_code LIKE 'E%'
                        AND period BETWEEN :pf AND :pt
                    ) combined
                ");
                $cov_stmt->execute([':pf' => $earliest, ':pt' => $latest_period, ':df' => $earliest_date, ':dt' => $latest_date]);
            }
            $actual_all = (int)$cov_stmt->fetchColumn();
            $coverage_pct = round($actual_all / $possible_all * 100);
        }
    }
}
// Derive filter options from already-fetched council_totals (avoids expensive subquery)
$ctype_options = [];
$region_options = [];
$ca_options = [];
foreach ($council_totals as $row) {
    if (($row['council_type'] ?? '') !== '') $ctype_options[$row['council_type']] = true;
    if (($row['region'] ?? '') !== '') $region_options[$row['region']] = true;
    if (($row['combined_authority'] ?? '') !== '') $ca_options[$row['combined_authority']] = true;
}
$ctype_options = array_keys($ctype_options);
$region_options = array_keys($region_options);
$ca_options = array_keys($ca_options);
sort($ctype_options);
sort($region_options);
sort($ca_options);

// Council list for the supplier/provider Council filter, alphabetical.
// On a supplier/provider page, scope it to the councils that supplier actually
// traded with (any nation) -- otherwise the filter hides councils the supplier
// did business with (e.g. XMA <-> Fife). With no supplier selected, fall back
// to the English-only default list.
if ($supplier) {
    // Scope to councils this supplier traded with (any nation); LEFT JOIN
    // config so the option value can be the lad_code (councils without one
    // fall back to the name, matching council_url_id()).
    $cfo_stmt = $pdo->prepare(
        "SELECT DISTINCT ct.council, cc.lad_code FROM ct_transparency_spend ct
         LEFT JOIN ct_council_config cc ON cc.council = ct.council
         WHERE ct.internal_provider = 0
           AND (ct.validation_status IS NULL OR ct.validation_status != 'invalid')
           AND ct.supplier_canon LIKE :supplier
         ORDER BY ct.council"
    );
    $cfo_stmt->execute([':supplier' => '%' . $supplier . '%']);
    $council_filter_options = $cfo_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $cfo_stmt = $pdo->prepare(
        "SELECT DISTINCT ct.council, cc.lad_code FROM ct_transparency_spend ct
         JOIN ct_council_config cc ON cc.council = ct.council
         WHERE cc.lad_code LIKE 'E%'
           AND cc.council_type != 'Strategic Authority'
           AND ct.internal_provider = 0
           AND (ct.validation_status IS NULL OR ct.validation_status != 'invalid')
         ORDER BY ct.council"
    );
    $cfo_stmt->execute();
    $council_filter_options = $cfo_stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Never offer the internal joint/shared-service entity in the council dropdown.
$council_filter_options = array_values(array_filter($council_filter_options, function ($o) {
    return !is_joint_council($o['council'] ?? '');
}));

$periods = $pdo->query("
    SELECT DISTINCT period FROM ct_transparency_spend ORDER BY period DESC
")->fetchAll(PDO::FETCH_COLUMN);

$year_options = ['' => 'All time'];
$years_seen = [];
foreach ($periods as $p) { $years_seen[substr($p, 0, 4)] = true; }
krsort($years_seen);
foreach (array_keys($years_seen) as $y) { $year_options[$y] = $y; }
if (!array_key_exists($year_selected, $year_options)) $year_selected = '';

// Filtered supplier data (used in suppliers section)
$where  = ['internal_provider = 0', "(validation_status IS NULL OR validation_status != 'invalid')"];
$params = [];
if ($council)  { $where[] = 'council = :council';             $params[':council']  = $council; }
if ($supplier) { $where[] = 'supplier_canon LIKE :supplier';  $params[':supplier'] = '%'.$supplier.'%'; }
if ($period)   { $where[] = 'period = :period';               $params[':period']   = $period; }
if ($date_from) { $where[] = 'paid_date >= :date_from'; $params[':date_from'] = $date_from; }
if ($date_to)   { $where[] = 'paid_date <= :date_to';   $params[':date_to']   = $date_to; }
$whereSQL = implode(' AND ', $where);
// Table-qualified copy for queries that JOIN ct_spend_sources (which also
// has `council` and `period` columns) -- bare names would be ambiguous.
$whereSQL_t = preg_replace('/(?<![.\w:])(council|period|supplier_canon|paid_date|internal_provider|validation_status)\b/', 't.$1', $whereSQL);

$suppliers_per_page = 40;
$suppliers_total_count = 0;
$suppliers_total_pages = 1;
$suppliers_page   = 1;
$suppliers_offset = 0;
$top_suppliers = [];
$ca_counts = [];

// These are only rendered on the "Supplier payments" section — skip the
// queries entirely on other sections (overview/councils/sa_overview/sa_detail
// all load this file too, and previously paid for these on every load).
if ($section === 'suppliers') {
    $suppliers_count_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM (
            SELECT supplier_canon FROM ct_transparency_spend WHERE {$whereSQL} GROUP BY supplier_canon
        ) t
    ");
    $suppliers_count_stmt->execute($params);
    $suppliers_total_count = (int)$suppliers_count_stmt->fetchColumn();
    $suppliers_total_pages = max(1, (int)ceil($suppliers_total_count / $suppliers_per_page));
    $suppliers_page   = max(1, min($suppliers_total_pages, (int)($_GET['spage'] ?? 1)));
    $suppliers_offset = ($suppliers_page - 1) * $suppliers_per_page;

    $top_suppliers_stmt = $pdo->prepare("
        SELECT supplier_canon,
               SUM(amount) AS total,
               COUNT(*) AS payments,
               COUNT(DISTINCT council) AS councils,
               MAX(paid_date) AS last_payment
        FROM ct_transparency_spend
        WHERE {$whereSQL}
        GROUP BY supplier_canon
        ORDER BY total DESC
        LIMIT {$suppliers_per_page} OFFSET {$suppliers_offset}
    ");
    $top_suppliers_stmt->execute($params);
    $top_suppliers = $top_suppliers_stmt->fetchAll();

    // CAs using each supplier on this page — combined authorities' own direct
    // spend (ct_ca_spend) only. Councils' combined_authority membership is a
    // regional grouping, not the SA body itself paying the supplier, so it's
    // not counted here (otherwise the count is bounded by ~16 CA regions
    // instead of how many CAs we've actually imported direct spend for).
    $page_supplier_names = array_column($top_suppliers, 'supplier_canon');
    if ($page_supplier_names && !$council) {
        $ph = implode(',', array_fill(0, count($page_supplier_names), '?'));
        $own_ca_where  = [ "supplier_canon IN ({$ph})"];
        $own_ca_params = $page_supplier_names;
        if ($period)    { $own_ca_where[] = 'period = ?';     $own_ca_params[] = $period; }
        if ($date_from) { $own_ca_where[] = 'paid_date >= ?'; $own_ca_params[] = $date_from; }
        if ($date_to)   { $own_ca_where[] = 'paid_date <= ?'; $own_ca_params[] = $date_to; }
        $own_ca_sql = implode(' AND ', $own_ca_where);
        $ca_own_stmt = $pdo->prepare("
            SELECT supplier_canon, ca_name
            FROM ct_ca_spend
            WHERE {$own_ca_sql}
            GROUP BY supplier_canon, ca_name
        ");
        $ca_own_stmt->execute($own_ca_params);
        foreach ($ca_own_stmt->fetchAll() as $row) {
            $ca_counts[$row['supplier_canon']][$row['ca_name']] = true;
        }
    }
}

// ── Detail view (supplier and/or council drill-down) ───────────────────────
// Split into two tabs — "Totals by organisation" and "Transactions" — each
// paginated and loaded 20 rows at a time to keep the page fast.
$detail_tab      = ($_GET['tab'] ?? 'totals') === 'transactions' ? 'transactions' : 'totals';
$detail_per_page = 20;

$supplier_council_totals = [];
$sct_total_count    = 0;
$recent             = [];
$recent_total_count = 0;
$detail_page        = 1;
$detail_total_pages = 1;

// Combined authorities' own direct spend (ct_ca_spend) is folded into the
// "Totals by organisation" tab alongside councils, since a CA isn't a
// constituent council itself -- only meaningful when not already filtered
// to one specific council.
$caWhereSQL = '';
if (!$council) {
    $ca_where = ['1=1'];
    if ($supplier)  { $ca_where[] = 'supplier_canon LIKE :supplier'; }
    if ($period)    { $ca_where[] = 'period = :period'; }
    if ($date_from) { $ca_where[] = 'paid_date >= :date_from'; }
    if ($date_to)   { $ca_where[] = 'paid_date <= :date_to'; }
    $caWhereSQL = implode(' AND ', $ca_where);
}

if ($supplier || $council) {
    if ($caWhereSQL) {
        $sct_count_stmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM (SELECT council FROM ct_transparency_spend WHERE {$whereSQL} GROUP BY council) t1)
              + (SELECT COUNT(*) FROM (SELECT ca_name FROM ct_ca_spend WHERE {$caWhereSQL} GROUP BY ca_name) t2)
        ");
    } else {
        $sct_count_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM (
                SELECT council FROM ct_transparency_spend WHERE {$whereSQL} GROUP BY council
            ) t
        ");
    }
    $sct_count_stmt->execute($params);
    $sct_total_count = (int)$sct_count_stmt->fetchColumn();

    $recent_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ct_transparency_spend WHERE {$whereSQL}");
    $recent_count_stmt->execute($params);
    $recent_total_count = (int)$recent_count_stmt->fetchColumn();

    $detail_row_count  = $detail_tab === 'totals' ? $sct_total_count : $recent_total_count;
    $detail_total_pages = max(1, (int)ceil($detail_row_count / $detail_per_page));
    $detail_page = max(1, min($detail_total_pages, (int)($_GET['page'] ?? 1)));
    $detail_offset = ($detail_page - 1) * $detail_per_page;

    if ($detail_tab === 'totals') {
        if ($caWhereSQL) {
            $sct_stmt = $pdo->prepare("
                SELECT organisation, total, payments, from_date, to_date, is_ca FROM (
                    SELECT council AS organisation, SUM(amount) AS total, COUNT(*) AS payments,
                           MIN(paid_date) AS from_date, MAX(paid_date) AS to_date, 0 AS is_ca
                    FROM ct_transparency_spend WHERE {$whereSQL} GROUP BY council
                    UNION ALL
                    SELECT ca_name AS organisation, SUM(amount) AS total, COUNT(*) AS payments,
                           MIN(paid_date) AS from_date, MAX(paid_date) AS to_date, 1 AS is_ca
                    FROM ct_ca_spend WHERE {$caWhereSQL} GROUP BY ca_name
                ) combined
                ORDER BY total DESC
                LIMIT {$detail_per_page} OFFSET {$detail_offset}
            ");
        } else {
            $sct_stmt = $pdo->prepare("
                SELECT council AS organisation,
                       SUM(amount) AS total,
                       COUNT(*) AS payments,
                       MIN(paid_date) AS from_date,
                       MAX(paid_date) AS to_date,
                       0 AS is_ca
                FROM ct_transparency_spend
                WHERE {$whereSQL}
                GROUP BY council
                ORDER BY total DESC
                LIMIT {$detail_per_page} OFFSET {$detail_offset}
            ");
        }
        $sct_stmt->execute($params);
        $supplier_council_totals = $sct_stmt->fetchAll();
        // Drop the internal joint entity from the per-supplier council breakdown.
        $supplier_council_totals = array_values(array_filter($supplier_council_totals, function ($r) {
            return !is_joint_council($r['organisation'] ?? '');
        }));
    } else {
        $recent_stmt = $pdo->prepare("
            SELECT t.council, t.supplier_canon, t.supplier_raw, t.service, t.amount, t.paid_date, t.period,
                   s.url AS source_url, t.validation_status, t.validation_notes
            FROM ct_transparency_spend t
            LEFT JOIN ct_spend_sources s ON s.id = t.source_id
            WHERE {$whereSQL_t}
            ORDER BY t.paid_date DESC
            LIMIT {$detail_per_page} OFFSET {$detail_offset}
        ");
        $recent_stmt->execute($params);
        $recent = $recent_stmt->fetchAll();
    }
}

layout_head('Actual payments');
?>

<style>
.tech-nav { display:flex; gap:0; margin-bottom:24px; border-bottom:2px solid #b1b4b6; flex-wrap:wrap; }
.tech-nav a { padding:10px 20px; text-decoration:none; color:#0b0c0c; font-size:1rem; font-weight:400; border-bottom:4px solid transparent; margin-bottom:-2px; }
.tech-nav a:hover { border-bottom-color:#b1b4b6; }
.tech-nav a.active { font-weight:700; border-bottom-color:#1d70b8; color:#1d70b8; }
.sort-icon { font-size:0.75rem; margin-left:3px; opacity:0.6; }
th.sort-asc .sort-icon, th.sort-desc .sort-icon { opacity:1; color:#1d70b8; }
.ct-supplier-bar { display:flex; align-items:center; gap:10px; margin-bottom:6px; font-size:0.875rem; }
.ct-supplier-bar__label { width:200px; flex-shrink:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ct-supplier-bar__track { flex:1; background:#f3f2f1; height:10px; border-radius:4px; overflow:hidden; }
.ct-supplier-bar__fill { height:100%; border-radius:4px; background:#1d70b8; }
.ct-supplier-bar__val { width:80px; text-align:right; flex-shrink:0; }
.ct-supplier-bar__sub { width:40px; text-align:right; flex-shrink:0; color:#6f777b; }
@media (max-width:640px) { .ct-supplier-bar__label { width:120px; } .ct-supplier-bar__val { width:60px; } }
@media (max-width:400px) { .ct-supplier-bar__label { width:80px; } .ct-supplier-bar__sub { display:none; } }
.ct-council-card { border:1px solid #b1b4b6; margin-bottom:8px; border-radius:3px; padding:12px 16px; display:flex; align-items:baseline; justify-content:space-between; background:#f3f2f1; gap:8px; flex-wrap:wrap; }
.ct-council-card__meta { color:#505a5f; white-space:nowrap; margin-left:16px; }
@media (max-width:480px) {
  .ct-council-card { flex-direction:column; align-items:flex-start; }
  .ct-council-card__meta { white-space:normal; margin-left:0; }
}
.ct-spender-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:16px; margin-bottom:24px; }
@media (max-width:900px) { .ct-spender-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:480px) { .ct-spender-grid { grid-template-columns:minmax(0,1fr); } }
</style>
<?php if ($section === 'councils'): ?>
<style>.govuk-width-container { max-width: 1170px; }</style>
<?php endif; ?>

<nav class="tech-nav">
  <a href="?section=overview"    class="<?= $section==='overview'    ? 'active' : '' ?>">LA overview</a>
  <a href="?section=sa_overview" class="<?= $section==='sa_overview' ? 'active' : '' ?>">SA overview</a>
  <a href="?section=suppliers"   class="<?= $section==='suppliers'   ? 'active' : '' ?>">Supplier payments</a>
  <a href="?section=validation"  class="<?= $section==='validation'  ? 'active' : '' ?>">Validation</a>
  <a href="/contracts/sources.php" class="<?= basename($_SERVER['PHP_SELF'])==='sources.php' ? 'active' : '' ?>">Data sources</a>
</nav>


<?php if ($section === 'overview'): ?>

<form method="get" action="/contracts/transparency.php" style="margin-bottom:24px;display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;justify-content:center;background:#f3f2f1;padding:15px 20px">
  <input type="hidden" name="section" value="overview">
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="fy_overview">Financial year</label>
    <select class="govuk-select" id="fy_overview" name="fy" onchange="this.form.submit()">
      <?php foreach ($fy_options as $fval => $flabel): ?>
        <option value="<?= $fval ?>"<?= $fy_selected===$fval?' selected':'' ?>><?= $flabel ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="ctype_overview">Council type</label>
    <select class="govuk-select" id="ctype_overview" name="ctype" onchange="this.form.submit()">
      <option value="">All types</option>
      <?php foreach ($ctype_options as $opt): ?>
        <option value="<?= htmlspecialchars($opt) ?>"<?= $ctype_selected===$opt?' selected':'' ?>><?= htmlspecialchars($opt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="region_overview">Region</label>
    <select class="govuk-select" id="region_overview" name="region" onchange="this.form.submit()">
      <option value="">All regions</option>
      <?php foreach ($region_options as $opt): ?>
        <option value="<?= htmlspecialchars($opt) ?>"<?= $region_selected===$opt?' selected':'' ?>><?= htmlspecialchars($opt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="nation_overview">Nation</label>
    <select class="govuk-select" id="nation_overview" name="nation" onchange="this.form.submit()">
      <?php // On default page load the list is England-only ($england_only), so
         // show England as the selected nation rather than a misleading "All nations".
         // "All nations" submits nation=all (a truthy, non-prefix value) so it is
         // distinguishable from an empty default load -- empty = England default,
         // 'all' = explicitly every nation (kills the default, applies no prefix).
         $nation_effective = $nation_selected !== '' ? $nation_selected : ($england_only ? 'England' : 'all'); ?>
      <option value="all"<?= $nation_effective==='all'?' selected':'' ?>>All nations</option>
      <?php foreach (array_keys($nation_prefixes) as $opt): ?>
        <option value="<?= htmlspecialchars($opt) ?>"<?= $nation_effective===$opt?' selected':'' ?>><?= htmlspecialchars($opt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($ctype_selected || $region_selected || $ca_selected || $nation_selected): ?>
    <a href="?section=overview&fy=<?= urlencode($fy_selected) ?>" class="govuk-link" style="align-self:center">Clear filters</a>
  <?php endif; ?>
</form>

  <?php if (empty($council_totals)): ?>
    <div class="govuk-inset-text">
      <p class="govuk-body">No transparency spend data loaded yet.</p>
      <p class="govuk-body">
        <a href="/contracts/sources.php" class="govuk-button govuk-button--secondary">Manage data sources</a>
        <button class="govuk-button" style="margin-left:12px" onclick="runImport()">Run import</button>
      </p>
      <div id="import-frame-wrap" style="display:none;margin-top:16px">
        <div style="background:#1a1a1a;border-radius:4px;overflow:hidden">
          <iframe id="import-frame" src="" style="width:100%;height:300px;border:none;display:block"></iframe>
        </div>
        <p class="govuk-body-s govuk-!-margin-top-2">
          <a id="reload-btn" href="/contracts/transparency.php" class="govuk-button govuk-button--secondary"
             style="display:none;margin-top:8px">← View results</a>
          <span style="color:#505a5f;font-size:0.875rem"> The "View results" button will appear when the import finishes.</span>
        </p>
      </div>
    </div>
    <script>
    function runImport() {
      var wrap = document.getElementById('import-frame-wrap');
      var frame = document.getElementById('import-frame');
      var btn  = document.getElementById('reload-btn');
      wrap.style.display = 'block';
      frame.src = '/contracts/import_transparency.php';
      // Show reload button after 10 seconds
      setTimeout(function() {
        btn.style.display = 'inline-block';
      }, 10000);
    }
    </script>
  <?php else: ?>

    <div class="govuk-grid-row govuk-!-margin-bottom-6">
      <?php foreach ([
        [fmt_value((float)$total_row['total']),    'Total tech payments',    'Actual invoiced amounts'],
        [number_format((int)$total_row['payments']),'Payment transactions',  'Across all councils'],
        [number_format((int)$total_row['suppliers']),'Tech suppliers',       'Named in spend data'],
        [(int)$total_row['councils'],              'Councils',               ($coverage_pct !== null ? $coverage_pct . '% period coverage' : 'councils in the spend data')],
      ] as [$n,$l,$s]): ?>
      <div class="govuk-grid-column-one-quarter govuk-!-margin-bottom-4">
        <div class="ct-stat-card">
          <div class="ct-stat-card__number"><?= $n ?></div>
          <div class="ct-stat-card__label"><?= $l ?></div>
          <div class="ct-stat-card__sub"><?= $s ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php
      $overview_per_page = 15;
      $total_overview_pages = max(1, (int)ceil(count($council_totals) / $overview_per_page));
      $overview_page = max(1, min($total_overview_pages, (int)($_GET['page'] ?? 1)));
      $page_council_totals = array_slice($council_totals, ($overview_page - 1) * $overview_per_page, $overview_per_page);
      $overview_qs = 'fy=' . urlencode($fy_selected) . '&ctype=' . urlencode($ctype_selected) . '&region=' . urlencode($region_selected) . '&ca=' . urlencode($ca_selected) . '&nation=' . urlencode($nation_selected);
    ?>

    <?php if (empty($council_totals)): ?>
      <p class="govuk-body">No councils match the selected filters.</p>
    <?php else: ?>
    <div class="ct-spender-grid">
      <?php foreach ($page_council_totals as $ct): ?>
        <div class="ct-stat-card">
          <div style="font-size:1rem;font-weight:700;margin-bottom:4px"><?= htmlspecialchars($ct['council']) ?></div>
          <?php if ($ct['council_type'] || $ct['region']): ?>
            <div style="font-size:0.75rem;color:#505a5f;margin-bottom:4px">
              <?= htmlspecialchars($ct['council_type']) ?><?= ($ct['council_type'] && $ct['region']) ? ' · ' : '' ?><?= htmlspecialchars($ct['region']) ?>
            </div>
          <?php endif; ?>
          <?php // Hide any "No current ..." placeholder (strategic/combined authority variants). Matches council.php guard.
                if (!empty($ct['combined_authority']) && strpos($ct['combined_authority'], 'No current ') !== 0): ?>
            <div style="font-size:0.7rem;color:#1d70b8;margin-bottom:4px"><?= htmlspecialchars($ct['combined_authority']) ?></div>
          <?php endif; ?>
          <div style="font-size:1.75rem;font-weight:700;color:#1d70b8;margin:6px 0"><?= fmt_value($ct['total']) ?></div>
          <div style="font-size:0.8rem;color:#505a5f">
            <?= number_format($ct['payments']) ?> payments
            · <?= $ct['from_date'] ? date('d/m/Y', strtotime($ct['from_date'])) : '' ?> – <?= $ct['to_date'] ? date('d/m/Y', strtotime($ct['to_date'])) : '' ?>
          </div>
          <div style="margin-top:8px">
            <a href="?section=councils&council=<?= council_url_id($ct['council'], $ct['lad_code']) ?>&<?= $overview_qs ?>&back=<?= urlencode('?section=overview&' . $overview_qs . '&page=' . $overview_page) ?>" class="govuk-link" style="font-size:0.875rem">View breakdown →</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($total_overview_pages > 1): ?>
    <nav class="govuk-pagination" role="navigation" aria-label="Overview pagination">
      <?php if ($overview_page > 1): ?>
      <div class="govuk-pagination__prev">
        <a class="govuk-link govuk-pagination__link" href="?section=overview&<?= $overview_qs ?>&page=<?= $overview_page-1 ?>" rel="prev">
          <svg class="govuk-pagination__icon govuk-pagination__icon--prev" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
            <path d="m6.5938-0.0078125-6.7266 6.7266 6.7441 6.4062 1.377-1.4453-4.1856-3.9768h12.896v-2h-12.984l4.2931-4.293-1.414-1.414z"></path>
          </svg>
          <span class="govuk-pagination__link-title">Previous</span>
        </a>
      </div>
      <?php endif; ?>
      <ul class="govuk-pagination__list">
        <?php for ($p = 1; $p <= $total_overview_pages; $p++): ?>
        <li class="govuk-pagination__item<?= $p===$overview_page?' govuk-pagination__item--current':'' ?>">
          <a class="govuk-link govuk-pagination__link" href="?section=overview&<?= $overview_qs ?>&page=<?= $p ?>" aria-label="Page <?= $p ?>"<?= $p===$overview_page?' aria-current="page"':'' ?>><?= $p ?></a>
        </li>
        <?php endfor; ?>
      </ul>
      <?php if ($overview_page < $total_overview_pages): ?>
      <div class="govuk-pagination__next">
        <a class="govuk-link govuk-pagination__link" href="?section=overview&<?= $overview_qs ?>&page=<?= $overview_page+1 ?>" rel="next">
          <span class="govuk-pagination__link-title">Next</span>
          <svg class="govuk-pagination__icon govuk-pagination__icon--next" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
            <path d="m8.107-0.0078125-1.4136 1.414 4.2926 4.293h-12.986v2h12.896l-4.1855 3.9768 1.377 1.4453 6.7441-6.4062-6.7246-6.7266z"></path>
          </svg>
        </a>
      </div>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php endif; ?>

    <p class="govuk-body-s govuk-!-colour-secondary">
      Actual invoiced payments to tech suppliers, including G-Cloud and CCS framework call-offs.
      <a href="?section=suppliers" class="govuk-link">View all supplier payments →</a>
    </p>

  <?php endif; ?>

<?php elseif ($section === 'sa_overview'): ?>

  <form method="get" style="display:flex;gap:16px;align-items:flex-end;margin-bottom:20px;flex-wrap:wrap">
    <input type="hidden" name="section" value="sa_overview">
    <div class="govuk-form-group govuk-!-margin-bottom-0">
      <label class="govuk-label govuk-!-font-size-16" for="fy_ca_overview">Financial year</label>
      <select class="govuk-select" id="fy_ca_overview" name="fy" onchange="this.form.submit()">
        <?php foreach ($fy_options as $fval => $flabel): ?>
          <option value="<?= $fval ?>"<?= $fy_selected===$fval?' selected':'' ?>><?= $flabel ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="govuk-form-group govuk-!-margin-bottom-0">
      <label class="govuk-label govuk-!-font-size-16" for="region_ca_overview">Region</label>
      <select class="govuk-select" id="region_ca_overview" name="region" onchange="this.form.submit()">
        <option value="">All regions</option>
        <?php foreach ($region_options as $opt): ?>
          <option value="<?= htmlspecialchars($opt) ?>"<?= $region_selected===$opt?' selected':'' ?>><?= htmlspecialchars($opt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($region_selected): ?>
      <a href="?section=sa_overview&fy=<?= urlencode($fy_selected) ?>" class="govuk-link" style="align-self:center">Clear filters</a>
    <?php endif; ?>
  </form>

  <?php if (empty($council_totals)): ?>
    <p class="govuk-body">No councils match the selected filters.</p>
  <?php else: ?>
  <?php
    $no_ca_key = 'No current strategic authority';
    $ca_groups = [];
    foreach ($council_totals as $ct) {
        if (($ct['council_type'] ?? '') === 'Strategic Authority') continue;
        $ca_name = ($ct['combined_authority'] ?? '') !== '' ? $ct['combined_authority'] : $no_ca_key;
        if (!isset($ca_groups[$ca_name])) {
            $ca_groups[$ca_name] = ['total' => 0.0, 'payments' => 0, 'councils' => []];
        }
        $ca_groups[$ca_name]['total']    += (float)$ct['total'];
        $ca_groups[$ca_name]['payments'] += (int)$ct['payments'];
        $ca_groups[$ca_name]['councils'][] = $ct;
    }
    // Combined authorities' own spend (e.g. WMCA's, GMCA's own payments — not a constituent council)
    $ca_own_totals_stmt = $pdo->prepare("
        SELECT ca_name,
               SUM(amount) AS total,
               COUNT(*) AS payments
        FROM ct_ca_spend
        WHERE {$caOvWhereSQL}
        GROUP BY ca_name
    ");
    $ca_own_totals_stmt->execute($ov_params);
    foreach ($ca_own_totals_stmt->fetchAll() as $row) {
        $ca_name = $row['ca_name'];
        if (!isset($ca_groups[$ca_name])) {
            $ca_groups[$ca_name] = ['total' => 0.0, 'payments' => 0, 'councils' => []];
        }
        $ca_groups[$ca_name]['total']    += (float)$row['total'];
        $ca_groups[$ca_name]['payments'] += (int)$row['payments'];
        $ca_groups[$ca_name]['councils'][] = [
            'council'    => $ca_name,
            'total'      => $row['total'],
            'payments'   => $row['payments'],
            'is_ca_own'  => true,
        ];
    }


    // Nation groups (Scotland/Wales/NI). Built from BOTH the devolved
    // government's own spend AND its councils, so each nation renders as ONE
    // block with a real council count -- not a misleading standalone
    // "Scottish Government - 0 councils" SA card. These stay OUT of $ca_groups
    // (the paginated English-SA list) and render in their own always-visible
    // block below. Init here so the devolved-government block can feed it.
    $nation_groups = [];

    // Devolved governments (type=Strategic Authority in ct_council_config).
    // Fold each government's OWN spend into its nation group (keyed by region),
    // flagged is_ca_own so it renders as the nation's central-government line.
    $devolved_stmt = $pdo->prepare("
        SELECT cc.council, cc.lad_code, cc.region, SUM(ts.amount) AS total, COUNT(*) AS payments
        FROM ct_transparency_spend ts
        JOIN ct_council_config cc ON cc.council = ts.council
        WHERE cc.council_type = 'Strategic Authority'
        AND cc.region IN ('Scotland','Wales','Northern Ireland')
        AND ts.internal_provider = 0
        AND (ts.validation_status IS NULL OR ts.validation_status != 'invalid')
        GROUP BY cc.council, cc.lad_code, cc.region
    ");
    $devolved_stmt->execute();
    foreach ($devolved_stmt->fetchAll() as $row) {
        $ca_name = $row['region'];
        if (!isset($nation_groups[$ca_name])) {
            $nation_groups[$ca_name] = ['total' => 0.0, 'payments' => 0, 'councils' => []];
        }
        $nation_groups[$ca_name]['total']    += (float)$row['total'];
        $nation_groups[$ca_name]['payments'] += (int)$row['payments'];
        $nation_groups[$ca_name]['councils'][] = [
            'council'      => $row['council'],
            'lad_code'     => $row['lad_code'],
            'total'        => $row['total'],
            'payments'     => $row['payments'],
            'is_ca_own'    => true,
            'is_devolved'  => true,
        ];
    }

    // Devolved-nation councils (Scotland/Wales/NI). They have no strategic
    // authority, so the England-only default clamp above excludes them from
    // $council_totals. Pull them in here, grouped by nation (cc.region already
    // holds 'Scotland'/'Wales'/'Northern Ireland'), so each nation shows as its
    // own group alongside the English SAs. Honour the fy date window.
    $nation_ov_where = [
        "ts.internal_provider = 0",
        "(ts.validation_status IS NULL OR ts.validation_status != 'invalid')",
        "cc.council_type != 'Strategic Authority'",
        "cc.region IN ('Scotland','Wales','Northern Ireland')",
    ];
    $nation_ov_params = [];
    if ($date_from) { $nation_ov_where[] = 'ts.paid_date >= :n_date_from'; $nation_ov_params[':n_date_from'] = $date_from; }
    if ($date_to)   { $nation_ov_where[] = 'ts.paid_date <= :n_date_to';   $nation_ov_params[':n_date_to']   = $date_to; }
    // Respect an explicit region filter if one is set (e.g. region=Wales).
    if ($region_selected && in_array($region_selected, ['Scotland','Wales','Northern Ireland'], true)) {
        $nation_ov_where[] = 'cc.region = :n_region';
        $nation_ov_params[':n_region'] = $region_selected;
    }
    $nation_ovWhereSQL = implode(' AND ', $nation_ov_where);
    $nation_stmt = $pdo->prepare("
        SELECT cc.region AS nation, cc.council, cc.lad_code,
               SUM(ts.amount) AS total, COUNT(*) AS payments
        FROM ct_transparency_spend ts
        JOIN ct_council_config cc ON cc.council = ts.council
        WHERE {$nation_ovWhereSQL}
        GROUP BY cc.region, cc.council, cc.lad_code
    ");
    $nation_stmt->execute($nation_ov_params);
    // Append the councils to the nation groups already seeded with each
    // government's own spend above (do NOT re-init $nation_groups here).
    foreach ($nation_stmt->fetchAll() as $row) {
        $ca_name = $row['nation'];
        if (!isset($nation_groups[$ca_name])) {
            $nation_groups[$ca_name] = ['total' => 0.0, 'payments' => 0, 'councils' => []];
        }
        $nation_groups[$ca_name]['total']    += (float)$row['total'];
        $nation_groups[$ca_name]['payments'] += (int)$row['payments'];
        $nation_groups[$ca_name]['councils'][] = [
            'council'   => $row['council'],
            'lad_code'  => $row['lad_code'],
            'total'     => $row['total'],
            'payments'  => $row['payments'],
        ];
    }
    uasort($nation_groups, fn($a, $b) => $b['total'] <=> $a['total']);

    $no_ca_group = $ca_groups[$no_ca_key] ?? null;
    unset($ca_groups[$no_ca_key]);
    uasort($ca_groups, fn($a, $b) => $b['total'] <=> $a['total']);
    $ca_overview_back = urlencode(
        '?section=sa_overview&fy=' . urlencode($fy_selected)
        . '&ctype=' . urlencode($ctype_selected)
        . '&region=' . urlencode($region_selected)
    );

    // "No current strategic authority" goes first, then real SAs by total desc.
    $ca_ordered_groups = [];
    if ($no_ca_group) {
        $ca_ordered_groups[] = ['name' => $no_ca_key, 'group' => $no_ca_group, 'is_no_ca' => true];
    }
    foreach ($ca_groups as $ca_name => $group) {
        $ca_ordered_groups[] = ['name' => $ca_name, 'group' => $group, 'is_no_ca' => false];
    }

    $ca_per_page = 10;
    $ca_total_pages = max(1, (int)ceil(count($ca_ordered_groups) / $ca_per_page));
    $ca_page = max(1, min($ca_total_pages, (int)($_GET['ca_page'] ?? 1)));
    $ca_page_groups = array_slice($ca_ordered_groups, ($ca_page - 1) * $ca_per_page, $ca_per_page);
    $ca_overview_qs = 'fy=' . urlencode($fy_selected) . '&ctype=' . urlencode($ctype_selected) . '&region=' . urlencode($region_selected);
  ?>

  <div class="govuk-grid-row govuk-!-margin-bottom-6">
    <?php foreach ([
      [fmt_value(array_sum(array_column($ca_groups, 'total')) + ($no_ca_group['total'] ?? 0) + array_sum(array_column($nation_groups, 'total'))), 'Total tech payments', 'Across all LAs, SAs and nations'],
      [count($ca_groups) + count($nation_groups), 'Nations / strategic authorities', 'With at least one onboarded council'],
      [array_sum(array_map(fn($g) => count(array_filter($g['councils'], fn($c) => empty($c['is_ca_own']))), array_merge($ca_groups, $nation_groups))), 'Councils with a nation / SA', 'Part of a strategic authority or nation'],
      [count($no_ca_group['councils'] ?? []), 'Councils with no SA', 'Not part of a strategic authority'],
    ] as [$n,$l,$s]): ?>
    <div class="govuk-grid-column-one-quarter govuk-!-margin-bottom-4">
      <div class="ct-stat-card">
        <div class="ct-stat-card__number"><?= $n ?></div>
        <div class="ct-stat-card__label"><?= $l ?></div>
        <div class="ct-stat-card__sub"><?= $s ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php foreach ($ca_page_groups as $item): ?>
  <?php $ca_name = $item['name']; $group = $item['group']; ?>
  <?php usort($group['councils'], fn($a, $b) => (float)$b['total'] <=> (float)$a['total']); ?>
  <?php $group_council_count = count(array_filter($group['councils'], fn($c) => empty($c['is_ca_own']))); ?>
  <?php if ($item['is_no_ca']): ?>
  <div style="border:1px solid #b1b4b6;border-radius:3px;margin-bottom:16px;background:#fafafa">
    <div style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <h3 class="govuk-heading-s govuk-!-margin-bottom-0"><?= htmlspecialchars($ca_name) ?></h3>
      <div style="text-align:right">
        <div style="font-size:1.25rem;font-weight:700;color:#505a5f"><?= fmt_value($group['total']) ?></div>
        <div class="govuk-body-s" style="color:#505a5f;margin-bottom:0">
          <?= $group_council_count ?> councils · <?= number_format($group['payments']) ?> payments
          · <a href="?section=overview&fy=<?= urlencode($fy_selected) ?>&ca=<?= urlencode($ca_name) ?>" class="govuk-link">View these councils →</a>
        </div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div style="border:1px solid #b1b4b6;border-radius:3px;margin-bottom:16px">
    <div style="padding:12px 16px;background:#f3f2f1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <h3 class="govuk-heading-s govuk-!-margin-bottom-0"><?= htmlspecialchars($ca_name) ?></h3>
      <div style="text-align:right">
        <div style="font-size:1.25rem;font-weight:700;color:#1d70b8"><?= fmt_value($group['total']) ?></div>
        <div class="govuk-body-s" style="color:#505a5f;margin-bottom:0"><?= $group_council_count ?> council<?= $group_council_count===1?'':'s' ?> · <?= number_format($group['payments']) ?> payments</div>
      </div>
    </div>
    <table class="govuk-table" style="margin-bottom:0">
      <tbody>
        <?php foreach ($group['councils'] as $ct): ?>
        <tr class="govuk-table__row"<?= !empty($ct['is_ca_own']) ? ' style="background:#e8f1fb"' : '' ?>>
          <td class="govuk-table__cell" style="padding:8px 16px">
            <?php if (!empty($ct['is_devolved'])): ?>
              <a href="?section=councils&council=<?= council_url_id($ct['council'], $ct['lad_code'] ?? null) ?>&fy=<?= urlencode($fy_selected) ?>&back=<?= $ca_overview_back ?>" class="govuk-link"><strong><?= htmlspecialchars($ct['council']) ?></strong></a>
            <?php elseif (!empty($ct['is_ca_own'])): ?>
              <a href="?section=sa_detail&ca=<?= urlencode($ct['council']) ?>&fy=<?= urlencode($fy_selected) ?>&back=<?= $ca_overview_back ?>" class="govuk-link"><strong><?= htmlspecialchars($ct['council']) ?> — own spend</strong></a>
            <?php else: ?>
              <a href="?section=councils&council=<?= council_url_id($ct['council'], $ct['lad_code']) ?>&fy=<?= urlencode($fy_selected) ?>&back=<?= $ca_overview_back ?>" class="govuk-link"><?= htmlspecialchars($ct['council']) ?></a>
            <?php endif; ?>
          </td>
          <td class="govuk-table__cell govuk-table__cell--numeric" style="padding:8px 16px"><?= fmt_value($ct['total']) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" style="padding:8px 16px;color:#505a5f"><?= number_format($ct['payments']) ?> payments</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php endforeach; ?>

  <?php if ($ca_total_pages > 1): ?>
  <nav class="govuk-pagination" role="navigation" aria-label="Combined authority overview pagination">
    <?php if ($ca_page > 1): ?>
    <div class="govuk-pagination__prev">
      <a class="govuk-link govuk-pagination__link" href="?section=sa_overview&<?= $ca_overview_qs ?>&ca_page=<?= $ca_page-1 ?>" rel="prev">
        <svg class="govuk-pagination__icon govuk-pagination__icon--prev" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
          <path d="m6.5938-0.0078125-6.7266 6.7266 6.7441 6.4062 1.377-1.4453-4.1856-3.9768h12.896v-2h-12.984l4.2931-4.293-1.414-1.414z"></path>
        </svg>
        <span class="govuk-pagination__link-title">Previous</span>
      </a>
    </div>
    <?php endif; ?>
    <ul class="govuk-pagination__list">
      <?php for ($p = 1; $p <= $ca_total_pages; $p++): ?>
      <li class="govuk-pagination__item<?= $p===$ca_page?' govuk-pagination__item--current':'' ?>">
        <a class="govuk-link govuk-pagination__link" href="?section=sa_overview&<?= $ca_overview_qs ?>&ca_page=<?= $p ?>" aria-label="Page <?= $p ?>"<?= $p===$ca_page?' aria-current="page"':'' ?>><?= $p ?></a>
      </li>
      <?php endfor; ?>
    </ul>
    <?php if ($ca_page < $ca_total_pages): ?>
    <div class="govuk-pagination__next">
      <a class="govuk-link govuk-pagination__link" href="?section=sa_overview&<?= $ca_overview_qs ?>&ca_page=<?= $ca_page+1 ?>" rel="next">
        <span class="govuk-pagination__link-title">Next</span>
        <svg class="govuk-pagination__icon govuk-pagination__icon--next" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
          <path d="m8.107-0.0078125-1.4136 1.414 4.2926 4.293h-12.986v2h12.896l-4.1855 3.9768 1.377 1.4453 6.7441-6.4062-6.7246-6.7266z"></path>
        </svg>
      </a>
    </div>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php if (!empty($nation_groups)): ?>
  <h2 class="govuk-heading-m govuk-!-margin-top-8">Own nation groups</h2>
  <p class="govuk-body-s govuk-!-colour-secondary">
    Scotland, Wales and Northern Ireland have no strategic authorities. Their councils are grouped by nation.
  </p>
  <?php foreach ($nation_groups as $ca_name => $group): ?>
  <?php usort($group['councils'], fn($a, $b) => (float)$b['total'] <=> (float)$a['total']); ?>
  <?php $group_council_count = count(array_filter($group['councils'], fn($c) => empty($c['is_ca_own']))); ?>
  <div style="border:1px solid #b1b4b6;border-radius:3px;margin-bottom:16px">
    <div style="padding:12px 16px;background:#f3f2f1;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <h3 class="govuk-heading-s govuk-!-margin-bottom-0"><?= htmlspecialchars($ca_name) ?></h3>
      <div style="text-align:right">
        <div style="font-size:1.25rem;font-weight:700;color:#1d70b8"><?= fmt_value($group['total']) ?></div>
        <div class="govuk-body-s" style="color:#505a5f;margin-bottom:0"><?= $group_council_count ?> council<?= $group_council_count===1?'':'s' ?> · <?= number_format($group['payments']) ?> payments</div>
      </div>
    </div>
    <table class="govuk-table" style="margin-bottom:0">
      <tbody>
        <?php foreach ($group['councils'] as $ct): ?>
        <tr class="govuk-table__row"<?= !empty($ct['is_ca_own']) ? ' style="background:#e8f1fb"' : '' ?>>
          <td class="govuk-table__cell" style="padding:8px 16px">
            <?php if (!empty($ct['is_ca_own'])): ?>
              <a href="?section=councils&council=<?= council_url_id($ct['council'], $ct['lad_code'] ?? null) ?>&fy=<?= urlencode($fy_selected) ?>&back=<?= $ca_overview_back ?>" class="govuk-link"><strong><?= htmlspecialchars($ct['council']) ?> — own spend</strong></a>
            <?php else: ?>
              <a href="?section=councils&council=<?= council_url_id($ct['council'], $ct['lad_code']) ?>&fy=<?= urlencode($fy_selected) ?>&back=<?= $ca_overview_back ?>" class="govuk-link"><?= htmlspecialchars($ct['council']) ?></a>
            <?php endif; ?>
          </td>
          <td class="govuk-table__cell govuk-table__cell--numeric" style="padding:8px 16px"><?= fmt_value($ct['total']) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" style="padding:8px 16px;color:#505a5f"><?= number_format($ct['payments']) ?> payments</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php endif; ?>

<?php elseif ($section === 'suppliers'): ?>

  <p class="govuk-body govuk-!-colour-secondary">
    Actual invoiced payments to identified tech suppliers.
  </p>

  <form method="get" action="/contracts/transparency.php"
        style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;align-items:flex-end">
    <input type="hidden" name="section" value="suppliers">
    <div class="govuk-form-group govuk-!-margin-bottom-0">
      <label class="govuk-label" for="s_supplier">Supplier</label>
      <input class="govuk-input govuk-input--width-20" type="text" id="s_supplier" name="supplier"
             value="<?= htmlspecialchars($supplier) ?>" placeholder="e.g. Microsoft">
    </div>
    <div class="govuk-form-group govuk-!-margin-bottom-0">
      <label class="govuk-label" for="s_council">Council</label>
      <select class="govuk-select" id="s_council" name="council">
        <option value="">All councils</option>
        <?php foreach ($council_filter_options as $cfo): ?>
          <?php // In a form <option>, use the raw lad_code (the browser encodes
                // on submit); NOT council_url_id(), whose urlencode() fallback
                // would double-encode county-council names. Fall back to the
                // raw name when there's no lad_code -- the resolver at the top
                // maps a lad_code back to the name, names pass through as-is.
                $cval = ($cfo['lad_code'] ?? '') !== '' ? $cfo['lad_code'] : $cfo['council']; ?>
          <option value="<?= htmlspecialchars($cval) ?>"
                  <?= $council===$cfo['council'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($cfo['council']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="govuk-form-group govuk-!-margin-bottom-0">
      <label class="govuk-label" for="s_period">Period</label>
      <select class="govuk-select" id="s_period" name="period">
        <option value="">All periods</option>
        <?php foreach ($periods as $p): ?>
          <option value="<?= htmlspecialchars($p) ?>"
                  <?= $period===$p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="govuk-form-group govuk-!-margin-bottom-0">
      <label class="govuk-label" for="fy_suppliers">Financial year</label>
      <select class="govuk-select" id="fy_suppliers" name="fy">
        <?php foreach ($fy_options as $fval => $flabel): ?>
          <option value="<?= $fval ?>"<?= $fy_selected===$fval?' selected':'' ?>><?= $flabel ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="govuk-button govuk-!-margin-bottom-0" type="submit">Filter</button>
    <?php if ($council || $supplier || $period || $fy_selected): ?>
      <a href="?section=suppliers" class="govuk-link" style="align-self:center">Clear</a>
    <?php endif; ?>
  </form>

  <table class="govuk-table sortable-table" style="font-size:0.875rem">
    <thead class="govuk-table__head">
      <tr class="govuk-table__row">
        <th class="govuk-table__header sortable" data-col="0" data-type="str">Supplier <span class="sort-icon">⇅</span></th>
        <th class="govuk-table__header govuk-table__header--numeric sortable sort-desc" data-col="1" data-type="num">Total paid <span class="sort-icon">↓</span></th>
        <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="2" data-type="num">Payments <span class="sort-icon">⇅</span></th>
        <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="3" data-type="num">Councils <span class="sort-icon">⇅</span></th>
        <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="4" data-type="num">SAs <span class="sort-icon">⇅</span></th>
        <th class="govuk-table__header">Last payment</th>
      </tr>
    </thead>
    <tbody class="govuk-table__body">
      <?php foreach ($top_suppliers as $row): ?>
      <?php $row_ca_count = count($ca_counts[$row['supplier_canon']] ?? []); ?>
      <tr class="govuk-table__row">
        <td class="govuk-table__cell">
          <a href="?section=suppliers&supplier=<?= urlencode($row['supplier_canon']) ?>" class="govuk-link">
            <?= htmlspecialchars($row['supplier_canon']) ?>
          </a>
        </td>
        <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= (int)$row['total'] ?>"><?= fmt_value($row['total']) ?></td>
        <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= $row['payments'] ?>"><?= number_format($row['payments']) ?></td>
        <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= $row['councils'] ?>"><?= $row['councils'] ?></td>
        <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= $row_ca_count ?>"><?= $row_ca_count ?></td>
        <td class="govuk-table__cell"><?= htmlspecialchars($row['last_payment'] ?? '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php
    $suppliers_qs = 'section=suppliers'
      . '&supplier=' . urlencode($supplier)
      . '&council=' . urlencode($council)
      . '&period=' . urlencode($period)
      . '&fy=' . urlencode($fy_selected);
  ?>

  <?php if ($suppliers_total_pages > 1): ?>
  <nav class="govuk-pagination" role="navigation" aria-label="Supplier payments pagination">
    <?php if ($suppliers_page > 1): ?>
    <div class="govuk-pagination__prev">
      <a class="govuk-link govuk-pagination__link" href="?<?= $suppliers_qs ?>&spage=<?= $suppliers_page-1 ?>" rel="prev">
        <svg class="govuk-pagination__icon govuk-pagination__icon--prev" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
          <path d="m6.5938-0.0078125-6.7266 6.7266 6.7441 6.4062 1.377-1.4453-4.1856-3.9768h12.896v-2h-12.984l4.2931-4.293-1.414-1.414z"></path>
        </svg>
        <span class="govuk-pagination__link-title">Previous</span>
      </a>
    </div>
    <?php endif; ?>
    <ul class="govuk-pagination__list">
      <?php for ($p = 1; $p <= $suppliers_total_pages; $p++): ?>
      <li class="govuk-pagination__item<?= $p===$suppliers_page?' govuk-pagination__item--current':'' ?>">
        <a class="govuk-link govuk-pagination__link" href="?<?= $suppliers_qs ?>&spage=<?= $p ?>" aria-label="Page <?= $p ?>"<?= $p===$suppliers_page?' aria-current="page"':'' ?>><?= $p ?></a>
      </li>
      <?php endfor; ?>
    </ul>
    <?php if ($suppliers_page < $suppliers_total_pages): ?>
    <div class="govuk-pagination__next">
      <a class="govuk-link govuk-pagination__link" href="?<?= $suppliers_qs ?>&spage=<?= $suppliers_page+1 ?>" rel="next">
        <span class="govuk-pagination__link-title">Next</span>
        <svg class="govuk-pagination__icon govuk-pagination__icon--next" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
          <path d="m8.107-0.0078125-1.4136 1.414 4.2926 4.293h-12.986v2h12.896l-4.1855 3.9768 1.377 1.4453 6.7441-6.4062-6.7246-6.7266z"></path>
        </svg>
      </a>
    </div>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php if ($supplier || $council): ?>

  <?php
    $detail_base_qs = 'section=suppliers'
      . '&supplier=' . urlencode($supplier)
      . '&council=' . urlencode($council)
      . '&period=' . urlencode($period)
      . '&fy=' . urlencode($fy_selected);
  ?>

  <div class="tech-nav" style="margin-top:32px;margin-bottom:16px">
    <a href="?<?= $detail_base_qs ?>&tab=totals" class="<?= $detail_tab==='totals' ? 'active' : '' ?>">Totals by organisation (<?= number_format($sct_total_count) ?>)</a>
    <a href="?<?= $detail_base_qs ?>&tab=transactions" class="<?= $detail_tab==='transactions' ? 'active' : '' ?>">Transactions (<?= number_format($recent_total_count) ?>)</a>
  </div>

  <?php if ($detail_tab === 'totals'): ?>

  <?php if ($supplier_council_totals): ?>
  <table class="govuk-table sortable-table" style="font-size:0.875rem">
    <thead class="govuk-table__head">
      <tr class="govuk-table__row">
        <th class="govuk-table__header sortable" data-col="0" data-type="str">Organisation <span class="sort-icon">⇅</span></th>
        <th class="govuk-table__header govuk-table__header--numeric sortable sort-desc" data-col="1" data-type="num">Total paid <span class="sort-icon">↓</span></th>
        <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="2" data-type="num">Payments <span class="sort-icon">⇅</span></th>
        <th class="govuk-table__header">First payment</th>
        <th class="govuk-table__header">Last payment</th>
      </tr>
    </thead>
    <tbody class="govuk-table__body">
      <?php foreach ($supplier_council_totals as $sct): ?>
      <tr class="govuk-table__row">
        <td class="govuk-table__cell">
          <?php if (!empty($sct['is_ca'])): ?>
            <a href="?section=sa_detail&ca=<?= urlencode($sct['organisation']) ?>&fy=<?= urlencode($fy_selected) ?>&back=<?= urlencode('?' . $detail_base_qs . '&tab=totals') ?>" class="govuk-link">
              <?= htmlspecialchars($sct['organisation']) ?> — own spend
            </a>
          <?php else: ?>
            <a href="?section=suppliers&supplier=<?= urlencode($supplier) ?>&council=<?= urlencode($sct['organisation']) ?>&fy=<?= urlencode($fy_selected) ?>" class="govuk-link">
              <?= htmlspecialchars($sct['organisation']) ?>
            </a>
          <?php endif; ?>
        </td>
        <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= (float)$sct['total'] ?>"><?= fmt_value($sct['total']) ?></td>
        <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= $sct['payments'] ?>"><?= number_format($sct['payments']) ?></td>
        <td class="govuk-table__cell" style="white-space:nowrap"><?= $sct['from_date'] ? date('d/m/Y', strtotime($sct['from_date'])) : '' ?></td>
        <td class="govuk-table__cell" style="white-space:nowrap"><?= $sct['to_date'] ? date('d/m/Y', strtotime($sct['to_date'])) : '' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="govuk-body-s govuk-!-colour-secondary">No organisation totals found.</p>
  <?php endif; ?>

  <?php else: ?>

  <table class="govuk-table sortable-table" style="font-size:0.875rem">
    <thead class="govuk-table__head">
      <tr class="govuk-table__row">
        <th class="govuk-table__header sortable" data-col="0" data-type="str">Supplier <span class="sort-icon">⇅</span></th>
        <th class="govuk-table__header sortable" data-col="1" data-type="str">Council <span class="sort-icon">⇅</span></th>
        <th class="govuk-table__header sortable" data-col="2" data-type="str">Service <span class="sort-icon">⇅</span></th>
        <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="3" data-type="num">Amount <span class="sort-icon">⇅</span></th>
        <th class="govuk-table__header sortable sort-desc" data-col="4" data-type="num">Date <span class="sort-icon">↓</span></th>
        <th class="govuk-table__header" style="width:2rem"></th>
      </tr>
    </thead>
    <tbody class="govuk-table__body">
      <?php foreach ($recent as $r): ?>
      <tr class="govuk-table__row">
        <td class="govuk-table__cell">
          <?= htmlspecialchars($r['supplier_canon']) ?>
          <?php if ($r['supplier_raw'] !== $r['supplier_canon']): ?>
            <div style="font-size:0.8rem;color:#6f777b"><?= htmlspecialchars(mb_strimwidth($r['supplier_raw'], 0, 50, '…')) ?></div>
          <?php endif; ?>
        </td>
        <td class="govuk-table__cell"><?= htmlspecialchars($r['council']) ?></td>
        <td class="govuk-table__cell" style="font-size:0.8rem"><?= htmlspecialchars(mb_strimwidth($r['service'] ?? '', 0, 50, '…')) ?></td>
        <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= (float)$r['amount'] ?>"><?= fmt_value($r['amount']) ?></td>
        <td class="govuk-table__cell" style="white-space:nowrap" data-val="<?= $r['paid_date'] ? strtotime($r['paid_date']) : 0 ?>">
          <?= htmlspecialchars($r['paid_date'] ?? '') ?>
          <?php if (!empty($r['period'])): ?>
            <div style="font-size:0.75rem;color:#6f777b"><?= htmlspecialchars($r['period']) ?></div>
          <?php endif; ?>
        </td>
        <td class="govuk-table__cell" style="text-align:center;white-space:nowrap">
          <?php if (!empty($r['source_url'])): ?>
            <a href="<?= htmlspecialchars($r['source_url']) ?>" target="_blank" rel="noopener" title="Source data" class="govuk-link" style="font-size:0.8rem;text-decoration:none" aria-label="Source CSV">&#x1F517;</a>
          <?php endif; ?>
          <?php if (!empty($r['validation_status'])): ?>
            <?php
              $vs = $r['validation_status'];
              $vn = htmlspecialchars($r['validation_notes'] ?? '');
              $vc = $vs === 'valid' ? '#00703c' : ($vs === 'invalid' ? '#d4351c' : '#505a5f');
              $vi = $vs === 'valid' ? '✓'       : ($vs === 'invalid' ? '✗'       : '?');
            ?>
            <span title="<?= $vn ?>" style="color:<?= $vc ?>;font-weight:bold;font-size:0.85rem;margin-left:4px;cursor:default"><?= $vi ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (empty($recent)): ?>
    <p class="govuk-body-s govuk-!-colour-secondary">No transactions found.</p>
  <?php endif; ?>

  <?php endif; ?>

  <?php if ($detail_total_pages > 1): ?>
  <nav class="govuk-pagination" role="navigation" aria-label="<?= $detail_tab==='totals'?'Totals by organisation':'Transactions' ?> pagination">
    <?php if ($detail_page > 1): ?>
    <div class="govuk-pagination__prev">
      <a class="govuk-link govuk-pagination__link" href="?<?= $detail_base_qs ?>&tab=<?= $detail_tab ?>&page=<?= $detail_page-1 ?>" rel="prev">
        <svg class="govuk-pagination__icon govuk-pagination__icon--prev" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
          <path d="m6.5938-0.0078125-6.7266 6.7266 6.7441 6.4062 1.377-1.4453-4.1856-3.9768h12.896v-2h-12.984l4.2931-4.293-1.414-1.414z"></path>
        </svg>
        <span class="govuk-pagination__link-title">Previous</span>
      </a>
    </div>
    <?php endif; ?>
    <ul class="govuk-pagination__list">
      <?php for ($p = 1; $p <= $detail_total_pages; $p++): ?>
        <?php if ($p === 1 || $p === $detail_total_pages || abs($p - $detail_page) <= 2): ?>
        <li class="govuk-pagination__item<?= $p===$detail_page?' govuk-pagination__item--current':'' ?>">
          <a class="govuk-link govuk-pagination__link" href="?<?= $detail_base_qs ?>&tab=<?= $detail_tab ?>&page=<?= $p ?>" aria-label="Page <?= $p ?>"<?= $p===$detail_page?' aria-current="page"':'' ?>><?= $p ?></a>
        </li>
        <?php elseif ($p === 2 || $p === $detail_total_pages - 1): ?>
        <li class="govuk-pagination__item govuk-pagination__item--ellipses">&ctdot;</li>
        <?php endif; ?>
      <?php endfor; ?>
    </ul>
    <?php if ($detail_page < $detail_total_pages): ?>
    <div class="govuk-pagination__next">
      <a class="govuk-link govuk-pagination__link" href="?<?= $detail_base_qs ?>&tab=<?= $detail_tab ?>&page=<?= $detail_page+1 ?>" rel="next">
        <span class="govuk-pagination__link-title">Next</span>
        <svg class="govuk-pagination__icon govuk-pagination__icon--next" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
          <path d="m8.107-0.0078125-1.4136 1.414 4.2926 4.293h-12.986v2h12.896l-4.1855 3.9768 1.377 1.4453 6.7441-6.4062-6.7246-6.7266z"></path>
        </svg>
      </a>
    </div>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php endif; ?>

<?php elseif ($section === 'validation'): ?>
<?php
  // ── Validation summary ───────────────────────────────────────────────────
  $val_stats = $pdo->query("
      SELECT validation_status, COUNT(*) as cnt
      FROM ct_transparency_spend
      WHERE validation_status IS NOT NULL
      GROUP BY validation_status
  ")->fetchAll(PDO::FETCH_KEY_PAIR);
  $val_total = array_sum($val_stats);

  $val_page     = max(1, (int)($_GET['val_page'] ?? 1));
  $val_per_page = 50;
  $val_offset   = ($val_page - 1) * $val_per_page;
  $val_filter   = $_GET['val_status'] ?? '';

  $val_where  = ['validation_status IS NOT NULL'];
  $val_params = [];
  if (in_array($val_filter, ['valid','invalid','uncertain'])) {
      $val_where[]  = 'validation_status = :val_status';
      $val_params[':val_status'] = $val_filter;
  }
  $val_whereSQL = implode(' AND ', $val_where);

  $val_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ct_transparency_spend WHERE $val_whereSQL");
  $val_count_stmt->execute($val_params);
  $val_filtered_total = (int)$val_count_stmt->fetchColumn();
  $val_pages = max(1, (int)ceil($val_filtered_total / $val_per_page));

  $val_rows_stmt = $pdo->prepare("
      SELECT t.id, t.council, t.supplier_canon, t.service, t.amount, t.paid_date,
             t.validation_status, t.validation_notes, t.validated_at
      FROM ct_transparency_spend t
      WHERE $val_whereSQL
      ORDER BY t.validated_at DESC
      LIMIT $val_per_page OFFSET $val_offset
  ");
  $val_rows_stmt->execute($val_params);
  $val_rows = $val_rows_stmt->fetchAll();
?>

  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px">
    <?php foreach (['valid'=>['#00703c','✓ Valid'],'uncertain'=>['#505a5f','? Uncertain'],'invalid'=>['#d4351c','✗ Invalid']] as $s=>[$col,$label]): ?>
    <div style="border:1px solid #b1b4b6;border-left:4px solid <?= $col ?>;padding:12px 18px;border-radius:3px;min-width:120px">
      <div style="font-size:1.5rem;font-weight:bold;color:<?= $col ?>"><?= number_format($val_stats[$s] ?? 0) ?></div>
      <div style="font-size:0.875rem;color:#505a5f"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
    <div style="border:1px solid #b1b4b6;padding:12px 18px;border-radius:3px;min-width:120px">
      <div style="font-size:1.5rem;font-weight:bold"><?= number_format($val_total) ?></div>
      <div style="font-size:0.875rem;color:#505a5f">Total validated</div>
    </div>
  </div>

  <form method="get" style="margin-bottom:16px;display:flex;gap:12px;align-items:flex-end">
    <input type="hidden" name="section" value="validation">
    <div class="govuk-form-group govuk-!-margin-bottom-0">
      <label class="govuk-label govuk-!-font-size-16">Status</label>
      <select class="govuk-select" name="val_status" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach (['valid','uncertain','invalid'] as $s): ?>
          <option value="<?= $s ?>"<?= $val_filter===$s?' selected':'' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($val_filter): ?>
      <a href="?section=validation" class="govuk-link" style="align-self:center">Clear</a>
    <?php endif; ?>
  </form>

  <?php if ($val_rows): ?>
  <table class="govuk-table" style="font-size:0.875rem">
    <thead class="govuk-table__head">
      <tr class="govuk-table__row">
        <th class="govuk-table__header">Supplier</th>
        <th class="govuk-table__header">Council</th>
        <th class="govuk-table__header">Service</th>
        <th class="govuk-table__header govuk-table__header--numeric">Amount</th>
        <th class="govuk-table__header">Date</th>
        <th class="govuk-table__header">Status</th>
        <th class="govuk-table__header">Notes</th>
      </tr>
    </thead>
    <tbody class="govuk-table__body">
      <?php foreach ($val_rows as $vr):
        $vc = $vr['validation_status'] === 'valid' ? '#00703c' : ($vr['validation_status'] === 'invalid' ? '#d4351c' : '#505a5f');
        $vi = $vr['validation_status'] === 'valid' ? '✓'       : ($vr['validation_status'] === 'invalid' ? '✗'       : '?');
        $txn_link = '?section=suppliers&supplier=' . urlencode($vr['supplier_canon']) . '&tab=transactions';
      ?>
      <tr class="govuk-table__row">
        <td class="govuk-table__cell">
          <a href="<?= htmlspecialchars($txn_link) ?>" class="govuk-link"><?= htmlspecialchars($vr['supplier_canon']) ?></a>
        </td>
        <td class="govuk-table__cell"><?= htmlspecialchars($vr['council']) ?></td>
        <td class="govuk-table__cell" style="font-size:0.8rem"><?= htmlspecialchars(mb_strimwidth($vr['service'] ?? '', 0, 40, '…')) ?></td>
        <td class="govuk-table__cell govuk-table__cell--numeric"><?= fmt_value($vr['amount']) ?></td>
        <td class="govuk-table__cell" style="white-space:nowrap"><?= htmlspecialchars($vr['paid_date'] ?? '') ?></td>
        <td class="govuk-table__cell" style="color:<?= $vc ?>;font-weight:bold"><?= $vi ?> <?= htmlspecialchars($vr['validation_status']) ?></td>
        <td class="govuk-table__cell" style="font-size:0.75rem;color:#505a5f;word-break:break-word;max-width:600px"><?= htmlspecialchars($vr["validation_notes"] ?? "") ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($val_pages > 1): ?>
  <nav class="govuk-pagination" role="navigation">
    <?php if ($val_page > 1): ?>
    <div class="govuk-pagination__prev">
      <a class="govuk-link govuk-pagination__link" href="?section=validation&val_status=<?= urlencode($val_filter) ?>&val_page=<?= $val_page-1 ?>">Previous</a>
    </div>
    <?php endif; ?>
    <ul class="govuk-pagination__list">
      <?php for ($p = max(1,$val_page-2); $p <= min($val_pages,$val_page+2); $p++): ?>
      <li class="govuk-pagination__item<?= $p===$val_page?' govuk-pagination__item--current':'' ?>">
        <a class="govuk-link govuk-pagination__link" href="?section=validation&val_status=<?= urlencode($val_filter) ?>&val_page=<?= $p ?>"><?= $p ?></a>
      </li>
      <?php endfor; ?>
    </ul>
    <?php if ($val_page < $val_pages): ?>
    <div class="govuk-pagination__next">
      <a class="govuk-link govuk-pagination__link" href="?section=validation&val_status=<?= urlencode($val_filter) ?>&val_page=<?= $val_page+1 ?>">Next</a>
    </div>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php else: ?>
  <p class="govuk-body-s govuk-!-colour-secondary">No validated records yet. Run <code>php validate_spend_sample.php --council='Council Name'</code> to begin.</p>
  <?php endif; ?>

<?php elseif ($section === 'councils'): ?>

<?php
  $councils_qs = 'section=councils'
    . '&fy=' . urlencode($fy_selected)
    . '&year=' . urlencode($year_selected)
    . '&ctype=' . urlencode($ctype_selected)
    . '&region=' . urlencode($region_selected)
    . '&ca=' . urlencode($ca_selected)
    . '&q=' . urlencode($council_search)
    . '&sort=' . urlencode($council_sort);

  $council_names_in_order = array_column($council_totals, 'council');
  $council_selected_index = $council ? array_search($council, $council_names_in_order, true) : false;
?>

<?php if ($council_selected_index !== false): ?>
  <?php
    $ct = $council_totals[$council_selected_index];

    $cs_where  = ['council = ?'];
    $cs_params = [$ct['council']];
    if ($date_from) { $cs_where[] = 'paid_date >= ?'; $cs_params[] = $date_from; }
    if ($date_to)   { $cs_where[] = 'paid_date <= ?'; $cs_params[] = $date_to; }
    $cs_sql = implode(' AND ', $cs_where);

    $cs_stmt = $pdo->prepare("
        SELECT supplier_canon, SUM(amount) AS total, COUNT(*) AS payments
        FROM ct_transparency_spend
        WHERE {$cs_sql}
        GROUP BY supplier_canon
        ORDER BY total DESC

    ");
    $cs_stmt->execute($cs_params);
    $cs_rows = $cs_stmt->fetchAll();
    $cs_max  = $cs_rows ? (float)$cs_rows[0]['total'] : 1;

    $trend_stmt = $pdo->prepare("
        SELECT period, SUM(amount) AS total
        FROM ct_transparency_spend
        WHERE {$cs_sql}
        GROUP BY period
    ");
    $trend_stmt->execute($cs_params);
    $trend = [];
    foreach ($trend_stmt->fetchAll() as $row) { $trend[$row['period']] = (float)$row['total']; }

    if ($date_from) {
        $trend_periods = [];
        $cursor = new DateTime($date_from);
        $end    = new DateTime($date_to);
        while ($cursor <= $end) {
            $trend_periods[] = $cursor->format('Y-m');
            $cursor->modify('+1 month');
        }
    } else {
        $trend_periods = $periods;
        sort($trend_periods);
    }
    $trend_max = $trend ? max($trend) : 0;

    // Link to the council's own published data. listing_url (a deliberately
    // curated human-readable index page) is always shown when set. Otherwise
    // fall back to the most recent active source URL, but only if it's a
    // genuine external link -- excluding web.archive.org snapshots (the
    // council's own site is blocked, so a live link is no more useful than
    // the page the user is already on) and file:// paths (locally
    // re-extracted historical archives, e.g. Birmingham/Trafford), which
    // aren't "the council's page" either.
    $source_link_url   = null;
    $source_link_label = '';
    if ($ct['listing_url']) {
        $source_link_url   = $ct['listing_url'];
        $source_link_label = "Council's published spend data";
    } else {
        $latest_source_stmt = $pdo->prepare("
            SELECT url FROM ct_spend_sources
            WHERE council = ? AND active = 1
              AND url NOT LIKE '%web.archive.org%'
              AND url NOT LIKE 'file://%'
            ORDER BY period DESC LIMIT 1
        ");
        $latest_source_stmt->execute([$ct['council']]);
        $latest_url = $latest_source_stmt->fetchColumn();
        if ($latest_url) {
            $source_link_url   = $latest_url;
            $source_link_label = 'Latest published data file';
        }
    }
  ?>

  <?php
    $back_param = $_GET['back'] ?? '';
    $back_href  = (is_string($back_param) && $back_param !== '' && $back_param[0] === '?')
        ? $back_param
        : '?' . $councils_qs;
  ?>
  <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:8px">
    <h2 class="govuk-heading-l govuk-!-margin-bottom-0"><?= htmlspecialchars($ct['council']) ?></h2>
    <a href="<?= htmlspecialchars($back_href) ?>" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0" style="flex-shrink:0">Back</a>
  </div>
  <p class="govuk-body-s" style="color:#505a5f;margin-bottom:24px">
    <?= htmlspecialchars($ct['council_type']) ?><?= ($ct['council_type'] && $ct['region']) ? ' · ' : '' ?><?= htmlspecialchars($ct['region']) ?>
    <?php // Hide any "No current ..." placeholder (strategic/combined authority variants). Matches council.php guard.
          if (!empty($ct['combined_authority']) && strpos($ct['combined_authority'], 'No current ') !== 0): ?>
      · <?= htmlspecialchars($ct['combined_authority']) ?>
    <?php endif; ?>
    <?php if ($ct['from_date']): ?>
      · <?= date('d/m/Y', strtotime($ct['from_date'])) ?> – <?= date('d/m/Y', strtotime($ct['to_date'])) ?>
    <?php endif; ?>
    <?php if ($source_link_url): ?>
      · <a class="govuk-link" href="<?= htmlspecialchars($source_link_url) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($source_link_label) ?> →</a>
    <?php endif; ?>
  </p>

  <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap">
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= fmt_value($ct['total']) ?></div>
      <div class="ct-stat-card__label">Total tech payments</div>
    </div>
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= number_format($ct['payments']) ?></div>
      <div class="ct-stat-card__label">Transactions</div>
    </div>
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= number_format($ct['suppliers']) ?></div>
      <div class="ct-stat-card__label">Unique suppliers</div>
    </div>
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= fmt_value((float)$ct['avg_payment']) ?></div>
      <div class="ct-stat-card__label">Average payment</div>
    </div>
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= fmt_value($ct['max_payment']) ?></div>
      <div class="ct-stat-card__label">Largest payment</div>
    </div>
    <?php if ($ct['population']): ?>
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number">£<?= number_format($ct['total'] / $ct['population'], 2) ?></div>
      <div class="ct-stat-card__label">Spend per resident</div>
    </div>
    <?php endif; ?>
    <?php
      // Only meaningful when a single financial year is selected — dividing an
      // all-time or partial-calendar-year total by a single-year budget would
      // mislead. $fy_selected is '' for "All time"; $year_selected is a partial
      // calendar-year filter. Require a clean FY with no calendar-year override.
      // DISABLED: the imported net_revenue_exp is net-of-financing (too small for
      // shire districts — yields impossible >100% ratios). Re-enable once the
      // denominator is switched to gross/total service expenditure. See ct_la_reference.
      $show_budget_pct = false && !empty($ct['net_revenue_exp']) && (float)$ct['net_revenue_exp'] > 0
                       && $fy_selected !== '' && $year_selected === '';
    ?>
    <?php if ($show_budget_pct): // net_revenue_exp is £ thousands ?>
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= number_format($ct['total'] / ((float)$ct['net_revenue_exp'] * 1000) * 100, 2) ?>%</div>
      <div class="ct-stat-card__label">Tech spend as % of budget</div>
      <div class="ct-stat-card__sub" style="font-size:0.7rem;color:#505a5f">Net revenue exp. <?= htmlspecialchars((string)$ct['budget_year']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($trend_periods): ?>
  <h3 class="govuk-heading-s govuk-!-margin-bottom-2">Monthly spend trend</h3>
  <div style="display:flex;align-items:flex-end;gap:2px;height:80px;margin-bottom:4px">
    <?php foreach ($trend_periods as $tp): ?>
    <?php $tv = $trend[$tp] ?? 0; ?>
    <div title="<?= $tp ?>: <?= fmt_value($tv) ?>"
         style="flex:1;min-width:1px;background:#1d70b8;border-radius:1px 1px 0 0;height:<?= $trend_max > 0 ? max(2, round($tv / $trend_max * 100)) : 0 ?>%"></div>
    <?php endforeach; ?>
  </div>
  <div class="govuk-body-s" style="color:#6f777b;display:flex;justify-content:space-between;margin-bottom:24px">
    <span><?= date('M Y', strtotime($trend_periods[0].'-01')) ?></span>
    <span><?= date('M Y', strtotime($trend_periods[count($trend_periods)-1].'-01')) ?></span>
  </div>
  <?php endif; ?>
  <h3 class="govuk-heading-s govuk-!-margin-bottom-2">Suppliers</h3>
  <?php if ($cs_rows): ?>
  <?php foreach ($cs_rows as $cs): ?>
  <div class="ct-supplier-bar">
    <span class="ct-supplier-bar__label"><a class="govuk-link" href="?section=suppliers&supplier=<?= urlencode($cs['supplier_canon']) ?>"><?= htmlspecialchars($cs['supplier_canon']) ?></a></span>
    <div class="ct-supplier-bar__track">
      <div class="ct-supplier-bar__fill" style="width:<?= round($cs['total']/$cs_max*100) ?>%"></div>
    </div>
    <span class="ct-supplier-bar__val"><?= fmt_value($cs['total']) ?></span>
    <span class="ct-supplier-bar__sub"><?= $cs['payments'] ?></span>
  </div>
  <?php endforeach; ?>
  <?php else: ?>
    <p class="govuk-body-s govuk-!-colour-secondary">No payments found for this date range.</p>
  <?php endif; ?>

<?php else: ?>

<form method="get" action="/contracts/transparency.php" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;margin-bottom:24px">
  <input type="hidden" name="section" value="councils">
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="q_councils">Search councils</label>
    <input class="govuk-input govuk-input--width-20" type="text" id="q_councils" name="q"
           value="<?= htmlspecialchars($council_search) ?>" placeholder="e.g. Kent">
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="sort_councils">Sort by</label>
    <select class="govuk-select" id="sort_councils" name="sort" onchange="this.form.submit()">
      <?php foreach ($council_sort_options as $sval => $slabel): ?>
        <option value="<?= $sval ?>"<?= $council_sort===$sval?' selected':'' ?>><?= $slabel ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="fy_councils">Financial year</label>
    <select class="govuk-select" id="fy_councils" name="fy" onchange="document.getElementById('year_councils').value='';this.form.submit()">
      <?php foreach ($fy_options as $fval => $flabel): ?>
        <option value="<?= $fval ?>"<?= $fy_selected===$fval?' selected':'' ?>><?= $flabel ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="govuk-form-group govuk-!-margin-bottom-0">
    <label class="govuk-label govuk-!-font-size-16" for="year_councils">Year</label>
    <select class="govuk-select" id="year_councils" name="year" onchange="document.getElementById('fy_councils').value='';this.form.submit()">
      <?php foreach ($year_options as $yval => $ylabel): ?>
        <option value="<?= $yval ?>"<?= $year_selected===(string)$yval?' selected':'' ?>><?= $ylabel ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="govuk-button govuk-!-margin-bottom-0" type="submit">Filter</button>
  <?php if ($council_search || $council_sort !== 'total_desc'): ?>
    <a href="?section=councils&fy=<?= urlencode($fy_selected) ?>&year=<?= urlencode($year_selected) ?>" class="govuk-link" style="align-self:center">Clear</a>
  <?php endif; ?>
</form>

<?php if (empty($council_totals)): ?>
  <p class="govuk-body">No data found for the selected date range.</p>
<?php else: ?>
<?php
  $councils_per_page = 10;
  $total_council_pages = max(1, (int)ceil(count($council_totals) / $councils_per_page));
  $council_page = max(1, min($total_council_pages, (int)($_GET['page'] ?? 1)));
  $page_councils = array_slice($council_totals, ($council_page - 1) * $councils_per_page, $councils_per_page);
?>
<div id="councils-list">
  <?php foreach ($page_councils as $pc): ?>
  <a href="?<?= $councils_qs ?>&council=<?= council_url_id($pc['council'], $pc['lad_code']) ?>" style="text-decoration:none;color:inherit">
    <div class="ct-council-card">
      <strong class="govuk-body govuk-!-margin-bottom-0"><?= htmlspecialchars($pc['council']) ?></strong>
      <span class="govuk-body-s ct-council-card__meta">
        <?= fmt_value($pc['total']) ?> · <?= number_format($pc['payments']) ?> transactions
        <?php if ($pc['from_date']): ?>
          · <?= date('d/m/Y', strtotime($pc['from_date'])) ?> – <?= date('d/m/Y', strtotime($pc['to_date'])) ?>
        <?php endif; ?>
        · View details →
      </span>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($total_council_pages > 1): ?>
<nav class="govuk-pagination" role="navigation" aria-label="Councils pagination">
  <?php if ($council_page > 1): ?>
  <div class="govuk-pagination__prev">
    <a class="govuk-link govuk-pagination__link" href="?<?= $councils_qs ?>&page=<?= $council_page-1 ?>" rel="prev">
      <svg class="govuk-pagination__icon govuk-pagination__icon--prev" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
        <path d="m6.5938-0.0078125-6.7266 6.7266 6.7441 6.4062 1.377-1.4453-4.1856-3.9768h12.896v-2h-12.984l4.2931-4.293-1.414-1.414z"></path>
      </svg>
      <span class="govuk-pagination__link-title">Previous</span>
    </a>
  </div>
  <?php endif; ?>
  <ul class="govuk-pagination__list">
    <?php for ($p = 1; $p <= $total_council_pages; $p++): ?>
    <li class="govuk-pagination__item<?= $p===$council_page?' govuk-pagination__item--current':'' ?>">
      <a class="govuk-link govuk-pagination__link" href="?<?= $councils_qs ?>&page=<?= $p ?>" aria-label="Page <?= $p ?>"<?= $p===$council_page?' aria-current="page"':'' ?>><?= $p ?></a>
    </li>
    <?php endfor; ?>
  </ul>
  <?php if ($council_page < $total_council_pages): ?>
  <div class="govuk-pagination__next">
    <a class="govuk-link govuk-pagination__link" href="?<?= $councils_qs ?>&page=<?= $council_page+1 ?>" rel="next">
      <span class="govuk-pagination__link-title">Next</span>
      <svg class="govuk-pagination__icon govuk-pagination__icon--next" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
        <path d="m8.107-0.0078125-1.4136 1.414 4.2926 4.293h-12.986v2h12.896l-4.1855 3.9768 1.377 1.4453 6.7441-6.4062-6.7246-6.7266z"></path>
      </svg>
    </a>
  </div>
  <?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?>

<?php elseif ($section === 'sa_detail'): ?>

<?php
  $ca_detail_name = trim($_GET['ca'] ?? '');
  $ca_detail_cfg_stmt = $pdo->prepare("SELECT 1 FROM ct_ca_config WHERE ca_name = ?");
  $ca_detail_cfg_stmt->execute([$ca_detail_name]);
  $ca_detail_exists = (bool)$ca_detail_cfg_stmt->fetchColumn();
?>
<?php if (!$ca_detail_exists): ?>
  <p class="govuk-body">Combined authority not found.</p>
<?php else: ?>
<?php
  $cad_where  = ['ca_name = ?'];
  $cad_params = [$ca_detail_name];
  if ($date_from) { $cad_where[] = 'paid_date >= ?'; $cad_params[] = $date_from; }
  if ($date_to)   { $cad_where[] = 'paid_date <= ?'; $cad_params[] = $date_to; }
  $cad_sql = implode(' AND ', $cad_where);

  $cad_totals_stmt = $pdo->prepare("
      SELECT SUM(amount) AS total, COUNT(*) AS payments,
             COUNT(DISTINCT supplier_canon) AS suppliers,
             AVG(amount) AS avg_payment, MAX(amount) AS max_payment,
             MIN(paid_date) AS from_date, MAX(paid_date) AS to_date
      FROM ct_ca_spend WHERE {$cad_sql}
  ");
  $cad_totals_stmt->execute($cad_params);
  $cad = $cad_totals_stmt->fetch();

  $cad_cs_stmt = $pdo->prepare("
      SELECT supplier_canon, SUM(amount) AS total, COUNT(*) AS payments
      FROM ct_ca_spend WHERE {$cad_sql}
      GROUP BY supplier_canon ORDER BY total DESC
  ");
  $cad_cs_stmt->execute($cad_params);
  $cad_cs_rows = $cad_cs_stmt->fetchAll();
  $cad_cs_max  = $cad_cs_rows ? (float)$cad_cs_rows[0]['total'] : 1;

  $cad_trend_stmt = $pdo->prepare("SELECT period, SUM(amount) AS total FROM ct_ca_spend WHERE {$cad_sql} GROUP BY period");
  $cad_trend_stmt->execute($cad_params);
  $cad_trend = [];
  foreach ($cad_trend_stmt->fetchAll() as $row) { $cad_trend[$row['period']] = (float)$row['total']; }
  if ($date_from) {
      $cad_trend_periods = [];
      $cursor = new DateTime($date_from);
      $end    = new DateTime($date_to);
      while ($cursor <= $end) {
          $cad_trend_periods[] = $cursor->format('Y-m');
          $cursor->modify('+1 month');
      }
  } else {
      $cad_trend_periods = array_keys($cad_trend);
      sort($cad_trend_periods);
  }
  $cad_trend_max = $cad_trend ? max($cad_trend) : 0;

  $cad_back_param = $_GET['back'] ?? '';
  $cad_back_href  = (is_string($cad_back_param) && $cad_back_param !== '' && $cad_back_param[0] === '?')
      ? $cad_back_param
      : '?section=sa_overview';
?>
  <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:8px">
    <h2 class="govuk-heading-l govuk-!-margin-bottom-0"><?= htmlspecialchars($ca_detail_name) ?></h2>
    <a href="<?= htmlspecialchars($cad_back_href) ?>" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0" style="flex-shrink:0">Back</a>
  </div>
  <p class="govuk-body-s" style="color:#505a5f;margin-bottom:24px">
    Combined authority's own spend (not a constituent council's)
    <?php if ($cad['from_date']): ?>
      · <?= date('d/m/Y', strtotime($cad['from_date'])) ?> – <?= date('d/m/Y', strtotime($cad['to_date'])) ?>
    <?php endif; ?>
  </p>

  <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap">
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= fmt_value((float)$cad['total']) ?></div>
      <div class="ct-stat-card__label">Total tech payments</div>
    </div>
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= number_format((int)$cad['payments']) ?></div>
      <div class="ct-stat-card__label">Transactions</div>
    </div>
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= number_format((int)$cad['suppliers']) ?></div>
      <div class="ct-stat-card__label">Unique suppliers</div>
    </div>
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= fmt_value((float)$cad['avg_payment']) ?></div>
      <div class="ct-stat-card__label">Average payment</div>
    </div>
    <div class="ct-stat-card" style="flex:1 1 140px">
      <div class="ct-stat-card__number"><?= fmt_value((float)$cad['max_payment']) ?></div>
      <div class="ct-stat-card__label">Largest payment</div>
    </div>
  </div>
  <?php if ($cad_trend_periods): ?>
  <h3 class="govuk-heading-s govuk-!-margin-bottom-2">Monthly spend trend</h3>
  <div style="display:flex;align-items:flex-end;gap:2px;height:80px;margin-bottom:4px">
    <?php foreach ($cad_trend_periods as $tp): ?>
    <?php $tv = $cad_trend[$tp] ?? 0; ?>
    <div title="<?= $tp ?>: <?= fmt_value($tv) ?>"
         style="flex:1;min-width:1px;background:#1d70b8;border-radius:1px 1px 0 0;height:<?= $cad_trend_max > 0 ? max(2, round($tv / $cad_trend_max * 100)) : 0 ?>%"></div>
    <?php endforeach; ?>
  </div>
  <div class="govuk-body-s" style="color:#6f777b;display:flex;justify-content:space-between;margin-bottom:24px">
    <span><?= date('M Y', strtotime($cad_trend_periods[0].'-01')) ?></span>
    <span><?= date('M Y', strtotime($cad_trend_periods[count($cad_trend_periods)-1].'-01')) ?></span>
  </div>
  <?php endif; ?>
  <h3 class="govuk-heading-s govuk-!-margin-bottom-2">Suppliers</h3>
  <?php if ($cad_cs_rows): ?>
  <?php foreach ($cad_cs_rows as $cs): ?>
  <div class="ct-supplier-bar">
    <span class="ct-supplier-bar__label"><a class="govuk-link" href="?section=suppliers&supplier=<?= urlencode($cs['supplier_canon']) ?>"><?= htmlspecialchars($cs['supplier_canon']) ?></a></span>
    <div class="ct-supplier-bar__track">
      <div class="ct-supplier-bar__fill" style="width:<?= round($cs['total']/$cad_cs_max*100) ?>%"></div>
    </div>
    <span class="ct-supplier-bar__val"><?= fmt_value($cs['total']) ?></span>
    <span class="ct-supplier-bar__sub"><?= $cs['payments'] ?></span>
  </div>
  <?php endforeach; ?>
  <?php else: ?>
    <p class="govuk-body-s govuk-!-colour-secondary">No payments found for this date range.</p>
  <?php endif; ?>
<?php endif; ?>

<?php endif; ?>


<div class="govuk-inset-text govuk-!-margin-top-6">
  <p class="govuk-body-s govuk-!-margin-bottom-0">
    <a href="/contracts/sources.php" class="govuk-link">Manage sources</a>
  </p>
</div>

<script>
document.querySelectorAll('.sortable-table').forEach(function(table) {
  var tbody = table.querySelector('tbody');
  var headers = table.querySelectorAll('th.sortable');
  var sortCol = -1, sortAsc = true;
  headers.forEach(function(th, i) {
    if (th.classList.contains('sort-desc')) { sortCol = i; sortAsc = false; }
    th.style.cursor = 'pointer';
    th.addEventListener('click', function() {
      sortAsc = (sortCol === i) ? !sortAsc : (th.dataset.type === 'str');
      sortCol = i;
      headers.forEach(function(h) { h.querySelector('.sort-icon').textContent = '⇅'; });
      th.querySelector('.sort-icon').textContent = sortAsc ? '↑' : '↓';
      var rows = Array.from(tbody.querySelectorAll('tr'));
      rows.sort(function(a, b) {
        var ac = a.querySelectorAll('td')[i], bc = b.querySelectorAll('td')[i];
        var av = ac.dataset.val !== undefined ? parseFloat(ac.dataset.val) : ac.textContent.trim();
        var bv = bc.dataset.val !== undefined ? parseFloat(bc.dataset.val) : bc.textContent.trim();
        return typeof av === 'string'
          ? (sortAsc ? av.localeCompare(bv) : bv.localeCompare(av))
          : (sortAsc ? av - bv : bv - av);
      });
      rows.forEach(function(r) { tbody.appendChild(r); });
    });
  });
});
</script>

<?php layout_foot(); ?>
