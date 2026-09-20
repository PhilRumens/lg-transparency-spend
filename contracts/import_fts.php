<?php
/**
 * import_fts.php
 * One-off script to import FTS contract data from BigQuery JSON export
 * into ct_contracts, then run LGAM classification and vendor matching.
 *
 * Usage: visit https://<your-host>/contracts/import_fts.php
 * in a browser (must be logged in).
 *
 * The JSON file (fts_import_data.json) must be uploaded to the same
 * contracts/ directory before running this script.
 */

declare(strict_types=1);
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';
require_login();

// Stream output
while (ob_get_level()) ob_end_clean();
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
echo '<html><head><style>body{font-family:monospace;font-size:13px;background:#1a1a1a;color:#d4d4d4;padding:1rem;white-space:pre-wrap;}.ok{color:#4ec9b0}.err{color:#f48771}.info{color:#9cdcfe}</style></head><body>';
echo str_pad('', 1024) . "\n";
flush();

function out(string $msg, string $class = 'info'): void {
    echo '<span class="' . $class . '">' . htmlspecialchars('[' . date('H:i:s') . '] ' . $msg) . '</span>' . "\n";
    ob_flush(); flush();
}

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// Load JSON file
$jsonFile = __DIR__ . '/fts_import_data.json';
if (!file_exists($jsonFile)) {
    out("ERROR: fts_import_data.json not found in contracts/ directory", 'err');
    out("Please upload the file first.", 'err');
    exit;
}

out("Loading fts_import_data.json...");
$records = json_decode(file_get_contents($jsonFile), true);
if (!$records) {
    out("ERROR: Failed to parse JSON", 'err');
    exit;
}
out("Loaded " . count($records) . " records", 'ok');

