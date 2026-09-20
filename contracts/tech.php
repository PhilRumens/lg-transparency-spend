<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// Tech CPV divisions
const TECH_CPVS = ['30','32','48','64','72','73'];
const TECH_CPV_LABELS = [
    '30' => 'IT Hardware',
    '32' => 'Communications Equipment',
    '48' => 'Software',
    '64' => 'Post & Telecommunications',
    '72' => 'IT Services',
    '73' => 'R&D Services',
];

// Active section
$section = $_GET['section'] ?? 'overview';


// ── Shared tech WHERE ─────────────────────────────────────────────────────
$date_cond = '';
$cpv_in = implode(',', array_map(fn($c) => "'$c'", TECH_CPVS));

// ── A: SUPPLIER MARKET SHARE ──────────────────────────────────────────────
// Suppliers are in supplier_names (pipe-separated). We need to split them.
// We'll rank by total value and notice count from ct_contracts directly.
// For supplier analysis we query supplier_names field grouped by it


// ── Supplier name normalisation map ──────────────────────────────────────
// Maps lowercase raw name variants → canonical display name
const SUPPLIER_NORM = [
    // Civica
    'civica uk limited'          => 'Civica UK',
    'civica uk ltd'              => 'Civica UK',
    'civica uk ltd.'             => 'Civica UK',
    'civica uk'                  => 'Civica UK',
    'civica'                     => 'Civica UK',
    'civica uk limited.'         => 'Civica UK',
    // Phoenix Software
    'phoenix software ltd'       => 'Phoenix Software',
    'phoenix software limited'   => 'Phoenix Software',
    'phoenix software'           => 'Phoenix Software',
    'phoenix software ltd.'      => 'Phoenix Software',
    // Idox
    'idox software ltd'          => 'Idox Software',
    'idox software limited'      => 'Idox Software',
    'idox software'              => 'Idox Software',
    'idox group'                 => 'Idox Software',
    // Softcat
    'softcat plc'                => 'Softcat',
    'softcat plc.'               => 'Softcat',
    'softcat'                    => 'Softcat',
    // NEC Software Solutions
    'nec software solutions uk limited' => 'NEC Software Solutions',
    'nec software solutions uk ltd'     => 'NEC Software Solutions',
    'nec software solutions'            => 'NEC Software Solutions',
    // Insight Direct
    'insight direct (uk) ltd'       => 'Insight Direct',
    'insight direct (uk) limited'   => 'Insight Direct',
    'insight direct (gb) limited'   => 'Insight Direct',
    'insight direct (uk)'           => 'Insight Direct',
    // Access Group
    'access uk ltd'              => 'The Access Group',
    'access uk limited'          => 'The Access Group',
    'the access group'           => 'The Access Group',
    // CDW
    'cdw limited'                => 'CDW',
    'cdw ltd'                    => 'CDW',
    // Bytes
    'bytes software services'          => 'Bytes Software Services',
    'bytes software services limited'  => 'Bytes Software Services',
    'bytes software services ltd'      => 'Bytes Software Services',
    // Granicus-Firmstep
    'granicus-firmstep limited'  => 'Granicus-Firmstep',
    'granicus firmstep limited'  => 'Granicus-Firmstep',
    'granicus-firmstep ltd'      => 'Granicus-Firmstep',
    'granicus-firmstep'          => 'Granicus-Firmstep',
    // MRI Software
    'mri software limited'       => 'MRI Software',
    'mri software'               => 'MRI Software',
    // Causeway Technologies
    'causeway technologies limited' => 'Causeway Technologies',
    'causeway technologies ltd'     => 'Causeway Technologies',
    // Beam Up
    'beam up ltd'                => 'Beam Up',
    'beam up'                    => 'Beam Up',
    // Oracle
    'oracle corporation uk limited' => 'Oracle',
    'oracle corporation uk ltd'     => 'Oracle',
    'oracle corporation uk'         => 'Oracle',
    // British Telecom
    'british telecommunications plc'  => 'BT',
    'british telecommunications plc.' => 'BT',
    'british telecommunications'      => 'BT',
    'bt plc'                           => 'BT',
    'bt ltd'                           => 'BT',
    'british telecommunication plc'   => 'BT',
    // AtkinsRealis
    'atkinsrealis'               => 'AtkinsRealis',
    'atkins realis'              => 'AtkinsRealis',
    // Cantium
    'cantium business solutions'  => 'Cantium',
    // Bechtle
    'bechtle'                     => 'Bechtle',
    // Datto
    'datto'                       => 'Datto',
    // Avoira
    'avoira'                      => 'Avoira',
    // Education Software Solutions
    'education software solutions' => 'Education Software Solutions',
    // InPhase
    'inphase'                     => 'InPhase',
    // Nexus Fusion
    'nexus fusion'                => 'Nexus Fusion',
    // Prism Infosec
    'prism infosec'               => 'Prism Infosec',
    // Airwave
    'airwave'                     => 'Airwave',
    // Virgin Media Business
    'virgin media business limited'   => 'Virgin Media Business',
    'virgin media business ltd'       => 'Virgin Media Business',
    'virgin media business'           => 'Virgin Media Business',
    // Vodafone
    'vodafone limited'           => 'Vodafone',
    'vodafone'                   => 'Vodafone',
    'vodafone corporate'         => 'Vodafone',
    // Capita
    'capita business services limited' => 'Capita',
    'capita business services ltd'     => 'Capita',
    // Specialist Computer Centres (SCC)
    'specialist computer centres plc'  => 'SCC',
    'specialist computer centres'      => 'SCC',
    // XMA
    'xma limited'                => 'XMA',
    'xma ltd'                    => 'XMA',
    'xma'                        => 'XMA',
    // Dell
    'dell corporation limited'   => 'Dell',
    'dell corporation ltd'       => 'Dell',
    'dell corporation ltd.'      => 'Dell',
    // Unit4
    'unit4 business software limited'   => 'Unit4',
    'unit4 business software ltd'       => 'Unit4',
    'unit 4 business software limited'  => 'Unit4',
    // Boxxe
    'boxxe limited'              => 'Boxxe',
    'boxxe ltd'                  => 'Boxxe',
    'boxxe'                      => 'Boxxe',
    // ESRI
    'esri (uk) limited'          => 'Esri UK',
    'esri (uk) ltd'              => 'Esri UK',
    'esri uk limited'            => 'Esri UK',
    // Trustmarque
    'trustmarque solutions ltd'     => 'Trustmarque',
    'trustmarque solutions limited' => 'Trustmarque',
    // Netcall
    'netcall technology limited' => 'Netcall',
    'netcall uk limited'         => 'Netcall',
    // Liquidlogic
    'liquidlogic limited'        => 'Liquidlogic',
    'liquidlogic'                => 'Liquidlogic',
    // Brightly Software
    'brightly software limited'  => 'Brightly Software',
    'brightly software ltd'      => 'Brightly Software',
    // Symology
    'symology ltd'               => 'Symology',
    'symology limited'           => 'Symology',
    // CACI
    'caci limited'               => 'CACI',
    'caci ltd'                   => 'CACI',
    // Banner Group
    'banner group limited'       => 'Banner Group',
    'banner group ltd'           => 'Banner Group',
    // Microsoft
    'microsoft limited'          => 'Microsoft',
    'microsoft'                  => 'Microsoft',
    // Delt Shared Services
    'delt shared services ltd'   => 'Delt Shared Services',
    // SAP
    'sap (uk) ltd'               => 'SAP',
    'sap (uk) limited'           => 'SAP',
    // iESE
    'iese innovation ltd'        => 'iESE',
    // Iken
    'iken business limited'      => 'Iken',
    'iken business ltd'          => 'Iken',
    // Concerto
    'concerto support services ltd'     => 'Concerto',
    'concerto support services limited' => 'Concerto',
    // Welfare Call
    'welfare call (lac) ltd'     => 'Welfare Call',
    'welfare call'               => 'Welfare Call',
    // Arcus Global
    'arcus global limited'       => 'Arcus Global',
    'arcus global ltd'           => 'Arcus Global',
    // Civica — all variants including Election Services
    'civica election services limited'  => 'Civica',
    'civica election services ltd'      => 'Civica',
    'civica election services'          => 'Civica',
    'civica elections services limited' => 'Civica',
    'civica elections services ltd'     => 'Civica',
    'civica xpress - civica uk ltd'     => 'Civica',
    'civica group ltd'                  => 'Civica',
    'civica uk limmited'                => 'Civica',
    'civica uk litd'                    => 'Civica',
    'civica uk limited.'                => 'Civica',
    // New suppliers from June 2026 refresh
    'veran performance limited'         => 'Veran Performance',
    'version 1 solutions limited'       => 'Version 1',
    'newton consulting limited'         => 'Newton Consulting',
    'crayon limited'                    => 'Crayon',
    'iris software limited'             => 'IRIS Software',
    'hcl technologies uk limited'       => 'HCL Technologies',
    'the networking people (tnp) ltd'   => 'TNP',
    'allstar business solutions ltd'    => 'Allstar Business Solutions',
    'allstar business solutions limited'=> 'Allstar Business Solutions',
    'unicard limited'                   => 'Unicard',
    'centerprise international limited' => 'Centerprise International',
    'centerprise international'         => 'Centerprise International',
    'ernst & young llp'                 => 'EY',
    'cgi it uk limited'                 => 'CGI',
    'ultima business solutions limited' => 'Ultima Business Solutions',
    'ultima business solutions ltd'     => 'Ultima Business Solutions',
    'ics ai ltd'                        => 'ICS AI',
    'fortrus limited'                   => 'Fortrus',
    'whistl uk limited'                 => 'Whistl',
    'exponential-e limited'             => 'Exponential-e',
    'jadu creative limited'             => 'Jadu',
    'advanced business software and solutions limited' => 'Advanced Business Solutions',
    'zellis uk limited'                 => 'Zellis',
    'zellis uk ltd'                     => 'Zellis',
    'lyreco uk limited'                 => 'Lyreco',
    'gartner uk limited'                => 'Gartner',
    'totalmobile ltd'                   => 'Totalmobile',
    'totalmobile limited'               => 'Totalmobile',
    'total mobile ltd'                  => 'Totalmobile',
    'heywood limited'                   => 'Heywood',
    'delt shared services ltd'          => 'Delt Shared Services',
    'kpmg llp'                          => 'KPMG',
    'aecom'                             => 'AECOM',
    'royal mail group limited'          => 'Royal Mail',
    'arcus global limited'              => 'Arcus Global',
    'arcus global ltd'                  => 'Arcus Global',
    'banner group limited'              => 'Banner Group',
    'ringgo limited'                    => 'RingGo',
    'liquid logic'                      => 'Liquidlogic',
    'liquidlogic ltd'                   => 'Liquidlogic',
];

