<?php
/**
 * refresh_fts.php
 * ─────────────────────────────────────────────────────────────────────────
 * Fetches new FTS contracts from the Council Tenders BigQuery API and
 * imports them into ct_contracts. Run this periodically (e.g. quarterly)
 * to keep FTS data up to date without relying on the broken FTS REST API.
 *
 * How it works:
 *   1. Finds the most recent FTS published_date already in your database.
 *   2. Queries BigQuery for all FTS LG contracts published after that date.
 *   3. Imports them using the same upsert logic as import_fts.php.
 *   4. Re-runs LGAM classification and vendor matching on new records only.
 *   5. Saves a timestamp to ct_sync_state so you can see when it last ran.
 *
 * Usage: visit https://<your-host>/contracts/refresh_fts.php
 * Must be logged in. Takes 1-3 minutes depending on how much is new.
 *
 * The Council Tenders API key is handled by the MCP proxy — no key needed
 * in this script. Calls go to https://tenders.run.cns.me/api/query
 */

declare(strict_types=1);
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';
require_login();

// Stream output to browser
while (ob_get_level()) ob_end_clean();
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
echo '<html><head><style>
  body { font-family: monospace; font-size: 13px; background: #1a1a1a; color: #d4d4d4; padding: 1.5rem; white-space: pre-wrap; }
  .ok   { color: #4ec9b0 }
  .err  { color: #f48771 }
  .info { color: #9cdcfe }
  .warn { color: #dcdcaa }
  a     { color: #9cdcfe }
</style></head><body>';
echo str_pad('', 1024) . "\n";
flush();

function out(string $msg, string $cls = 'info'): void {
    echo '<span class="' . $cls . '">[' . date('H:i:s') . '] ' . htmlspecialchars($msg) . '</span>' . "\n";
    ob_flush(); flush();
}

// ── Config ────────────────────────────────────────────────────────────────

// Council Tenders BigQuery API endpoint (public, no key required)
const CT_API = 'https://tenders.run.cns.me/api/sql';

// LG buyer patterns (must match fetch_contracts.php)
const LG_PATTERNS = ['council', 'combined authority', 'borough', 'county', 'metropolitan'];

// ── Connect ───────────────────────────────────────────────────────────────
$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// ── Find last FTS date in DB ──────────────────────────────────────────────
$last_fts = $pdo->query(
    "SELECT MAX(published_date) FROM ct_contracts WHERE source = 'fts'"
)->fetchColumn();

if ($last_fts) {
    $since = $last_fts; // YYYY-MM-DD
    out("Last FTS record in database: {$since}");
    out("Fetching records published after {$since}...");
} else {
    $since = '2024-01-01';
    out("No FTS records found — doing full backfill from {$since}");
}

// ── Build BigQuery SQL ────────────────────────────────────────────────────
$buyer_conditions = array_map(
    fn($p) => "LOWER(buyer_name) LIKE '%" . addslashes($p) . "%'",
    LG_PATTERNS
);
$buyer_sql = implode(' OR ', $buyer_conditions);

$sql = "
SELECT ocid, title, buyer_name, status, stage,
       CAST(value_amount AS FLOAT64) AS value_amount,
       value_currency, cpv_division,
       ARRAY_TO_STRING(cpv_codes, ',') AS cpv_codes,
       region,
       FORMAT_TIMESTAMP('%Y-%m-%d', published_date) AS published_date,
       FORMAT_TIMESTAMP('%Y-%m-%d', tender_end_date) AS tender_end_date,
       official_url,
       (SELECT STRING_AGG(a.supplier_name, ' | ')
        FROM UNNEST(awards) a
        WHERE a.supplier_name IS NOT NULL) AS supplier_names,
       LEFT(description, 1000) AS description
FROM \`govreposcrape.uk_tenders_public.compiled_process\`
WHERE source = 'fts'
  AND published_date > TIMESTAMP('{$since}')
  AND ({$buyer_sql})
ORDER BY published_date ASC
";

out("Querying BigQuery...");

// ── Call Council Tenders API ──────────────────────────────────────────────
function query_bigquery(string $sql): array {
    $ch = curl_init(CT_API);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['sql' => $sql]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_USERAGENT      => 'LGContractsDashboard/1.0',
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) throw new RuntimeException("curl failed");
    if ($code !== 200)   throw new RuntimeException("API returned HTTP {$code}: " . substr($resp, 0, 200));

    $data = json_decode($resp, true);
    if (!$data)          throw new RuntimeException("JSON decode failed: " . substr($resp, 0, 200));

    // Handle both direct rows array and wrapped response
    if (isset($data['rows'])) return $data['rows'];
    if (is_array($data))      return $data;
    throw new RuntimeException("Unexpected response format");
}

try {
    $records = query_bigquery($sql);
} catch (Throwable $e) {
    out("BigQuery query failed: " . $e->getMessage(), 'err');
    out("This may mean the API endpoint has changed. Check https://tenders.run.cns.me for docs.", 'warn');
    out("Alternatively, use the manual import route:", 'warn');
    out("  1. Ask Claude to fetch fresh BigQuery batches", 'warn');
    out("  2. Upload fts_import_data.json + import_fts.php", 'warn');
    out("  3. Visit /contracts/import_fts.php", 'warn');
    exit;
}

$count = count($records);
out("Received {$count} new FTS records from BigQuery", $count > 0 ? 'ok' : 'warn');

if ($count === 0) {
    out("Database is already up to date — nothing to import.", 'ok');
    $pdo->prepare("INSERT INTO ct_sync_state (k,v) VALUES ('fts_last_refresh',?) ON DUPLICATE KEY UPDATE v=?")
        ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    out("Done.");
    exit;
}

// ── Upsert records ────────────────────────────────────────────────────────
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
    // Handle both array and object
    $r = (array)$r;
    $ocid = $r['ocid'] ?? null;
    if (!$ocid) continue;

    $title  = sanitise(substr($r['title'] ?? '', 0, 500));
    $buyer  = sanitise($r['buyer_name'] ?? '');
    $status = $r['status'] ?? 'complete';
    $stage  = $r['stage']  ?? 'award';
    $value  = isset($r['value_amount']) && $r['value_amount'] !== null ? (float)$r['value_amount'] : null;
    $curr   = $r['value_currency'] ?? 'GBP';
    $cpvDiv = $r['cpv_division'] ?? null;
    $cpvFul = sanitise($r['cpv_codes'] ?? null);
    $region = sanitise($r['region'] ?? null);
    $pub    = $r['published_date'] ?? null;
    $end    = $r['tender_end_date'] ?? null;
    $url    = $r['official_url'] ?? null;
    $supp   = sanitise($r['supplier_names'] ?? null);
    $desc   = sanitise(substr($r['description'] ?? '', 0, 1000));

    try {
        $stmt->execute([
            ':ocid'             => $ocid,
            ':title'            => $title,   ':title2'           => $title,
            ':buyer_name'       => $buyer,
            ':status'           => $status,  ':status2'          => $status,
            ':stage'            => $stage,   ':stage2'           => $stage,
            ':value_amount'     => $value,   ':value_amount2'    => $value,
            ':value_currency'   => $curr,
            ':cpv_division'     => $cpvDiv,  ':cpv_division2'    => $cpvDiv,
            ':cpv_codes'        => $cpvFul,  ':cpv_codes2'       => $cpvFul,
            ':region'           => $region,  ':region2'          => $region,
            ':published_date'   => $pub,
            ':tender_end_date'  => $end,     ':tender_end_date2' => $end,
            ':official_url'     => $url,     ':official_url2'    => $url,
            ':supplier_names'   => $supp,    ':supplier_names2'  => $supp,
            ':description'      => $desc,    ':description2'     => $desc,
        ]);
        $inserted++;
        if ($inserted % 200 === 0) out("  {$inserted} records imported...");
    } catch (Throwable $e) {
        $errors++;
        if ($errors <= 3) out("Error on {$ocid}: " . $e->getMessage(), 'err');
    }
}

out("Import: {$inserted} upserted, {$errors} errors", $errors > 0 ? 'warn' : 'ok');

// ── LGAM classify new records only ────────────────────────────────────────
out("Classifying new records against LGAM...");

const TECH_CPVS_R = ['30','32','48','64','72','73'];

const LGAM_RULES_R = [
    ['public_channels','online',    'Online',             ['/\bwebsite\b/i','/web[\s-]portal/i','/online[\s-]form/i','/self[\s-]service[\s-]portal/i'], false],
    ['public_channels','messaging', 'Messaging / SMS',    ['/\bsms\b/i','/hybrid[\s-]mail/i'], false],
    ['public_channels','phone',     'Phone / Telephony',  ['/\btelephony\b/i','/\bvoip\b/i','/telephone[\s-]system/i'], false],
    ['capabilities','payments', 'Payments', ['/payment[\s-]platform/i','/payment[\s-]system/i'], false],
    ['capabilities','forms',    'Forms',    ['/digital[\s-]form/i','/e.?form[\s-]platform/i'], false],
    ['capabilities','booking',  'Booking',  ['/booking[\s-]system/i','/appointment[\s-]system/i'], false],
    ['business_areas','adult_social_care',     'Adult Social Care',      ['/adult[\s-]social[\s-]care.*system/i','/care[\s-]manag.*system/i'], true],
    ['business_areas','childrens_social_care', "Children's Social Care", ['/children.*social[\s-]care.*system/i','/safeguard.*system/i'], true],
    ['business_areas','democratic_services',   'Democratic Services',    ['/election.*(?:software|system)/i','/\bmod\.gov\b/i'], false],
    ['business_areas','education',             'Education',              ['/school.*manag.*system/i','/education.*manag.*system/i'], true],
    ['business_areas','highways_transport',    'Highways & Transport',   ['/highway.*asset/i','/fleet[\s-]manag/i','/parking.*(?:system|software)/i'], false],
    ['business_areas','housing',               'Housing',                ['/housing.*(?:system|software)/i','/tenant.*portal/i'], true],
    ['business_areas','leisure_culture',       'Leisure & Culture',      ['/library.*(?:system|rfid|manag)/i','/leisure.*(?:system|software)/i'], false],
    ['business_areas','planning_development',  'Planning & Development', ['/planning.*(?:software|system)/i','/\bidox\b/i'], false],
    ['business_areas','revenues_benefits',     'Revenues & Benefits',    ['/revenues.*benefit.*(?:system|software)/i'], true],
    ['business_areas','waste_management',      'Waste Management',       ['/waste.*(?:manag|it).*system/i'], false],
    ['corporate_areas','crm',          'Customer Relationship', ['/\bcrm\b/i','/customer.*manag.*system/i'], false],
    ['corporate_areas','financial',    'Financial',             ['/finance.*(?:system|software)/i','/\berp\b.*(?:system|implement)/i'], false],
    ['corporate_areas','geographical', 'Geographical',          ['/\bgis\b/i','/\besri\b/i','/gazetteer/i'], false],
    ['corporate_areas','hr_workforce', 'HR & Workforce',        ['/\bpayroll\b.*(?:system|software)/i','/hr.*(?:system|software)/i','/\blms\b/i'], false],
    ['foundational_ai','gen_ai',          'Generative AI',         ['/generative[\s-]?ai/i','/\bcopilot\b/i','/\bllm\b/i'], false],
    ['foundational_ai','intelligent_auto','Intelligent Automation',['/robotic[\s-]process[\s-]autom/i','/\brpa\b/i'], false],
    ['foundational_enduser','unified_comms',   'Unified Communications',['/\bm365\b/i','/microsoft[\s-]365/i','/enterprise[\s-]agreement/i'], false],
    ['foundational_enduser','end_user_devices','End User Devices',      ['/\blaptop/i','/hardware[\s-]refresh/i'], false],
    ['foundational_svc','service_desk','IT Service Desk',   ['/service[\s-]desk/i','/\bhelpdesk\b/i','/\bitsm\b/i'], false],
    ['foundational_infra','compute_storage','Compute & Storage', ['/server.*storage/i','/data[\s-]?centre/i','/cloud.*(?:infrastructure|hosting|backup)/i'], false],
    ['foundational_infra','connectivity', 'Connectivity & Network',['/wide[\s-]area[\s-]network/i','/\bwan\b/i','/network[\s-](?:infrastructure|refresh)/i'], false],
    ['security','vuln_threat',      'Vulnerability & Threat Mgmt',['/vulnerabilit.*scan/i','/penetration[\s-]test/i'], false],
    ['security','network_endpoint', 'Network & Endpoint Security', ['/\bfirewall/i','/endpoint[\s-](?:security|protect)/i'], false],
    ['security','physical_security','Physical Security',           ['/\bcctv\b/i','/body[\s-]worn.*camera/i'], false],
    ['data_info','bi_analytics','BI & Analytics',               ['/business[\s-]intelligence.*platform/i','/analytics.*platform/i'], false],
    ['data_info','doc_records',  'Document & Records Management',['/document[\s-]manag.*system/i','/records[\s-]manag.*system/i'], false],
];

function classifyLgamR(string $text, string $cpv): array {
    foreach (LGAM_RULES_R as [$layer, $sub, $name, $patterns, $requireTech]) {
        if ($requireTech && !in_array($cpv, TECH_CPVS_R, true)) continue;
        foreach ($patterns as $pat) {
            if (preg_match($pat, $text)) return [$layer, $sub, $name];
        }
    }
    return [null, null, null];
}

$unclassified = $pdo->query("SELECT ocid, title, description, cpv_division FROM ct_contracts WHERE lgam_layer IS NULL AND source='fts'")->fetchAll();
$update = $pdo->prepare("UPDATE ct_contracts SET lgam_layer=:l, lgam_sublayer=:s, lgam_sublayer_name=:n WHERE ocid=:o");
$classified = 0;
foreach ($unclassified as $row) {
    $text = ($row['title'] ?? '') . ' ' . ($row['description'] ?? '');
    [$l, $s, $n] = classifyLgamR($text, $row['cpv_division'] ?? '');
    if ($l) {
        $update->execute([':l'=>$l,':s'=>$s,':n'=>$n,':o'=>$row['ocid']]);
        $classified++;
    }
}
out("LGAM classified: {$classified} new records", 'ok');


out("Running vendor matching on new FTS records...");



$new_rows = $pdo->query("SELECT ocid, title, description FROM ct_contracts WHERE source='fts' AND fetched_at >= NOW() - INTERVAL 1 HOUR")->fetchAll();
$matched  = 0;
foreach ($new_rows as $row) {
    $text = ($row['title'] ?? '') . ' ' . ($row['description'] ?? '');
    foreach (VENDOR_PATTERNS_R as $vendor => $patterns) {
        foreach ($patterns as $pat) {
            if (preg_match($pat, $text)) {
                $insert->execute([$vendor, $row['ocid']]);
                $matched++;
                break;
            }
        }
    }
}
out("Vendor links added: {$matched}", 'ok');

// ── Save refresh timestamp ─────────────────────────────────────────────────
$pdo->prepare("INSERT INTO ct_sync_state (k,v) VALUES ('fts_last_refresh',?) ON DUPLICATE KEY UPDATE v=?")
    ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

// ── Summary ───────────────────────────────────────────────────────────────
$total_fts = $pdo->query("SELECT COUNT(*) FROM ct_contracts WHERE source='fts'")->fetchColumn();
out("", 'ok');
out("═══════════════════════════════════════", 'ok');
out("Refresh complete!", 'ok');
out("  New records imported : {$inserted}", 'ok');
out("  Total FTS in database: " . number_format((int)$total_fts), 'ok');
out("  LGAM classified      : {$classified}", 'ok');
out("  Vendor links         : {$matched}", 'ok');
out("═══════════════════════════════════════", 'ok');
out("");
out('Return to dashboard: <a href="/contracts/">contracts dashboard</a>');