// Prepare upsert
$stmt = $pdo->prepare("
    INSERT INTO ct_contracts
        (ocid,title,buyer_name,status,stage,value_amount,value_currency,
         cpv_division,cpv_codes,region,published_date,tender_end_date,
         official_url,source,supplier_names,description,fetched_at)
    VALUES
        (:ocid,:title,:buyer_name,:status,:stage,:value_amount,:value_currency,
         :cpv_division,:cpv_codes,:region,:published_date,:tender_end_date,
         :official_url,'fts',:supplier_names,:description,NOW())
    ON DUPLICATE KEY UPDATE
        title           = :title2,
        status          = :status2,
        stage           = :stage2,
        value_amount    = COALESCE(:value_amount2, value_amount),
        cpv_division    = COALESCE(:cpv_division2, cpv_division),
        cpv_codes       = COALESCE(:cpv_codes2, cpv_codes),
        region          = COALESCE(:region2, region),
        tender_end_date = COALESCE(:tender_end_date2, tender_end_date),
        official_url    = COALESCE(:official_url2, official_url),
        supplier_names  = COALESCE(:supplier_names2, supplier_names),
        description     = COALESCE(:description2, description),
        fetched_at      = NOW()
");

function sanitise(?string $s): ?string {
    if ($s === null) return null;
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = preg_replace('/[\xF0-\xF7][\x80-\xBF]{3}/', '', $s);
    return $s;
}

$inserted = 0;
$errors   = 0;

foreach ($records as $r) {
    $ocid    = $r['ocid'] ?? null;
    if (!$ocid) continue;

    $title   = sanitise(substr($r['title'] ?? '', 0, 500));
    $buyer   = sanitise($r['buyer_name'] ?? '');
    $status  = $r['status'] ?? 'complete';
    $stage   = $r['stage']  ?? 'award';
    $value   = isset($r['value_amount']) && $r['value_amount'] !== null ? (float)$r['value_amount'] : null;
    $curr    = $r['value_currency'] ?? 'GBP';
    $cpvDiv  = $r['cpv_division'] ?? null;
    $cpvFull = sanitise($r['cpv_codes'] ?? null);
    $region  = sanitise($r['region'] ?? null);
    $pub     = $r['published_date'] ?? null;
    $end     = $r['tender_end_date'] ?? null;
    $url     = $r['official_url'] ?? null;
    $supp    = sanitise($r['supplier_names'] ?? null);
    $desc    = sanitise(substr($r['description'] ?? '', 0, 1000));

    try {
        $stmt->execute([
            ':ocid'             => $ocid,
            ':title'            => $title,
            ':title2'           => $title,
            ':buyer_name'       => $buyer,
            ':status'           => $status,
            ':status2'          => $status,
            ':stage'            => $stage,
            ':stage2'           => $stage,
            ':value_amount'     => $value,
            ':value_amount2'    => $value,
            ':value_currency'   => $curr,
            ':cpv_division'     => $cpvDiv,
            ':cpv_division2'    => $cpvDiv,
            ':cpv_codes'        => $cpvFull,
            ':cpv_codes2'       => $cpvFull,
            ':region'           => $region,
            ':region2'          => $region,
            ':published_date'   => $pub,
            ':tender_end_date'  => $end,
            ':tender_end_date2' => $end,
            ':official_url'     => $url,
            ':official_url2'    => $url,
            ':supplier_names'   => $supp,
            ':supplier_names2'  => $supp,
            ':description'      => $desc,
            ':description2'     => $desc,
        ]);
        $inserted++;
        if ($inserted % 500 === 0) {
            out("{$inserted} records imported...");
        }
    } catch (Throwable $e) {
        $errors++;
        if ($errors <= 5) {
            out("Error on {$ocid}: " . $e->getMessage(), 'err');
        }
    }
}

out("Import complete: {$inserted} upserted, {$errors} errors", 'ok');

// LGAM classification
out("Running LGAM classification...");
$classified = classifyAll($pdo);
out("Classified: {$classified} rows", 'ok');


out("Running vendor matching...");
$matched = matchVendors($pdo);
out("Vendor links: {$matched}", 'ok');

out("All done! You can now delete fts_import_data.json and import_fts.php from the server.", 'ok');

// ── LGAM + Vendor functions (copied from fetch_contracts.php) ─────────────

const TECH_CPVS = ['30','32','48','64','72','73'];

const LGAM_RULES = [
    ['public_channels','online',    'Online',             ['/\bwebsite\b/i','/web[\s-]portal/i','/digital[\s-]experience[\s-]platform/i','/online[\s-]form/i','/self[\s-]service[\s-]portal/i'], false],
    ['public_channels','messaging', 'Messaging / SMS',    ['/\bsms\b/i','/hybrid[\s-]mail/i','/gov.?delivery/i'], false],
    ['public_channels','phone',     'Phone / Telephony',  ['/\btelephony\b/i','/\bvoip\b/i','/telephone[\s-]system/i','/contact[\s-]centre[\s-]system/i'], false],
    ['capabilities','payments', 'Payments', ['/payment[\s-]platform/i','/payment[\s-]system/i','/bill[\s-]payment/i'], false],
    ['capabilities','forms',    'Forms',    ['/digital[\s-]form/i','/e.?form[\s-]platform/i','/citizen[\s-]form/i'], false],
    ['capabilities','booking',  'Booking',  ['/booking[\s-]system/i','/appointment[\s-]system/i','/diary[\s-]manag/i'], false],
    ['capabilities','identity', 'Identity', ['/identity[\s-]check/i','/identity[\s-]verif/i'], false],
    ['business_areas','adult_social_care',     'Adult Social Care',      ['/adult[\s-]social[\s-]care.*system/i','/care[\s-]manag.*system/i','/electronic[\s-]care[\s-]monitor/i'], true],
    ['business_areas','childrens_social_care', "Children's Social Care", ['/children.*social[\s-]care.*system/i','/safeguard.*system/i'], true],
    ['business_areas','democratic_services',   'Democratic Services',    ['/election.*(?:software|system)/i','/electoral.*(?:system|software)/i','/\bmod\.gov\b/i'], false],
    ['business_areas','education',             'Education',              ['/school.*manag.*system/i','/\bmis\b.*school/i','/admissions.*system/i','/education.*manag.*system/i'], true],
    ['business_areas','highways_transport',    'Highways & Transport',   ['/highway.*asset/i','/fleet[\s-]manag/i','/traffic[\s-]manag.*system/i','/parking.*(?:system|software|platform)/i'], false],
    ['business_areas','housing',               'Housing',                ['/housing.*(?:system|software)/i','/homelessness.*system/i','/repairs.*manag.*system/i','/tenant.*portal/i'], true],
    ['business_areas','leisure_culture',       'Leisure & Culture',      ['/library.*(?:system|rfid|manag)/i','/rfid.*library/i','/leisure.*(?:system|software)/i'], false],
    ['business_areas','licensing_regulation',  'Licensing & Regulation', ['/licensing.*(?:system|software|platform)/i'], false],
    ['business_areas','planning_development',  'Planning & Development', ['/planning.*(?:software|system|applic)/i','/\bidox\b/i','/building[\s-]control.*system/i'], false],
    ['business_areas','revenues_benefits',     'Revenues & Benefits',    ['/revenues.*benefit.*(?:system|software)/i','/council[\s-]tax.*(?:system|software)/i'], true],
    ['business_areas','waste_management',      'Waste Management',       ['/waste.*(?:manag|it).*system/i','/waste.*software/i'], false],
    ['corporate_areas','crm',          'Customer Relationship (B2C)', ['/\bcrm\b/i','/customer.*manag.*system/i'], false],
    ['corporate_areas','financial',    'Financial',                   ['/finance.*(?:system|software)/i','/\berp\b.*(?:system|implement)/i','/oracle.*(?:financ|payroll)/i'], false],
    ['corporate_areas','geographical', 'Geographical',                ['/\bgis\b/i','/geograph.*inform.*system/i','/\besri\b/i','/gazetteer/i'], false],
    ['corporate_areas','hr_workforce', 'HR & Workforce',              ['/\bpayroll\b.*(?:system|software)/i','/hr.*(?:system|software)/i','/learning[\s-]manag.*system/i','/\blms\b/i'], false],
    ['foundational_ai','gen_ai',          'Generative AI',         ['/generative[\s-]?ai/i','/microsoft.*copilot/i','/\bllm\b/i'], false],
    ['foundational_ai','intelligent_auto','Intelligent Automation',['/robotic[\s-]process[\s-]autom/i','/\brpa\b/i','/intelligent[\s-]autom/i'], false],
    ['foundational_enduser','unified_comms',   'Unified Communications',['/microsoft[\s-]enterprise[\s-]agreement/i','/\bm365\b/i','/microsoft[\s-]365/i','/office[\s-]365/i'], false],
    ['foundational_enduser','end_user_devices','End User Devices',      ['/\blaptop/i','/end[\s-]user[\s-]device/i','/hardware[\s-]refresh/i'], false],
    ['foundational_svc','service_desk','IT Service Desk',          ['/service[\s-]desk/i','/\bhelpdesk\b/i','/\bitsm\b/i'], false],
    ['foundational_infra','compute_storage','Compute & Storage',   ['/server.*storage/i','/data[\s-]?centre/i','/cloud.*(?:infrastructure|hosting|backup)/i','/backup[\s-]solution/i'], false],
    ['foundational_infra','connectivity', 'Connectivity & Network',['/wide[\s-]area[\s-]network/i','/\bwan\b/i','/\bwifi\b/i','/network[\s-](?:infrastructure|refresh)/i'], false],
    ['security','vuln_threat',      'Vulnerability & Threat Mgmt',['/vulnerabilit.*scan/i','/penetration[\s-]test/i'], false],
    ['security','network_endpoint', 'Network & Endpoint Security', ['/\bfirewall/i','/endpoint[\s-](?:security|protect)/i','/managed[\s-]detect/i'], false],
    ['security','physical_security','Physical Security',           ['/\bcctv\b/i','/body[\s-]worn.*camera/i'], false],
    ['data_info','bi_analytics','BI & Analytics',               ['/business[\s-]intelligence.*platform/i','/analytics.*platform/i','/reporting.*platform/i'], false],
    ['data_info','doc_records',  'Document & Records Management',['/document[\s-]manag.*system/i','/records[\s-]manag.*system/i','/\bedms\b/i'], false],
];

const VENDOR_PATTERNS = [
    'Phoenix Software Ltd'              => ['/phoenix[\s-]software/i'],
    'Bytes Technology Group Ltd'        => ['/bytes[\s-]technology/i'],
    'Insight Enterprises UK Limited'    => ['/insight[\s-]enterprises/i','/insight[\s-]direct/i'],
    'CDW Limited'                       => ['/\bcdw\b/i'],
    'Softcat Limited'                   => ['/\bsoftcat\b/i'],
    'Specialist Computer Centres (SCC) PLC' => ['/specialist[\s-]computer[\s-]cent/i'],
    'Trustmarque Solutions (Capita)'    => ['/trustmarque/i'],
    'Computacenter (UK) Ltd'            => ['/computacenter/i'],
    'British Telecom (BT Group)'        => ['/british[\s-]telecom/i','/\bbt[\s-]group\b/i'],
    'Virgin Media Business'             => ['/virgin[\s-]media/i'],
    'Vodafone'                          => ['/\bvodafone\b/i'],
    'Daisy Group Holdings'              => ['/daisy[\s-](?:group|communications)/i'],
    'Capita Group'                      => ['/\bcapita\b(?!l)/i'],
    'Civica'                            => ['/\bcivica\b/i'],
    'NEC Software Solutions'            => ['/nec[\s-]software/i'],
    'Agilisys'                          => ['/\bagilisys\b/i'],
    'The Access Group Ltd'              => ['/\bthe[\s-]access[\s-]group\b/i'],
    'Serco Group'                       => ['/\bserco\b/i'],
    'Oracle Corporation (software)'     => ['/\boracle\b/i'],
    'Idox Plc'                          => ['/\bidox\b/i'],
    'Dell Corporation Limited'          => ['/\bdell\b/i'],
    'Liquidlogic (part of Xyster)'      => ['/\bliquidlogic\b/i'],
    'Unit4 Business Software'           => ['/\bunit[\s-]?4\b/i'],
    'SAP (UK) Limited'                  => ['/\bsap\b/i'],
    'Granicus-Firmstep Ltd'             => ['/\bgranicus\b/i','/\bfirmstep\b/i'],
    'Microsoft'                         => ['/\bmicrosoft\b/i'],
    'Esri UK Ltd'                       => ['/\besri\b/i'],
];

function classifyAll(PDO $pdo): int {
    $stmt   = $pdo->query("SELECT ocid,title,description,cpv_division FROM ct_contracts WHERE lgam_layer IS NULL");
    $update = $pdo->prepare("UPDATE ct_contracts SET lgam_layer=:l,lgam_sublayer=:s,lgam_sublayer_name=:n WHERE ocid=:o");
    $count  = 0;
    while ($row = $stmt->fetch()) {
        $text = ($row['title'] ?? '') . ' ' . ($row['description'] ?? '');
        [$l, $s, $n] = classifyLgam($text, $row['cpv_division'] ?? '');
        if ($l) {
            $update->execute([':l' => $l, ':s' => $s, ':n' => $n, ':o' => $row['ocid']]);
            $count++;
        }
    }
    return $count;
}

function classifyLgam(string $text, string $cpv): array {
    foreach (LGAM_RULES as [$layer, $sub, $name, $patterns, $requireTech]) {
        if ($requireTech && !in_array($cpv, TECH_CPVS, true)) continue;
        foreach ($patterns as $pat) {
            if (preg_match($pat, $text)) return [$layer, $sub, $name];
        }
    }
    return [null, null, null];
}

function matchVendors(PDO $pdo): int {
    $rows   = $pdo->query("SELECT ocid,title,description FROM ct_contracts WHERE source='fts'")->fetchAll();
    $count  = 0;
    foreach ($rows as $row) {
        $text = ($row['title'] ?? '') . ' ' . ($row['description'] ?? '');
        foreach (VENDOR_PATTERNS as $vendor => $patterns) {
            foreach ($patterns as $pat) {
                if (preg_match($pat, $text)) {
                    $insert->execute([$vendor, $row['ocid']]);
                    $count++;
                    break;
                }
            }
        }
    }
    return $count;
}