// ── Supplier awarded values from BigQuery (refreshed June 2026) ───────────
// Source: aggregate of awarded_amount from compiled_process, LG buyers,
// tech CPVs (30/32/48/64/72/73), award stage, Jan 2024–Jun 2026.
// Civica now includes Election Services variants (313 total across all name variants).
const SUPPLIER_BQ_VALUES = [
    'Veran Performance'            => ['notices' => 3,   'tv' => 411050481],
    'Phoenix Software'             => ['notices' => 175, 'tv' => 196723626],
    'Version 1'                    => ['notices' => 4,   'tv' => 195779456],
    'Softcat'                      => ['notices' => 162, 'tv' => 166075869],
    'Insight Direct'               => ['notices' => 101, 'tv' => 70985568],
    'Bytes'                        => ['notices' => 54,  'tv' => 70437868],
    'Civica'                       => ['notices' => 269, 'tv' => 67361230],
    'SCC'                          => ['notices' => 33,  'tv' => 46408537],
    'Allstar Business Solutions'   => ['notices' => 11,  'tv' => 45681350],
    'NEC Software Solutions'       => ['notices' => 63,  'tv' => 44933213],
    'CDW'                          => ['notices' => 39,  'tv' => 39246557],
    'The Access Group'             => ['notices' => 68,  'tv' => 37051391],
    'Liquidlogic'                  => ['notices' => 14,  'tv' => 35009587],
    'Oracle'                       => ['notices' => 28,  'tv' => 32903366],
    'Newton Consulting'            => ['notices' => 2,   'tv' => 30225000],
    'Virgin Media Business'        => ['notices' => 25,  'tv' => 26629676],
    'KPMG'                         => ['notices' => 8,   'tv' => 23517094],
    'Idox Software'                => ['notices' => 90,  'tv' => 21211013],
    'SAP'                          => ['notices' => 3,   'tv' => 20570691],
    'Capita'                       => ['notices' => 30,  'tv' => 20016873],
    'HCL Technologies'             => ['notices' => 5,   'tv' => 19095236],
    'XMA'                          => ['notices' => 36,  'tv' => 18737141],
    'Trustmarque'                  => ['notices' => 17,  'tv' => 18529624],
    'TNP'                          => ['notices' => 6,   'tv' => 17701542],
    'BT'                           => ['notices' => 28,  'tv' => 17489213],
    'Ultima Business Solutions'    => ['notices' => 14,  'tv' => 16266590],
    'Crayon'                       => ['notices' => 2,   'tv' => 16067500],
    'Brightly Software'            => ['notices' => 11,  'tv' => 15988308],
    'Dell'                         => ['notices' => 26,  'tv' => 14714208],
    'IRIS Software'                => ['notices' => 2,   'tv' => 13372203],
    'Agilisys'                     => ['notices' => 4,   'tv' => 13241040],
    'CACI'                         => ['notices' => 19,  'tv' => 12397478],
    'AECOM'                        => ['notices' => 3,   'tv' => 12124064],
    'Netcall'                      => ['notices' => 9,   'tv' => 11733267],
    'Royal Mail'                   => ['notices' => 6,   'tv' => 10434000],
    'Unicard'                      => ['notices' => 3,   'tv' => 9560868],
    'Vodafone'                     => ['notices' => 18,  'tv' => 9051277],
    'MRI Software'                 => ['notices' => 31,  'tv' => 8674187],
    'Totalmobile'                  => ['notices' => 12,  'tv' => 8530638],
    'Boxxe'                        => ['notices' => 25,  'tv' => 8514582],
    'Centerprise International'    => ['notices' => 3,   'tv' => 8468450],
    'Lyreco'                       => ['notices' => 4,   'tv' => 8435000],
    'EY'                           => ['notices' => 5,   'tv' => 7915105],
    'Microsoft'                    => ['notices' => 8,   'tv' => 7672212],
    'CGI'                          => ['notices' => 2,   'tv' => 7607364],
    'Unit4'                        => ['notices' => 16,  'tv' => 7528838],
    'ICS AI'                       => ['notices' => 2,   'tv' => 7066665],
    'Symology'                     => ['notices' => 15,  'tv' => 6944705],
    'Causeway Technologies'        => ['notices' => 33,  'tv' => 6603311],
    'Zellis'                       => ['notices' => 7,   'tv' => 6244874],
];

function normalise_supplier(string $name): string {
    $lower = strtolower(trim($name));
    return SUPPLIER_NORM[$lower] ?? $name;
}

// ── Supplier analysis from supplier_names field ──────────────────────────
// supplier_names is pipe-separated; we need to split and aggregate.
// Use a helper that splits each row's pipe-delimited string in PHP.
$raw_suppliers = $pdo->query("
    SELECT supplier_names, value_amount, cpv_division, status
    FROM ct_contracts
    WHERE cpv_division IN ({$cpv_in}){$date_cond}
      AND supplier_names IS NOT NULL
      AND supplier_names != ''
")->fetchAll();

// Aggregate per supplier name
$supplier_agg = [];
foreach ($raw_suppliers as $row) {
    $names = array_values(array_filter(array_map('trim', explode('|', $row['supplier_names']))));
    $name_count = count($names);
    // Cap value at £500m to exclude framework maximums / data errors
    $raw_value = (float)($row['value_amount'] ?? 0);
    $capped_value = min($raw_value, 500_000_000);
    // Divide value equally among suppliers on multi-supplier awards
    $per_supplier_value = $name_count > 0 ? $capped_value / $name_count : 0;

    foreach ($names as $name) {
        if (!$name || strtolower($name) === 'not awarded' || strtolower($name) === 'various') continue;
        $name = normalise_supplier($name);
        if (!isset($supplier_agg[$name])) {
            $supplier_agg[$name] = ['notices'=>0, 'tv'=>0.0, 'active_n'=>0, 'cpvs'=>[]];
        }
        $supplier_agg[$name]['notices']++;
        // Only count value if stated (don't inflate with zeroes from missing values)
        if ($raw_value > 0) {
            $supplier_agg[$name]['tv'] += $per_supplier_value;
        }
        if ($row['status'] === 'active') $supplier_agg[$name]['active_n']++;
        $cpv = $row['cpv_division'] ?? '';
        if ($cpv) $supplier_agg[$name]['cpvs'][$cpv] = ($supplier_agg[$name]['cpvs'][$cpv] ?? 0) + 1;
    }
}

// Sort by notices desc
uasort($supplier_agg, fn($a,$b) => $b['notices'] <=> $a['notices']);

// Merge local notice counts with BQ awarded values
// Local counts respect the date filter; BQ values used for awarded £ figures
$merged = [];
foreach (SUPPLIER_BQ_VALUES as $name => $bq) {
    $local = $supplier_agg[$name] ?? ['notices' => 0, 'active_n' => 0];
    $merged[$name] = [
        'vendor_name' => $name,
        'notices'     => (int)$bq['notices'],
        'tv'          => (float)$bq['tv'],
        'active_n'    => 0,
    ];
}
// Add any local suppliers not in BQ list (with local data only, no value)
foreach ($supplier_agg as $name => $data) {
    if (!isset(SUPPLIER_BQ_VALUES[$name]) && $data['notices'] >= 3) {
        $merged[$name] = [
            'vendor_name' => $name,
            'notices'     => $data['notices'],
            'tv'          => 0.0,
            'active_n'    => 0,
        ];
    }
}

// Sort by notices for top_suppliers_notices
uasort($merged, fn($a,$b) => $b['notices'] <=> $a['notices']);
$top_suppliers_notices = array_values(array_slice($merged, 0, 30));

// Sort by value for top_suppliers_value
$by_value = $merged;
uasort($by_value, fn($a,$b) => $b['tv'] <=> $a['tv']);
$top_suppliers_value = array_values(array_slice($by_value, 0, 20));

// Max for scaling
$max_notices = $top_suppliers_notices ? (int)$top_suppliers_notices[0]['notices'] : 1;
$max_value   = $top_suppliers_value   ? (float)$top_suppliers_value[0]['tv']      : 1;

// Per-vendor CPV breakdown
$vendor_cpv = [];
foreach (array_slice($supplier_agg, 0, 30, true) as $name => $data) {
    foreach ($data['cpvs'] as $cpv => $n) {
        $vendor_cpv[$name][] = ['cpv_division' => $cpv, 'n' => $n, 'tv' => 0];
    }
}

// ── B: BUYER BENCHMARKING ─────────────────────────────────────────────────
$top_buyers = $pdo->query("
    SELECT buyer_name,
           COUNT(*) AS notices,
           SUM(COALESCE(value_amount,0)) AS tv,
           SUM(CASE WHEN status='active' AND (tender_end_date IS NULL OR tender_end_date >= CURDATE()) THEN 1 ELSE 0 END) AS active_n,
           COUNT(DISTINCT cpv_division) AS cpv_variety,
           MIN(published_date) AS first_seen,
           MAX(published_date) AS last_seen
    FROM ct_contracts
    WHERE cpv_division IN ({$cpv_in}){$date_cond}
    GROUP BY buyer_name
    ORDER BY tv DESC
    LIMIT 30
")->fetchAll();

$top_buyers_by_notices = $pdo->query("
    SELECT buyer_name, COUNT(*) AS notices, SUM(COALESCE(value_amount,0)) AS tv
    FROM ct_contracts
    WHERE cpv_division IN ({$cpv_in}){$date_cond}
    GROUP BY buyer_name
    ORDER BY notices DESC
    LIMIT 30
")->fetchAll();

$max_buyer_value   = $top_buyers ? (float)$top_buyers[0]['tv'] : 1;
$max_buyer_notices = $top_buyers_by_notices ? (int)$top_buyers_by_notices[0]['notices'] : 1;

// Per-buyer CPV breakdown
$buyer_cpv = [];
if (!empty($top_buyers)) {
    $bnames = array_column($top_buyers, 'buyer_name');
    $ph     = implode(',', array_fill(0, count($bnames), '?'));
    $rows   = $pdo->prepare("
        SELECT buyer_name, cpv_division, COUNT(*) AS n, SUM(COALESCE(value_amount,0)) AS tv
        FROM ct_contracts
        WHERE buyer_name IN ({$ph}) AND cpv_division IN ({$cpv_in})
        GROUP BY buyer_name, cpv_division
        ORDER BY buyer_name, tv DESC
    ");
    $rows->execute($bnames);
    foreach ($rows->fetchAll() as $r) {
        $buyer_cpv[$r['buyer_name']][] = $r;
    }
}

// ── C: CATEGORY BREAKDOWN ─────────────────────────────────────────────────
$cat_summary = $pdo->query("
    SELECT cpv_division,
           COUNT(*) AS notices,
           SUM(CASE WHEN value_amount IS NOT NULL THEN 1 ELSE 0 END) AS with_value,
           SUM(COALESCE(value_amount,0)) AS tv,
           AVG(CASE WHEN value_amount > 0 THEN value_amount END) AS avg_value,
           SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_n,
           SUM(CASE WHEN status='complete' THEN 1 ELSE 0 END) AS awarded_n,
           SUM(CASE WHEN YEAR(published_date)=2024 THEN 1 ELSE 0 END) AS n_2024,
           SUM(CASE WHEN YEAR(published_date)=2025 THEN 1 ELSE 0 END) AS n_2025,
           SUM(CASE WHEN YEAR(published_date)=2026 THEN 1 ELSE 0 END) AS n_2026
    FROM ct_contracts
    WHERE cpv_division IN ({$cpv_in}){$date_cond}
    GROUP BY cpv_division
    ORDER BY tv DESC
")->fetchAll();

// Top suppliers per CPV — use local supplier_names aggregation per CPV
$cat_suppliers = [];
foreach (TECH_CPVS as $cpv) {
    $rows = $pdo->prepare("
        SELECT supplier_names, value_amount
        FROM ct_contracts
        WHERE cpv_division = ?
          AND supplier_names IS NOT NULL AND supplier_names != ''
          {$date_cond}
    ");
    $rows->execute([$cpv]);
    $cpv_agg = [];
    foreach ($rows->fetchAll() as $row) {
        $names = array_values(array_filter(array_map('trim', explode('|', $row['supplier_names']))));
        $n_count = count($names);
        $val = (float)($row['value_amount'] ?? 0);
        $per = $n_count > 0 && $val > 0 ? min($val, 500_000_000) / $n_count : 0;
        foreach ($names as $name) {
            if (!$name || in_array(strtolower($name), ['not awarded','various','tbc','n/a'])) continue;
            $canon = normalise_supplier($name);
            // Use BQ value if we have it, otherwise local
            $bq_tv = SUPPLIER_BQ_VALUES[$canon]['tv'] ?? null;
            if (!isset($cpv_agg[$canon])) $cpv_agg[$canon] = ['n'=>0,'tv'=>0.0,'has_bq'=>false];
            $cpv_agg[$canon]['n']++;
            if ($bq_tv === null) $cpv_agg[$canon]['tv'] += $per;
        }
    }
    // Overlay BQ values for known vendors
    foreach (SUPPLIER_BQ_VALUES as $name => $bq) {
        if (isset($cpv_agg[$name])) {
            $cpv_agg[$name]['tv'] = (float)($bq['tv'] ?? 0);
        }
    }
    uasort($cpv_agg, fn($a,$b) => $b['n'] <=> $a['n']);
    $cat_suppliers[$cpv] = [];
    foreach (array_slice($cpv_agg, 0, 8, true) as $vname => $data) {
        $cat_suppliers[$cpv][] = ['vendor_name'=>$vname,'n'=>$data['n'],'tv'=>$data['tv']];
    }
}

// Recent contracts per CPV
$cat_recent = [];
foreach (TECH_CPVS as $cpv) {
    $rows = $pdo->prepare("
        SELECT title, buyer_name, value_amount, published_date, official_url, status, supplier_names
        FROM ct_contracts
        WHERE cpv_division = ? AND status = 'active'
          AND (tender_end_date IS NULL OR tender_end_date >= CURDATE())
        ORDER BY published_date DESC
        LIMIT 5
    ");
    $rows->execute([$cpv]);
    $cat_recent[$cpv] = $rows->fetchAll();
}

// ── OVERVIEW STATS ────────────────────────────────────────────────────────
$overview = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(COALESCE(value_amount,0)) AS tv,
           SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_n,
           COUNT(DISTINCT buyer_name) AS buyers,
           COUNT(DISTINCT cpv_division) AS categories,
           SUM(CASE WHEN value_amount IS NOT NULL THEN 1 ELSE 0 END) AS with_value
    FROM ct_contracts
    WHERE cpv_division IN ({$cpv_in}){$date_cond}
")->fetch();

layout_head('Tech spend intelligence');
?>

<style>
.tech-nav { display:flex; gap:0; margin-bottom:24px; border-bottom:2px solid #b1b4b6; flex-wrap:wrap; }
.tech-nav a { padding:10px 20px; text-decoration:none; color:#0b0c0c; font-size:1rem; font-weight:400; border-bottom:4px solid transparent; margin-bottom:-2px; }
.tech-nav a:hover { border-bottom-color:#b1b4b6; }
.tech-nav a.active { font-weight:700; border-bottom-color:#1d70b8; color:#1d70b8; }
.bar-row { display:flex; align-items:center; gap:10px; margin-bottom:6px; font-size:0.875rem; }
.bar-row__label { width:200px; flex-shrink:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.bar-row__track { flex:1; background:#f3f2f1; height:10px; border-radius:4px; overflow:hidden; }
.bar-row__fill { height:100%; border-radius:4px; background:#1d70b8; }
.bar-row__fill--orange { background:#f47738; }
.bar-row__val { width:80px; text-align:right; flex-shrink:0; font-variant-numeric:tabular-nums; }
.bar-row__sub { width:60px; text-align:right; flex-shrink:0; color:#6f777b; }
.cat-card { border:1px solid #b1b4b6; margin-bottom:20px; }
.cat-card__header { background:#f3f2f1; padding:15px 20px; display:flex; align-items:center; justify-content:space-between; }
.cat-card__title { font-size:1.1rem; font-weight:700; }
.cat-card__body { padding:20px; }
.cat-card__grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.trend-bars { display:flex; gap:4px; align-items:flex-end; height:32px; }
.sort-icon { font-size:0.75rem; margin-left:3px; opacity:0.6; }
th.sort-asc .sort-icon, th.sort-desc .sort-icon { opacity:1; color:#1d70b8; }
.trend-bar { flex:1; background:#1d70b8; border-radius:2px 2px 0 0; min-height:3px; }
.trend-bar--dim { background:#cce2d8; }
.split-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
@media(max-width:640px) { .split-grid,.cat-card__grid { grid-template-columns:1fr; } .bar-row__label { width:140px; } .bar-row__val,.bar-row__sub { width:50px; } }
@media(max-width:400px) { .bar-row__label { width:90px; } .bar-row__sub { display:none; } }
</style>

<!-- Section nav -->
<nav class="tech-nav">
  <a href="?section=overview" class="<?= $section==='overview'?'active':'' ?>">Overview</a>
  <a href="?section=suppliers" class="<?= $section==='suppliers'?'active':'' ?>">A — Supplier market share</a>
  <a href="?section=buyers" class="<?= $section==='buyers'?'active':'' ?>">B — Buyer benchmarking</a>
  <a href="?section=categories" class="<?= $section==='categories'?'active':'' ?>">C — Category breakdown</a>
  <a href="/contracts/tech_tenders.php" class="<?= basename($_SERVER['PHP_SELF'])==='tech_tenders.php'?'active':'' ?>">Active tenders</a>
  <a href="/contracts/contracts.php" class="<?= basename($_SERVER['PHP_SELF'])==='contracts.php'?'active':'' ?>">All contracts</a>
</nav>




<?php if ($section === 'overview'): ?>
<!-- ═══════════════════════════════════════════════════════ OVERVIEW ═══ -->

<div class="govuk-grid-row govuk-!-margin-bottom-6">
  <?php foreach ([
    [number_format((int)$overview['total']),   'Tech contracts',          'CPV 30, 32, 48, 64, 72, 73'],
    [fmt_value((float)$overview['tv']),         'Total stated value',       number_format((int)$overview['with_value']) . ' contracts have a value'],
    [(int)$overview['active_n'],               'Active tenders',           'Live tech opportunities now'],
    [(int)$overview['buyers'],                 'Buying organisations',     'Councils with tech contracts'],
  ] as [$n,$label,$sub]): ?>
  <div class="govuk-grid-column-one-quarter govuk-!-margin-bottom-4">
    <div class="ct-stat-card">
      <div class="ct-stat-card__number"><?= is_int($n) ? number_format($n) : $n ?></div>
      <div class="ct-stat-card__label"><?= $label ?></div>
      <div class="ct-stat-card__sub"><?= $sub ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<h2 class="govuk-heading-m">Spend by tech category</h2>
<?php
$max_cat_tv = $cat_summary ? max(array_column($cat_summary, 'tv')) : 1;
$colors = ['#1d70b8','#003078','#4c2c92','#d4351c','#f47738','#00703c'];
$ci = 0;
?>
<div style="font-size:0.8125rem;margin-bottom:24px">
  <?php foreach ($cat_summary as $row):
    $pct = $max_cat_tv > 0 ? max(1, round($row['tv']/$max_cat_tv*100)) : 1;
    $col = $colors[$ci++ % count($colors)];
  ?>
  <div style="margin-bottom:10px">
    <div style="display:flex;justify-content:space-between;margin-bottom:3px">
      <a href="?section=categories" class="govuk-link" style="font-weight:500">
        <?= htmlspecialchars(TECH_CPV_LABELS[$row['cpv_division']] ?? 'CPV '.$row['cpv_division']) ?>
      </a>
      <span style="color:#505a5f"><?= fmt_value($row['tv']) ?> · <?= number_format($row['notices']) ?> notices<?= $row['active_n'] ? ' · <span style="color:#005a30">'.$row['active_n'].' active</span>' : '' ?></span>
    </div>
    <div style="background:#f3f2f1;height:12px;border-radius:4px;overflow:hidden">
      <div style="width:<?= $pct ?>%;height:100%;background:<?= $col ?>;border-radius:4px;transition:width .3s"></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="split-grid govuk-!-margin-top-6">
  <!-- Top suppliers -->
  <div>
    <h2 class="govuk-heading-m">Top suppliers (by notices)</h2>
    <table class="govuk-table sortable-table" style="font-size:0.875rem">
      <thead class="govuk-table__head">
        <tr class="govuk-table__row">
          <th class="govuk-table__header sortable" data-col="0" data-type="str">Vendor <span class="sort-icon">⇅</span></th>
          <th class="govuk-table__header govuk-table__header--numeric sortable sort-desc" data-col="1" data-type="num">Notices <span class="sort-icon">↓</span></th>
          <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="2" data-type="num">Awarded value <span class="sort-icon">⇅</span></th>
        </tr>
      </thead>
      <tbody class="govuk-table__body">
        <?php foreach (array_slice($top_suppliers_notices, 0, 15) as $row): ?>
        <tr class="govuk-table__row">
          <td class="govuk-table__cell"><?= htmlspecialchars($row['vendor_name']) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= $row['notices'] ?>"><?= number_format($row['notices']) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= (int)$row['tv'] ?>"><?= $row['tv'] > 0 ? fmt_value($row['tv']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="govuk-body-s govuk-!-colour-secondary govuk-!-margin-top-2">
      <a href="?section=suppliers" class="govuk-link">Full supplier analysis →</a>
    </p>
  </div>

  <!-- Top buyers -->
  <div>
    <h2 class="govuk-heading-m">Top tech buyers (by value)</h2>
    <table class="govuk-table sortable-table" style="font-size:0.875rem">
      <thead class="govuk-table__head">
        <tr class="govuk-table__row">
          <th class="govuk-table__header sortable" data-col="0" data-type="str">Council <span class="sort-icon">⇅</span></th>
          <th class="govuk-table__header govuk-table__header--numeric sortable sort-desc" data-col="1" data-type="num">Value <span class="sort-icon">↓</span></th>
          <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="2" data-type="num">Notices <span class="sort-icon">⇅</span></th>
        </tr>
      </thead>
      <tbody class="govuk-table__body">
        <?php foreach (array_slice($top_buyers, 0, 15) as $row): ?>
        <tr class="govuk-table__row">
          <td class="govuk-table__cell"><?= htmlspecialchars(mb_strimwidth($row['buyer_name'], 0, 35, '…')) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= (int)$row['tv'] ?>"><?= fmt_value($row['tv']) ?></td>
          <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= $row['notices'] ?>"><?= number_format($row['notices']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="govuk-body-s govuk-!-colour-secondary govuk-!-margin-top-2">
      <a href="?section=buyers" class="govuk-link">Full buyer benchmarking →</a>
    </p>
  </div>
</div>

<!-- Active tenders strip -->
<div style="margin-top:24px;border:1px solid #b1b4b6;padding:20px">
  <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px">
    <h2 class="govuk-heading-m govuk-!-margin-bottom-0">Latest active tech tenders</h2>
    <a href="/contracts/tech_tenders.php" class="govuk-link" style="font-size:0.875rem">View all active tenders →</a>
  </div>
  <?php
  $active = $pdo->query("
      SELECT title, buyer_name, value_amount, published_date, tender_end_date, official_url, cpv_division
      FROM ct_contracts
      WHERE cpv_division IN ({$cpv_in}) AND status = 'active'
      ORDER BY published_date DESC
      LIMIT 8
  ")->fetchAll();
  ?>
  <table class="govuk-table govuk-!-margin-bottom-0" style="font-size:0.875rem">
    <thead class="govuk-table__head">
      <tr class="govuk-table__row">
        <th class="govuk-table__header">Title</th>
        <th class="govuk-table__header">Buyer</th>
        <th class="govuk-table__header">Category</th>
        <th class="govuk-table__header govuk-table__header--numeric">Value</th>
        <th class="govuk-table__header">Published</th>
        <th class="govuk-table__header">Deadline</th>
      </tr>
    </thead>
    <tbody class="govuk-table__body">
      <?php foreach ($active as $r): ?>
      <tr class="govuk-table__row">
        <td class="govuk-table__cell" style="max-width:250px">
          <?php if ($r['official_url']): ?>
            <a href="<?= htmlspecialchars($r['official_url']) ?>" class="govuk-link" target="_blank" rel="noopener">
              <?= htmlspecialchars(mb_strimwidth($r['title'] ?? '—', 0, 60, '…')) ?>
            </a>
          <?php else: ?>
            <?= htmlspecialchars(mb_strimwidth($r['title'] ?? '—', 0, 60, '…')) ?>
          <?php endif; ?>
        </td>
        <td class="govuk-table__cell"><?= htmlspecialchars(mb_strimwidth($r['buyer_name'] ?? '', 0, 30, '…')) ?></td>
        <td class="govuk-table__cell" style="white-space:nowrap"><?= htmlspecialchars(TECH_CPV_LABELS[$r['cpv_division']] ?? '') ?></td>
        <td class="govuk-table__cell govuk-table__cell--numeric"><?= fmt_value($r['value_amount']) ?></td>
        <td class="govuk-table__cell" style="white-space:nowrap"><?= htmlspecialchars($r['published_date'] ?? '—') ?></td>
        <td class="govuk-table__cell" style="white-space:nowrap">
          <?php if ($r['tender_end_date']): ?>
            <?php
            $days = (int)ceil((strtotime($r['tender_end_date']) - time()) / 86400);
            $col  = $days < 7 ? '#d4351c' : ($days < 21 ? '#f47738' : '#0b0c0c');
            ?>
            <span style="color:<?= $col ?>"><?= htmlspecialchars($r['tender_end_date']) ?></span>
            <span style="color:#6f777b;font-size:0.8rem">(<?= $days ?>d)</span>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php elseif ($section === 'suppliers'): ?>
<!-- ════════════════════════════════════════════════ A: SUPPLIER MARKET SHARE ═══ -->

<p class="govuk-body govuk-!-colour-secondary">
  Supplier presence in tech contracts (CPV 30, 32, 48, 64, 72, 73) across <?= number_format((int)$overview['total']) ?> contracts.
  Based on supplier names recorded in award notices. Notice counts and awarded values from the public procurement index (BigQuery), normalised across name variants. "Awarded value" is the value stated in the award notice — not actual spend. Framework call-offs are frequently unpublished so notice counts understate true market share. Data refreshed June 2026.
</p>

<?php
// Merge notice + value lists into one deduplicated set
$all_suppliers_map = [];
foreach ($top_suppliers_notices as $r) {
    $all_suppliers_map[$r['vendor_name']] = [
        'vendor_name' => $r['vendor_name'],
        'notices'     => (int)$r['notices'],
        'tv'          => (float)$r['tv'],
        'active_n'    => (int)$r['active_n'],
    ];
}
foreach ($top_suppliers_value as $r) {
    if (!isset($all_suppliers_map[$r['vendor_name']])) {
        $all_suppliers_map[$r['vendor_name']] = [
            'vendor_name' => $r['vendor_name'],
            'notices'     => (int)$r['notices'],
            'tv'          => (float)$r['tv'],
            'active_n'    => 0,
        ];
    } else {
        // update tv from the value-sorted query (may include more records)
        $all_suppliers_map[$r['vendor_name']]['tv'] = (float)$r['tv'];
    }
}
$all_suppliers = array_values($all_suppliers_map);
?>

<table class="govuk-table sortable-table" id="suppliers-table" style="font-size:0.875rem">
  <thead class="govuk-table__head">
    <tr class="govuk-table__row">
      <th class="govuk-table__header sortable" data-col="0" data-type="str">Vendor <span class="sort-icon">⇅</span></th>
      <th class="govuk-table__header govuk-table__header--numeric sortable sort-desc" data-col="1" data-type="num">Notices <span class="sort-icon">↓</span></th>
      <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="2" data-type="num">Awarded value <span class="sort-icon">⇅</span></th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
    <?php foreach ($all_suppliers as $row): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><?= htmlspecialchars($row['vendor_name']) ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= $row['notices'] ?>"><?= number_format($row['notices']) ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= (int)$row['tv'] ?>"><?= $row['tv'] > 0 ? fmt_value($row['tv']) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- Supplier CPV profile table -->
<h2 class="govuk-heading-m">Supplier tech category profile</h2>
<p class="govuk-body-s govuk-!-colour-secondary">Which tech categories each supplier operates in, based on contract CPV codes.</p>
<table class="govuk-table" style="font-size:0.875rem">
  <thead class="govuk-table__head">
    <tr class="govuk-table__row">
      <th class="govuk-table__header">Vendor</th>
      <?php foreach (TECH_CPV_LABELS as $cpv => $label): ?>
        <th class="govuk-table__header govuk-table__header--numeric" title="<?= $label ?>">CPV <?= $cpv ?></th>
      <?php endforeach; ?>
      <th class="govuk-table__header govuk-table__header--numeric">Total</th>
      <th class="govuk-table__header govuk-table__header--numeric">Value</th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
    <?php foreach ($top_suppliers_notices as $row):
      $cpv_map = [];
      foreach ($vendor_cpv[$row['vendor_name']] ?? [] as $cr) {
          $cpv_map[$cr['cpv_division']] = $cr['n'];
      }
    ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><?= htmlspecialchars(mb_strimwidth($row['vendor_name'], 0, 35, '…')) ?></td>
      <?php foreach (array_keys(TECH_CPV_LABELS) as $cpv): ?>
        <td class="govuk-table__cell govuk-table__cell--numeric">
          <?php if (isset($cpv_map[$cpv])): ?>
            <span style="background:#1d70b8;color:#fff;padding:1px 6px;border-radius:3px;font-size:0.8rem"><?= $cpv_map[$cpv] ?></span>
          <?php else: ?>
            <span style="color:#b1b4b6">—</span>
          <?php endif; ?>
        </td>
      <?php endforeach; ?>
      <td class="govuk-table__cell govuk-table__cell--numeric"><strong><?= number_format($row['notices']) ?></strong></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= fmt_value($row['tv']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php elseif ($section === 'buyers'): ?>
<!-- ════════════════════════════════════════════════ B: BUYER BENCHMARKING ═══ -->

<p class="govuk-body govuk-!-colour-secondary">
  How councils compare on tech procurement activity. Ranked by total stated contract value.
  Note: many contracts don't include a value, so notice count is often a better indicator of activity.
</p>

<?php
// Merge both buyer lists into one deduplicated set keyed by buyer_name
$all_buyers_map = [];
foreach ($top_buyers as $r) {
    $all_buyers_map[$r['buyer_name']] = [
        'buyer_name' => $r['buyer_name'],
        'tv'         => $r['tv'],
        'notices'    => $r['notices'],
        'active_n'   => $r['active_n'],
        'cpv_variety'=> $r['cpv_variety'],
    ];
}
foreach ($top_buyers_by_notices as $r) {
    if (!isset($all_buyers_map[$r['buyer_name']])) {
        $all_buyers_map[$r['buyer_name']] = [
            'buyer_name' => $r['buyer_name'],
            'tv'         => $r['tv'],
            'notices'    => $r['notices'],
            'active_n'   => 0,
            'cpv_variety'=> 0,
        ];
    }
}
$all_buyers = array_values($all_buyers_map);
?>

<table class="govuk-table sortable-table" id="buyers-table" style="font-size:0.875rem">
  <thead class="govuk-table__head">
    <tr class="govuk-table__row">
      <th class="govuk-table__header sortable" data-col="0" data-type="str">Council <span class="sort-icon">⇅</span></th>
      <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="1" data-type="num">Notices <span class="sort-icon">⇅</span></th>
      <th class="govuk-table__header govuk-table__header--numeric sortable sort-desc" data-col="2" data-type="num">Value <span class="sort-icon">↓</span></th>
      <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="3" data-type="num">Active <span class="sort-icon">⇅</span></th>
      <th class="govuk-table__header govuk-table__header--numeric sortable" data-col="4" data-type="num">CPV categories <span class="sort-icon">⇅</span></th>
      <th class="govuk-table__header">Notices</th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
    <?php foreach ($all_buyers as $row): ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell"><?= htmlspecialchars($row['buyer_name']) ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= $row['notices'] ?>"><?= number_format($row['notices']) ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= (int)$row['tv'] ?>"><?= fmt_value($row['tv']) ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= $row['active_n'] ?>">
        <?= $row['active_n'] > 0 ? '<span class="ct-tag ct-tag--active">' . $row['active_n'] . '</span>' : '—' ?>
      </td>
      <td class="govuk-table__cell govuk-table__cell--numeric" data-val="<?= $row['cpv_variety'] ?>"><?= $row['cpv_variety'] ?></td>
      <td class="govuk-table__cell"><a href="contracts.php?buyer=<?= urlencode($row['buyer_name']) ?>" class="govuk-link">View notices</a></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- Buyer detail table -->
<h2 class="govuk-heading-m">Buyer tech category profile</h2>
<p class="govuk-body-s govuk-!-colour-secondary">Which tech categories each council buys in, based on CPV codes. Shows breadth of tech procurement activity.</p>
<div style="overflow-x:auto">
<table class="govuk-table" style="font-size:0.875rem">
  <thead class="govuk-table__head">
    <tr class="govuk-table__row">
      <th class="govuk-table__header">Council</th>
      <?php foreach (TECH_CPV_LABELS as $cpv => $label): ?>
        <th class="govuk-table__header govuk-table__header--numeric" title="<?= $label ?>">CPV <?= $cpv ?></th>
      <?php endforeach; ?>
      <th class="govuk-table__header govuk-table__header--numeric">Notices</th>
      <th class="govuk-table__header govuk-table__header--numeric">Value</th>
      <th class="govuk-table__header govuk-table__header--numeric">Active</th>
      <th class="govuk-table__header" style="white-space:nowrap">Last notice</th>
    </tr>
  </thead>
  <tbody class="govuk-table__body">
    <?php foreach ($top_buyers as $row):
      $cpv_map = [];
      foreach ($buyer_cpv[$row['buyer_name']] ?? [] as $cr) {
          $cpv_map[$cr['cpv_division']] = $cr['n'];
      }
    ?>
    <tr class="govuk-table__row">
      <td class="govuk-table__cell" style="white-space:nowrap">
        <?= htmlspecialchars(mb_strimwidth($row['buyer_name'], 0, 35, '…')) ?>
      </td>
      <?php foreach (array_keys(TECH_CPV_LABELS) as $cpv): ?>
        <td class="govuk-table__cell govuk-table__cell--numeric">
          <?php if (isset($cpv_map[$cpv])): ?>
            <span style="background:#e8f1fb;color:#1d70b8;padding:1px 6px;border-radius:3px;font-size:0.8rem"><?= $cpv_map[$cpv] ?></span>
          <?php else: ?>
            <span style="color:#b1b4b6">—</span>
          <?php endif; ?>
        </td>
      <?php endforeach; ?>
      <td class="govuk-table__cell govuk-table__cell--numeric"><strong><?= number_format($row['notices']) ?></strong></td>
      <td class="govuk-table__cell govuk-table__cell--numeric"><?= fmt_value($row['tv']) ?></td>
      <td class="govuk-table__cell govuk-table__cell--numeric">
        <?php if ($row['active_n'] > 0): ?>
          <span class="ct-tag ct-tag--active"><?= $row['active_n'] ?></span>
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php elseif ($section === 'categories'): ?>
<!-- ════════════════════════════════════════════════ C: CATEGORY BREAKDOWN ═══ -->

<p class="govuk-body govuk-!-colour-secondary">
  Tech procurement broken down by CPV category — volume, value and top suppliers per category. Supplier counts reflect named award notices only; framework call-offs (G-Cloud, CCS agreements) are frequently unpublished so true volumes are significantly higher.
</p>

<?php foreach ($cat_summary as $cat):
  $cpv   = $cat['cpv_division'];
  $label = TECH_CPV_LABELS[$cpv] ?? "CPV {$cpv}";
  $suppliers = $cat_suppliers[$cpv] ?? [];
  $recent    = $cat_recent[$cpv]    ?? [];
  $max_s     = $suppliers ? (int)$suppliers[0]['n'] : 1;
  // Trend bars: 2024, 2025, 2026 (partial)
  $t_max = max($cat['n_2024'], $cat['n_2025'], $cat['n_2026'], 1);
?>
<div class="cat-card">
  <div class="cat-card__header">
    <div>
      <div class="cat-card__title">CPV <?= $cpv ?> — <?= htmlspecialchars($label) ?></div>
      <div style="font-size:0.875rem;color:#505a5f;margin-top:3px">
        <?= number_format($cat['notices']) ?> contracts ·
        <?= fmt_value($cat['tv']) ?> stated value ·
        <?= number_format($cat['active_n']) ?> active
      </div>
    </div>

  </div>
  <div class="cat-card__body">
    <div class="cat-card__grid">
      <!-- Top suppliers -->
      <div>
        <h3 class="govuk-heading-s govuk-!-margin-bottom-2">Top suppliers <span style="font-size:0.75rem;color:#6f777b;font-weight:400">(by notices in local DB)</span></h3>
        <?php if ($suppliers): ?>
          <?php foreach ($suppliers as $s):
            $pct = round($s['n']/$max_s*100);
          ?>
          <div class="bar-row" style="margin-bottom:5px">
            <span class="bar-row__label" title="<?= htmlspecialchars($s['vendor_name']) ?>"><?= htmlspecialchars(mb_strimwidth($s['vendor_name'], 0, 25, '…')) ?></span>
            <span class="bar-row__track"><span class="bar-row__fill" style="width:<?= $pct ?>%"></span></span>
            <span class="bar-row__val"><?= $s['n'] ?></span>
            <span class="bar-row__sub"><?= fmt_value($s['tv']) ?></span>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="govuk-body-s govuk-!-colour-secondary">No tracked vendor matches in this category.</p>
        <?php endif; ?>
      </div>
      <!-- Active tenders -->
      <div>
        <h3 class="govuk-heading-s govuk-!-margin-bottom-2">Active tenders</h3>
        <?php if ($recent): ?>
          <?php foreach ($recent as $r): ?>
          <div style="font-size:0.8rem;border-bottom:1px solid #f3f2f1;padding:5px 0">
            <?php if ($r['official_url']): ?>
              <a href="<?= htmlspecialchars($r['official_url']) ?>" class="govuk-link" target="_blank" rel="noopener">
                <?= htmlspecialchars(mb_strimwidth($r['title'] ?? '—', 0, 55, '…')) ?>
              </a>
            <?php else: ?>
              <?= htmlspecialchars(mb_strimwidth($r['title'] ?? '—', 0, 55, '…')) ?>
            <?php endif; ?>
            <div style="color:#6f777b;margin-top:1px">
              <?= htmlspecialchars(mb_strimwidth($r['buyer_name'] ?? '', 0, 35, '…')) ?>
              <?= $r['value_amount'] ? ' · ' . fmt_value($r['value_amount']) : '' ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="govuk-body-s govuk-!-colour-secondary">No active tenders in this category.</p>
        <?php endif; ?>
        <p style="margin-top:6px">
          <a href="/contracts/contracts.php?status=active&cpv=<?= $cpv ?>" class="govuk-link" style="font-size:0.875rem">All active →</a>
        </p>
      </div>
    </div>

    <!-- Value stats -->
    <div style="margin-top:15px;padding-top:15px;border-top:1px solid #f3f2f1;display:flex;gap:24px;flex-wrap:wrap;font-size:0.875rem">
      <div><span style="color:#505a5f">Avg contract value: </span><strong><?= fmt_value($cat['avg_value']) ?></strong></div>
      <div><span style="color:#505a5f">% with stated value: </span><strong><?= $cat['notices']>0 ? round($cat['with_value']/$cat['notices']*100) : 0 ?>%</strong></div>
      <div><span style="color:#505a5f">Awarded: </span><strong><?= number_format($cat['awarded_n']) ?></strong></div>
      <div><span style="color:#505a5f">2024: </span><strong><?= $cat['n_2024'] ?></strong> · <span style="color:#505a5f">2025: </span><strong><?= $cat['n_2025'] ?></strong> · <span style="color:#505a5f">2026: </span><strong><?= $cat['n_2026'] ?></strong></div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<script>
document.querySelectorAll('.sortable-table').forEach(function(table) {
  var tbody = table.querySelector('tbody');
  var headers = table.querySelectorAll('th.sortable');
  var sortCol = -1, sortAsc = true;

  // Find initially sorted column
  headers.forEach(function(th, i) {
    if (th.classList.contains('sort-desc')) { sortCol = i; sortAsc = false; }
    if (th.classList.contains('sort-asc'))  { sortCol = i; sortAsc = true;  }
  });

  headers.forEach(function(th, colIdx) {
    th.style.cursor = 'pointer';
    th.style.userSelect = 'none';
    th.addEventListener('click', function() {
      if (sortCol === colIdx) {
        sortAsc = !sortAsc;
      } else {
        sortCol = colIdx;
        sortAsc = th.dataset.type === 'str'; // strings default asc, nums default desc
      }

      // Update icons
      headers.forEach(function(h) {
        h.classList.remove('sort-asc','sort-desc');
        h.querySelector('.sort-icon').textContent = '⇅';
      });
      th.classList.add(sortAsc ? 'sort-asc' : 'sort-desc');
      th.querySelector('.sort-icon').textContent = sortAsc ? '↑' : '↓';

      // Sort rows
      var rows = Array.from(tbody.querySelectorAll('tr'));
      rows.sort(function(a, b) {
        var aCell = a.querySelectorAll('td')[colIdx];
        var bCell = b.querySelectorAll('td')[colIdx];
        var aVal = aCell.dataset.val !== undefined ? parseFloat(aCell.dataset.val) : aCell.textContent.trim();
        var bVal = bCell.dataset.val !== undefined ? parseFloat(bCell.dataset.val) : bCell.textContent.trim();
        if (typeof aVal === 'string') {
          return sortAsc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
        }
        return sortAsc ? aVal - bVal : bVal - aVal;
      });
      rows.forEach(function(r) { tbody.appendChild(r); });
    });
  });
});
</script>

<?php layout_foot(); ?>
