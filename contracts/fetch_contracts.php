<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { header('HTTP/1.1 403 Forbidden'); exit; }
/**
 * fetch_contracts.php
 * ─────────────────────────────────────────────────────────────────────────
 * Pulls local government contracts from the Contracts Finder OCDS API
 * and Find a Tender OCDS API into a local MySQL database, then applies
 * LGAM layer classification and vendor matching.
 *
 * APIS USED  (both free, no authentication needed for reading)
 * ─────────────────────────────────────────────────────────────────────────
 * Contracts Finder OCDS search:
 *   https://www.contractsfinder.service.gov.uk/Published/Notices/OCDS/Search
 *   ?publishedFrom={ISO8601}&publishedTo={ISO8601}&stages=planning,tender,award
 *   &limit=100&cursor={cursor}
 *
 * Find a Tender OCDS release packages:
 *   https://www.find-tender.service.gov.uk/api/1.0/ocdsReleasePackages
 *   ?publishedFrom={ISO8601}&publishedTo={ISO8601}&stages=planning,tender,award
 *   &limit=100&cursor={cursor}
 *
 * HOW IT WORKS
 * ─────────────────────────────────────────────────────────────────────────
 * 1. Read last-sync timestamp from ct_sync_state table.
 * 2. Page through CF and FTS APIs from that timestamp to now.
 * 3. Filter to local-authority buyers only (buyer name matching).
 * 4. Upsert each matching release into ct_contracts.
 * 5. Re-run LGAM classification on all rows.
 * 6. Save new sync timestamp.
 *
 * SETUP
 * ─────────────────────────────────────────────────────────────────────────
 * 1. Run schema.sql against your architect_2 database.
 * 2. Edit the CONFIG section below (DB credentials).
 * 3. First run / backfill from 2024:
 *      php fetch_contracts.php --backfill 2024-01-01
 * 4. Weekly cron (Monday 03:00):
 *      0 3 * * 1 /usr/bin/php /path/to/fetch_contracts.php >> /path/to/fetch_contracts.log 2>&1
 *
 * RATE LIMITING
 * ─────────────────────────────────────────────────────────────────────────
 * CF returns HTTP 403 if you exceed ~1 req/sec.
 * SLEEP_BETWEEN_PAGES = 1 is safe for both APIs.
 * Backfill from 2024 (~2 years): ~2000+ pages, 30-60 min.
 * Weekly incremental run: ~20-50 pages, under 2 min.
 */

set_time_limit(0);
ignore_user_abort(true); // keep running if browser disconnects

// Stream output to browser in real time
while (ob_get_level()) ob_end_clean();
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');
// Output a padding block so the browser starts rendering immediately
// (most browsers wait for ~1KB before rendering streamed responses)
echo '<html><head><style>body{font-family:monospace;font-size:13px;background:#1a1a1a;color:#d4d4d4;padding:1rem;white-space:pre-wrap;} .info{color:#9cdcfe} .error{color:#f48771} .warn{color:#dcdcaa}</style></head><body>';
echo str_pad('', 1024) . PHP_EOL; // padding to trigger browser render
flush();

// ════════════════════════════════════════════════════════════════════════════
// CONFIG — edit these
// ════════════════════════════════════════════════════════════════════════════

// Database credentials come from the shared LGAM config
require_once __DIR__ . '/config.php';

define('INITIAL_FETCH_FROM', '2024-01-01T00:00:00Z');

define('CF_BASE',  'https://www.contractsfinder.service.gov.uk/Published/Notices/OCDS/Search');
define('FTS_BASE', 'https://www.find-tender.service.gov.uk/api/1.0/ocdsReleasePackages');

// Scottish (Public Contracts Scotland) and Welsh (Sell2Wales) procurement data.
// Their own live OCDS APIs are unreachable/broken, so we use the OCP Data
// Registry's bulk per-year "compiled release" JSONL exports instead.
define('PCS_REGISTRY_URL', 'https://data.open-contracting.org/en/publication/39/download?name=%d.jsonl.gz');
define('S2W_REGISTRY_URL', 'https://data.open-contracting.org/en/publication/119/download?name=%d.jsonl.gz');

define('SLEEP_BETWEEN_PAGES', 0);   // sleep between pages (429 handler backs off automatically)
define('MAX_PAGES_PER_SOURCE', 0);  // 0 = unlimited; set e.g. 10 for testing

define('BUYER_PATTERNS', [
    'council', 'combined authority', 'borough',
    'county',  'city council',       'metropolitan',
]);

// ════════════════════════════════════════════════════════════════════════════
// LGAM CLASSIFICATION RULES
// [layer_id, sublayer_id, sublayer_name, [regex_patterns], require_tech_cpv]
// ════════════════════════════════════════════════════════════════════════════

define('TECH_CPVS', ['30','32','48','64','72','73']);

const LGAM_RULES = [
    ['public_channels','online',    'Online',             ['/\bwebsite\b/i','/web[\s-]portal/i','/digital[\s-]experience[\s-]platform/i','/cms[\s.-]platform/i','/online[\s-]form/i','/self[\s-]service[\s-]portal/i'], false],
    ['public_channels','messaging', 'Messaging / SMS',    ['/\bsms\b/i','/hybrid[\s-]mail/i','/gov.?delivery/i'], false],
    ['public_channels','phone',     'Phone / Telephony',  ['/\btelephony\b/i','/sip[\s-]prov/i','/\bvoip\b/i','/telephone[\s-]system/i','/contact[\s-]centre[\s-]system/i'], false],

    ['capabilities','payments', 'Payments', ['/cashless[\s-]park/i','/payment[\s-]platform/i','/payment[\s-]system/i','/bill[\s-]payment/i'], false],
    ['capabilities','forms',    'Forms',    ['/digital[\s-]form/i','/online[\s-]form/i','/e.?form[\s-]platform/i','/benefit[\s-]form/i','/citizen[\s-]form/i'], false],
    ['capabilities','booking',  'Booking',  ['/booking[\s-]system/i','/appointment[\s-]system/i','/diary[\s-]manag/i'], false],
    ['capabilities','identity', 'Identity', ['/identity[\s-]check/i','/digital[\s-]id[\s-]check/i','/identity[\s-]verif/i'], false],

    ['business_areas','adult_social_care',     'Adult Social Care',      ['/adult[\s-]social[\s-]care.*system/i','/\basc\b.*case/i','/care[\s-]manag.*system/i','/electronic[\s-]care[\s-]monitor/i','/care[\s-]schedul.*system/i'], true],
    ['business_areas','childrens_social_care', "Children's Social Care", ['/children.*social[\s-]care.*system/i','/care[\s-]leaver.*(?:applic|system|solution)/i','/children[\s-]in[\s-]our[\s-]care/i','/safeguard.*system/i'], true],
    ['business_areas','democratic_services',   'Democratic Services',    ['/election.*(?:software|system)/i','/electoral.*(?:system|software)/i','/\bmod\.gov\b/i','/committee.*meeting.*system/i'], false],
    ['business_areas','education',             'Education',              ['/school.*manag.*system/i','/\bmis\b.*school/i','/admissions.*system/i','/\bsen\b.*system/i','/education.*manag.*system/i'], true],
    ['business_areas','highways_transport',    'Highways & Transport',   ['/highway.*asset/i','/pothole.*detect/i','/fleet[\s-]manag/i','/traffic[\s-]manag.*system/i','/parking.*(?:system|software|platform)/i','/demand[\s-]responsive[\s-]transport/i'], false],
    ['business_areas','housing',               'Housing',                ['/housing.*(?:system|software)/i','/homelessness.*system/i','/repairs.*manag.*system/i','/housing.*benefit.*system/i','/tenant.*portal/i'], true],
    ['business_areas','leisure_culture',       'Leisure & Culture',      ['/library.*(?:system|rfid|manag)/i','/rfid.*library/i','/museum.*(?:system|website)/i','/leisure.*(?:system|software)/i'], false],
    ['business_areas','licensing_regulation',  'Licensing & Regulation', ['/licensing.*(?:system|software|platform)/i','/private[\s-]rented.*licen/i','/licensing.*data.*remediat/i'], false],
    ['business_areas','planning_development',  'Planning & Development', ['/planning.*(?:software|system|applic)/i','/\bidox\b/i','/building[\s-]control.*system/i'], false],
    ['business_areas','public_health',         'Public Health',          ['/stop[\s-]smoking.*(?:system|software)/i','/smoking[\s-]cessation.*system/i','/\bphilis\b/i'], false],
    ['business_areas','revenues_benefits',     'Revenues & Benefits',    ['/revenues.*benefit.*(?:system|software)/i','/council[\s-]tax.*(?:system|software)/i','/revenues.*form.*process/i'], true],
    ['business_areas','waste_management',      'Waste Management',       ['/waste.*(?:manag|it).*system/i','/waste.*software/i','/kerbside.*collect.*system/i'], false],

    ['corporate_areas','comms_pr',        'Communications & PR',            ['/corporate[\s-]intranet/i','/\bintranet\b/i','/webcasting/i','/audio[\s-]visual.*system/i','/digital[\s-]screen/i','/led[\s-]screen/i'], false],
    ['corporate_areas','crm',             'Customer Relationship (B2C)',    ['/\bcrm\b/i','/customer.*manag.*system/i','/netcall/i','/customer.*(?:engage|enable).*platform/i'], false],
    ['corporate_areas','facilities',      'Facilities',                     ['/\biwms\b/i','/asset.*manag.*(?:property|build|fire|rescue|bluelight)/i','/facilities.*manag.*system/i'], false],
    ['corporate_areas','financial',       'Financial',                      ['/finance.*(?:system|software)/i','/\berp\b.*(?:system|implement)/i','/oracle.*(?:financ|payroll|implement)/i','/purchase[\s-]order.*system/i'], false],
    ['corporate_areas','geographical',    'Geographical',                   ['/\bgis\b/i','/geograph.*inform.*system/i','/\besri\b/i','/gazetteer/i','/local[\s-]land[\s-]charges/i'], false],
    ['corporate_areas','legal_compliance','Legal & Compliance',             ['/coroner.*(?:case|workflow)/i','/matter[\s-]manag/i','/commercial.*manag.*system/i'], false],
    ['corporate_areas','hr_workforce',    'HR & Workforce',                 ['/\bpayroll\b.*(?:system|software)/i','/hr.*(?:system|software)/i','/learning[\s-]manag.*system/i','/\blms\b/i','/workforce.*system/i'], false],
    ['corporate_areas','governance_risk', 'Corporate Governance & Risk',    ['/risk[\s-]manag.*(?:system|software)/i','/cloud[\s-]compliance/i','/audit.*system/i'], false],

    ['foundational_ai','gen_ai',          'Generative AI',         ['/generative[\s-]?ai/i','/microsoft.*copilot/i','/\bcopilot\b/i','/\bllm\b/i'], false],
    ['foundational_ai','intelligent_auto','Intelligent Automation',['/robotic[\s-]process[\s-]autom/i','/\brpa\b/i','/intelligent[\s-]autom/i','/auto[\s-]?redact/i'], false],
    ['foundational_ai','machine_learning','Machine Learning',       ['/machine[\s-]?learn/i','/ai[\s-]?powered.*(?:detect|analyt|vision)/i','/pothole.*detect/i'], false],

    ['foundational_enduser','unified_comms',   'Unified Communications',['/microsoft[\s-]enterprise[\s-]agreement/i','/enterprise[\s-]agreement/i','/\bm365\b/i','/microsoft[\s-]365/i','/office[\s-]365/i','/microsoft.*licen/i'], false],
    ['foundational_enduser','end_user_devices','End User Devices',      ['/\blaptop/i','/chromebook/i','/end[\s-]user[\s-]device/i','/sim[\s-]card/i','/hardware[\s-]refresh/i','/smartphone.*supply/i'], false],
    ['foundational_enduser','productivity',    'Productivity',          ['/adobe.*licen/i','/miro.*licen/i','/power[\s-]bi.*licen/i'], false],

    ['foundational_svc','service_desk',  'IT Service Desk',          ['/service[\s-]desk/i','/it[\s-]service[\s-]desk/i','/\bhelpdesk\b/i','/\bitsm\b/i'], false],
    ['foundational_svc','sw_asset_licen','Software Asset & Licence', ['/software[\s-]asset[\s-]manag/i','/licence[\s-]manag/i','/\bvmware\b/i','/oracle.*licen/i'], false],

    ['foundational_infra','compute_storage','Compute, Storage & Network',['/server.*storage/i','/data[\s-]?centre/i','/cloud.*(?:infrastructure|hosting|backup)/i','/backup[\s-]solution/i','/\bcitrix\b/i','/disaster[\s-]recovery/i'], false],
    ['foundational_infra','connectivity', 'Connectivity & Network',      ['/wide[\s-]area[\s-]network/i','/\bwan\b/i','/structured[\s-]cabl/i','/\bwifi\b/i','/wi-fi/i','/network[\s-](?:infrastructure|refresh)/i','/sd-wan/i'], false],

    ['security','vuln_threat',      'Vulnerability & Threat Mgmt',['/vulnerabilit.*scan/i','/penetration[\s-]test/i','/it[\s-]health[\s-]check/i','/psn.*compliance/i'], false],
    ['security','network_endpoint', 'Network & Endpoint Security', ['/\bfirewall/i','/endpoint[\s-](?:security|protect)/i','/\bsophos\b/i','/mdr.*licen/i','/managed[\s-]detect/i'], false],
    ['security','soc',              'Security Operations (SOC)',   ['/security[\s-]operations[\s-]centre/i','/cyber[\s-]incident[\s-]response/i','/\bsiem\b/i','/microsoft[\s-]sentinel/i'], false],
    ['security','iam',              'Identity & Access Management',['/identity.*access[\s-]manag/i','/single[\s-]sign[\s-]on/i','/\bdashlane\b/i','/privileged[\s-]access/i'], false],
    ['security','physical_security','Physical Security',           ['/\bcctv\b/i','/public[\s-]space[\s-]surveil/i','/cctv.*install/i','/body[\s-]worn.*camera/i'], false],

    ['data_info','bi_analytics','BI & Analytics',               ['/business[\s-]intelligence.*platform/i','/analytics.*platform/i','/reporting.*platform/i','/power[\s-]bi.*platform/i'], false],
    ['data_info','data_science', 'Data Science & Predictive',   ['/data[\s-]architect.*appoint/i','/data[\s-]science/i','/debt[\s-]segmentation/i'], false],
    ['data_info','doc_records',  'Document & Records Management',['/document[\s-]manag.*system/i','/records[\s-]manag.*system/i','/\bedms\b/i'], false],
    ['data_info','geospatial',   'Geospatial Data',             ['/\bgis\b.*platform/i','/geograph.*inform.*system/i','/mapping.*software/i','/\besri\b.*licen/i'], false],
];

// ════════════════════════════════════════════════════════════════════════════
// VENDOR MATCHING RULES
// ════════════════════════════════════════════════════════════════════════════



// ════════════════════════════════════════════════════════════════════════════
// MAIN
// ════════════════════════════════════════════════════════════════════════════

$log = new Logger();
$log->info('=== fetch_contracts.php starting ===');

$forceSince = null;
// Support --backfill YYYY-MM-DD via CLI or ?backfill=YYYY-MM-DD via browser
$backfillDate = null;
if (in_array('--backfill', $argv ?? [], true)) {
    $idx = array_search('--backfill', $argv);
    if (isset($argv[$idx + 1]) && preg_match('/^\d{4}-\d{2}-\d{2}/', $argv[$idx + 1])) {
        $backfillDate = $argv[$idx + 1];
    }
} elseif (!empty($_GET['backfill']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $_GET['backfill'])) {
    $backfillDate = $_GET['backfill'];
}
if ($backfillDate) {
    $forceSince = $backfillDate . 'T00:00:00Z';
    $log->info("Backfill mode from {$forceSince}");
}

// --devolved-only: skip the CF/FTS fetchers (useful for backfilling/refreshing
// just PCS/Sell2Wales without re-running a slow full CF/FTS backfill)
$devolvedOnly = in_array('--devolved-only', $argv ?? [], true) || !empty($_GET['devolved_only']);

try {
    $db = new Database();
    $db->createTablesIfNeeded();

    $since = $forceSince ?? $db->getLastSyncTime();
    $until = gmdate('Y-m-d\TH:i:s\Z');
    $log->info("Syncing {$since} → {$until}");

    if ($devolvedOnly) {
        $cfTotal = 0;
        $ftsTotal = 0;
        $log->info('--devolved-only: skipping Contracts Finder / Find a Tender');
    } else {
        $cfTotal  = (new OcdsApiFetcher(CF_BASE,  'Contracts Finder', $log))->fetchAndStore($db, $since, $until);
        $ftsTotal = (new OcdsApiFetcher(FTS_BASE, 'Find a Tender',    $log))->fetchAndStore($db, $since, $until);
    }
    $pcsTotal = (new OcdsBulkFetcher(PCS_REGISTRY_URL, 'Public Contracts Scotland', 'pcs',       'Scotland', $log))
        ->fetchAndStore($db, $since, (bool)$forceSince);
    $s2wTotal = (new OcdsBulkFetcher(S2W_REGISTRY_URL, 'Sell2Wales',                'sell2wales', 'Wales',    $log))
        ->fetchAndStore($db, $since, (bool)$forceSince);
    $log->info("Upserted: {$cfTotal} CF + {$ftsTotal} FTS + {$pcsTotal} PCS + {$s2wTotal} Sell2Wales = "
        . ($cfTotal + $ftsTotal + $pcsTotal + $s2wTotal) . " total");

    $log->info('Classifying LGAM...');
    $log->info('  Classified: ' . $db->classifyAll());


    if (!$forceSince) {
        // Mark past-deadline active contracts as complete after every sync
        $pdo = db();
        $expired = $pdo->exec("
            UPDATE ct_contracts
            SET status = 'complete'
            WHERE status = 'active'
              AND tender_end_date IS NOT NULL
              AND tender_end_date < CURDATE()
        ");
        $log->info("Marked {$expired} past-deadline contracts as complete");

        $db->setLastSyncTime($until);
        $log->info("Saved sync time: {$until}");
    }

    $log->info('=== Done ===');

} catch (Throwable $e) {
    $log->error('FATAL: ' . $e->getMessage());
    $log->error($e->getTraceAsString());
    exit(1);
}


// ════════════════════════════════════════════════════════════════════════════
// OCDS API FETCHER
// ════════════════════════════════════════════════════════════════════════════

class OcdsApiFetcher
{
    public function __construct(
        private string $baseUrl,
        private string $name,
        private Logger $log
    ) {}

    public function fetchAndStore(Database $db, string $since, string $until): int
    {
        $inserted = 0;

        // Resume from saved cursor if we were interrupted
        $saved  = $db->getSavedCursor($this->name);
        $cursor = $saved['cursor'];
        $page   = $saved['page'];

        if ($cursor) {
            $this->log->info("  [{$this->name}] Resuming from page {$page} (saved cursor found)");
        }

        do {
            // FTS uses updatedFrom/updatedTo; CF uses publishedFrom/publishedTo
            $isFts = str_contains($this->baseUrl, 'find-tender');
            // FTS requires dates without trailing Z; CF requires ISO8601 with Z
            $sinceClean = rtrim($since, 'Z');
            $untilClean = rtrim($until, 'Z');
            $params = $isFts
                ? ['updatedFrom' => $sinceClean, 'updatedTo' => $untilClean, 'stages' => 'planning,tender,award', 'limit' => 100]
                : ['publishedFrom' => $since, 'publishedTo' => $until, 'stages' => 'planning,tender,award', 'limit' => 100];
            if ($cursor) $params['cursor'] = $cursor;
            $url = $this->baseUrl . '?' . http_build_query($params);

            $this->log->info("  [{$this->name}] page {$page}: GET {$url}");
            $data = json_decode($this->get($url), true);

            if (!$data) { $this->log->error("  JSON decode failed"); break; }

            $releases = $data['releases'] ?? [];
            $this->log->info("  Got " . count($releases) . " releases");

            foreach ($releases as $release) {
                $r = $this->normalise($release);
                if ($r && isLG($r['buyer_name'])) {
                    $db->upsertContract($r);
                    $inserted++;
                    if ($inserted % 100 === 0) {
                        $this->log->info("  [{$this->name}] {$inserted} LG contracts saved so far...");
                    }
                }
            }

            // Extract next cursor
            $cursor = null;
            if (!empty($data['links']['next'])) {
                parse_str(parse_url($data['links']['next'], PHP_URL_QUERY), $qs);
                $cursor = $qs['cursor'] ?? null;
            }

            $page++;

            // Save cursor after every page so we can resume if interrupted
            $db->saveCursor($this->name, $cursor, $page);

            if (MAX_PAGES_PER_SOURCE > 0 && $page >= MAX_PAGES_PER_SOURCE) {
                $this->log->info("  Reached page limit");
                break;
            }
            if ($cursor) usleep(500000); // 0.5s between pages — retry handler backs off on 429

        } while ($cursor && count($releases) > 0);

        // Clear saved cursor for this source — it's done
        $db->saveCursor($this->name, null, 0);
        $this->log->info("  [{$this->name}] done: {$page} pages, {$inserted} LG records");
        return $inserted;
    }

    private function normalise(array $r): ?array
    {
        $ocid  = $r['ocid'] ?? null;
        $buyer = $r['buyer']['name'] ?? null;
        if (!$ocid || !$buyer) return null;

        $tender = $r['tender']  ?? [];
        $awards = $r['awards']  ?? [];
        $tags   = $r['tag']     ?? [];

        // Stage
        $stage = 'active';
        if (array_intersect(['award','awardUpdate'], $tags))       $stage = 'award';
        elseif (array_intersect(['tender','tenderAmendment'], $tags)) $stage = 'tender';
        elseif (in_array('planning', $tags))                        $stage = 'planning';

        // Status
        if ($stage === 'award') {
            $status = ($awards[0]['status'] ?? '') === 'cancelled' ? 'cancelled' : 'complete';
        } else {
            $ts     = strtolower($tender['status'] ?? 'active');
            $status = in_array($ts, ['active','planned','cancelled','complete']) ? $ts : 'active';
        }

        // Value
        $value = null;
        if (!empty($awards[0]['value']['amount']))  $value = (float)$awards[0]['value']['amount'];
        elseif (!empty($tender['value']['amount'])) $value = (float)$tender['value']['amount'];

        // Suppliers
        $suppliers = [];
        foreach ($awards as $a) {
            foreach ($a['suppliers'] ?? [] as $s) {
                if (!empty($s['name'])) $suppliers[] = $s['name'];
            }
        }

        // CPV
        $cpvFull = $tender['classification']['id'] ?? null;
        $cpvDiv  = $cpvFull ? substr((string)$cpvFull, 0, 2) : null;

        // Dates
        $pub = $r['date'] ?? $tender['datePublished'] ?? null;
        $pub = $pub ? date('Y-m-d', strtotime($pub)) : null;
        $end = $tender['tenderPeriod']['endDate'] ?? null;
        $end = $end ? date('Y-m-d', strtotime($end)) : null;

        // Region
        $region = null;
        foreach ($tender['items'] ?? [] as $item) {
            foreach ($item['deliveryAddresses'] ?? [] as $addr) {
                if (!empty($addr['region'])) { $region = $addr['region']; break 2; }
            }
        }

        // Official URL
        $url  = null;
        $docs = array_merge($tender['documents'] ?? [], $r['planning']['documents'] ?? []);
        foreach ($docs as $doc) {
            if (in_array($doc['documentType'] ?? '', ['tenderNotice','awardNotice','plannedProcurementNotice'])) {
                $url = $doc['url'] ?? null;
                break;
            }
        }

        $title = $tender['title'] ?? ($r['planning']['documents'][0]['description'] ?? '');

        return [
            'ocid'            => $ocid,
            'title'           => sanitiseUtf8(substr(strip_tags($title), 0, 500)),
            'buyer_name'      => sanitiseUtf8($buyer),
            'status'          => $status,
            'stage'           => $stage,
            'value_amount'    => $value,
            'value_currency'  => $tender['value']['currency'] ?? ($awards[0]['value']['currency'] ?? 'GBP'),
            'cpv_division'    => $cpvDiv,
            'cpv_codes'       => $cpvFull,
            'region'          => sanitiseUtf8($region),
            'published_date'  => $pub,
            'tender_end_date' => $end,
            'official_url'    => $url,
            'source'          => str_contains($this->baseUrl, 'contractsfinder') ? 'contracts_finder' : 'fts',
            'supplier_names'  => $suppliers ? sanitiseUtf8(implode(' | ', array_unique($suppliers))) : null,
            'description'     => sanitiseUtf8(substr(strip_tags($tender['description'] ?? ''), 0, 1000)),
        ];
    }

    private function get(string $url): string
    {
        $maxRetries = 5;
        $retryDelay = 10; // seconds, doubles on each retry (10, 20, 40, 80, 160)

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,  // include response headers so we can read Retry-After
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_USERAGENT      => 'LGTenderFetcher/1.0 (contact@rumens.uk)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $raw      = curl_exec($ch);
            $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hdrSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($raw === false) throw new RuntimeException("curl failed: {$url}");

            $headers = substr($raw, 0, $hdrSize);
            $body    = substr($raw, $hdrSize);

            if ($code === 200) return $body;

            if ($code === 429 || $code === 503) {
                if ($attempt === $maxRetries) {
                    throw new RuntimeException("Still rate limited after {$maxRetries} retries: {$url}");
                }
                // Respect Retry-After header if present, otherwise exponential backoff
                $wait = $retryDelay * (2 ** $attempt);
                if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
                    $wait = (int)$m[1] + 1;
                }
                $this->log->info("  Rate limited ({$code}), waiting {$wait}s then retry " . ($attempt + 1) . "/{$maxRetries}");
                sleep($wait);
                continue;
            }

            if ($code === 403) throw new RuntimeException("Access denied (403) for {$url}");

            throw new RuntimeException("HTTP {$code} for {$url}");
        }

        throw new RuntimeException("Failed after {$maxRetries} retries: {$url}");
    }
}


// ════════════════════════════════════════════════════════════════════════════
// OCDS BULK FETCHER (Public Contracts Scotland / Sell2Wales via OCP Data Registry)
// ════════════════════════════════════════════════════════════════════════════
//
// PCS and Sell2Wales don't have usable live OCDS APIs (PCS API is unreachable,
// Sell2Wales API has an expired cert and a server-side 500 error). Instead we
// pull their "compiled release" data from the Open Contracting Partnership's
// Data Registry, which bundles one JSONL file per calendar year.
//
// Compiled releases differ from the CF/FTS tender/award/planning releases:
//  - tag is always ['compiled'] (no stage info in the tag)
//  - tender.datePublished is never populated — only the top-level `date`
//    (last-compiled timestamp) is available, so that's used for published_date
//  - the contract value often only appears in contracts[].value, not
//    tender.value or awards[].value
class OcdsBulkFetcher
{
    public function __construct(
        private string $urlTemplate,
        private string $name,
        private string $sourceKey,
        private string $defaultRegion,
        private Logger $log
    ) {}

    public function fetchAndStore(Database $db, string $since, bool $isBackfill): int
    {
        $endYear = (int)gmdate('Y');
        if ($isBackfill) {
            $startYear = (int)substr($since, 0, 4);
        } else {
            // Re-fetch this year + last year on every run — cheap (a few MB)
            // and catches any updates made to recently-compiled records.
            $startYear = $endYear - 1;
        }

        $inserted = 0;
        for ($year = $startYear; $year <= $endYear; $year++) {
            $url = sprintf($this->urlTemplate, $year);
            $this->log->info("  [{$this->name}] downloading {$year}.jsonl.gz...");

            try {
                $gz = $this->get($url);
            } catch (Throwable $e) {
                $this->log->error("  [{$this->name}] {$year}: " . $e->getMessage());
                continue;
            }

            $jsonl = gzdecode($gz);
            if ($jsonl === false) {
                $this->log->error("  [{$this->name}] {$year}: failed to decompress");
                continue;
            }

            $yearCount = 0;
            foreach (explode("\n", $jsonl) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $record = json_decode($line, true);
                if (!$record) continue;

                $r = $this->normalise($record);
                if ($r && isLG($r['buyer_name'])) {
                    $db->upsertContract($r);
                    $inserted++;
                    $yearCount++;
                }
            }
            $this->log->info("  [{$this->name}] {$year}: {$yearCount} LG records");
        }

        $this->log->info("  [{$this->name}] done: {$inserted} LG records");
        return $inserted;
    }

    private function normalise(array $r): ?array
    {
        $ocid  = $r['ocid'] ?? null;
        $buyer = $r['buyer']['name'] ?? null;
        if (!$ocid || !$buyer) return null;

        $tender    = $r['tender']    ?? [];
        $awards    = $r['awards']    ?? [];
        $contracts = $r['contracts'] ?? [];

        // Status: map tender.status (if present) onto our known status set
        $statusMap = [
            'active'       => 'active',
            'planned'      => 'planned',
            'planning'     => 'planned',
            'complete'     => 'complete',
            'cancelled'    => 'cancelled',
            'unsuccessful' => 'cancelled',
            'withdrawn'    => 'withdrawn',
        ];
        $ts     = strtolower((string)($tender['status'] ?? ''));
        $status = $statusMap[$ts] ?? null;

        // Stage: derive from what data is actually present, since compiled
        // releases don't carry stage info in their tag
        if (!empty($contracts) || !empty($awards)) {
            $stage  = 'award';
            $status = $status ?? 'complete';
        } elseif ($ts === 'planned' || $ts === 'planning') {
            $stage  = 'planning';
            $status = $status ?? 'planned';
        } else {
            $stage  = 'tender';
            $status = $status ?? 'active';
        }

        // Value: awards -> tender -> sum of contracts
        $value    = null;
        $currency = 'GBP';
        if (!empty($awards[0]['value']['amount'])) {
            $value    = (float)$awards[0]['value']['amount'];
            $currency = $awards[0]['value']['currency'] ?? $currency;
        } elseif (!empty($tender['value']['amount'])) {
            $value    = (float)$tender['value']['amount'];
            $currency = $tender['value']['currency'] ?? $currency;
        } elseif (!empty($contracts)) {
            $sum = 0; $found = false;
            foreach ($contracts as $c) {
                if (!empty($c['value']['amount'])) {
                    $sum     += (float)$c['value']['amount'];
                    $currency = $c['value']['currency'] ?? $currency;
                    $found    = true;
                }
            }
            if ($found) $value = $sum;
        }

        // Suppliers: from awards[].suppliers, else parties with a supplier role
        $suppliers = [];
        foreach ($awards as $a) {
            foreach ($a['suppliers'] ?? [] as $s) {
                if (!empty($s['name'])) $suppliers[] = $s['name'];
            }
        }
        if (!$suppliers) {
            foreach ($r['parties'] ?? [] as $p) {
                if (!empty($p['name']) && in_array('supplier', $p['roles'] ?? [], true)) {
                    $suppliers[] = $p['name'];
                }
            }
        }

        // CPV
        $cpvFull = $tender['classification']['id'] ?? null;
        $cpvDiv  = $cpvFull ? substr((string)$cpvFull, 0, 2) : null;

        // Dates: no tender.datePublished in compiled releases — use the
        // top-level `date` (last-compiled timestamp) as the closest proxy.
        // upsertContract() never overwrites published_date on re-fetch, so
        // this stays fixed at first-seen date rather than drifting.
        $pub = $r['date'] ?? null;
        $pub = $pub ? date('Y-m-d', strtotime($pub)) : null;
        $end = $tender['tenderPeriod']['endDate'] ?? null;
        $end = $end ? date('Y-m-d', strtotime($end)) : null;

        // Region: rarely populated in compiled releases — fall back to the
        // nation this source covers
        $region = null;
        foreach ($tender['items'] ?? [] as $item) {
            foreach ($item['deliveryAddresses'] ?? [] as $addr) {
                if (!empty($addr['region'])) { $region = $addr['region']; break 2; }
            }
        }
        $region = $region ?? $this->defaultRegion;

        // Official URL: prefer a notice-page document, else any document
        $url = null;
        foreach ($tender['documents'] ?? [] as $doc) {
            if (in_array($doc['documentType'] ?? '', ['tenderNotice','awardNotice','contractNotice','plannedProcurementNotice'])) {
                $url = $doc['url'] ?? null;
                break;
            }
        }
        if (!$url && !empty($tender['documents'][0]['url'])) {
            $url = $tender['documents'][0]['url'];
        }

        $title = $tender['title'] ?? ($contracts[0]['title'] ?? '');
        $desc  = $tender['description'] ?? $r['description'] ?? '';

        return [
            'ocid'            => $ocid,
            'title'           => sanitiseUtf8(substr(strip_tags($title), 0, 500)),
            'buyer_name'      => sanitiseUtf8($buyer),
            'status'          => $status,
            'stage'           => $stage,
            'value_amount'    => $value,
            'value_currency'  => $currency,
            'cpv_division'    => $cpvDiv,
            'cpv_codes'       => $cpvFull,
            'region'          => sanitiseUtf8($region),
            'published_date'  => $pub,
            'tender_end_date' => $end,
            'official_url'    => $url,
            'source'          => $this->sourceKey,
            'supplier_names'  => $suppliers ? sanitiseUtf8(implode(' | ', array_unique($suppliers))) : null,
            'description'     => sanitiseUtf8(substr(strip_tags($desc), 0, 1000)),
        ];
    }

    private function get(string $url): string
    {
        $maxRetries = 3;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_USERAGENT      => 'LGTenderFetcher/1.0 (contact@rumens.uk)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false) throw new RuntimeException("curl failed: {$url}");
            if ($code === 200) return $body;
            if ($code === 404) throw new RuntimeException("HTTP 404 (no data for this year yet): {$url}");

            if ($attempt === $maxRetries) throw new RuntimeException("HTTP {$code} for {$url}");
            sleep(5 * ($attempt + 1));
        }
        throw new RuntimeException("Failed after {$maxRetries} retries: {$url}");
    }
}


// ════════════════════════════════════════════════════════════════════════════
// DATABASE
// ════════════════════════════════════════════════════════════════════════════

class Database
{
    private PDO $pdo;

    public function __construct()
    {
        // Use the shared db() singleton from lgam/config.php
        $this->pdo = db();
        $this->pdo->exec("SET NAMES utf8mb4");
    }

    public function createTablesIfNeeded(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ct_contracts (
                ocid                VARCHAR(120)    NOT NULL PRIMARY KEY,
                title               TEXT,
                buyer_name          VARCHAR(255),
                status              VARCHAR(30),
                stage               VARCHAR(30),
                value_amount        DECIMAL(15,2)   DEFAULT NULL,
                value_currency      VARCHAR(5),
                cpv_division        VARCHAR(5),
                cpv_codes           VARCHAR(200),
                region              VARCHAR(100),
                published_date      DATE            DEFAULT NULL,
                tender_end_date     DATE            DEFAULT NULL,
                official_url        TEXT,
                source              VARCHAR(30),
                supplier_names      TEXT,
                description         TEXT,
                lgam_layer          VARCHAR(60)     DEFAULT NULL,
                lgam_sublayer       VARCHAR(60)     DEFAULT NULL,
                lgam_sublayer_name  VARCHAR(100)    DEFAULT NULL,
                fetched_at          DATETIME        DEFAULT NULL,
                INDEX idx_buyer       (buyer_name(100)),
                INDEX idx_status      (status),
                INDEX idx_cpv         (cpv_division),
                INDEX idx_published   (published_date),
                INDEX idx_lgam_layer  (lgam_layer),
                INDEX idx_source      (source)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");



        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ct_sync_state (
                k VARCHAR(50) NOT NULL PRIMARY KEY,
                v TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function getLastSyncTime(): string
    {
        $row = $this->pdo->query("SELECT v FROM ct_sync_state WHERE k='last_sync'")->fetch();
        return $row ? $row['v'] : INITIAL_FETCH_FROM;
    }

    public function saveCursor(string $source, ?string $cursor, int $page): void
    {
        $key = 'cursor_' . $source;
        if ($cursor === null) {
            // Source finished — clear its cursor
            $this->pdo->prepare("DELETE FROM ct_sync_state WHERE k=?")->execute([$key]);
            $this->pdo->prepare("DELETE FROM ct_sync_state WHERE k=?")->execute([$key . '_page']);
        } else {
            $this->pdo->prepare(
                "INSERT INTO ct_sync_state (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)"
            )->execute([$key, $cursor]);
            $this->pdo->prepare(
                "INSERT INTO ct_sync_state (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)"
            )->execute([$key . '_page', (string)$page]);
        }
    }

    public function getSavedCursor(string $source): array
    {
        $key = 'cursor_' . $source;
        $cursorRow = $this->pdo->prepare("SELECT v FROM ct_sync_state WHERE k=?")->execute([$key])
            ? $this->pdo->prepare("SELECT v FROM ct_sync_state WHERE k=?")->execute([$key]) && false
            : null;
        // Simpler approach
        $stmt = $this->pdo->prepare("SELECT k,v FROM ct_sync_state WHERE k IN (?,?)");
        $stmt->execute([$key, $key . '_page']);
        $rows = $stmt->fetchAll();
        $data = array_column($rows, 'v', 'k');
        return [
            'cursor' => $data[$key] ?? null,
            'page'   => isset($data[$key . '_page']) ? (int)$data[$key . '_page'] : 0,
        ];
    }

    public function setLastSyncTime(string $ts): void
    {
        $this->pdo->prepare(
            "INSERT INTO ct_sync_state (k,v) VALUES ('last_sync',?) ON DUPLICATE KEY UPDATE v=?"
        )->execute([$ts, $ts]);
    }

    public function upsertContract(array $r): void
    {
        // Use individual UPDATE assignments for MariaDB compatibility
        // (MariaDB <10.3 does not support VALUES() in ON DUPLICATE KEY UPDATE)
        $this->pdo->prepare("
            INSERT INTO ct_contracts
                (ocid,title,buyer_name,status,stage,value_amount,value_currency,
                 cpv_division,cpv_codes,region,published_date,tender_end_date,
                 official_url,source,supplier_names,description,fetched_at)
            VALUES
                (:ocid,:title,:buyer_name,:status,:stage,:value_amount,:value_currency,
                 :cpv_division,:cpv_codes,:region,:published_date,:tender_end_date,
                 :official_url,:source,:supplier_names,:description,NOW())
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
        ")->execute([
            ':ocid'             => $r['ocid'],
            ':title'            => $r['title'],
            ':title2'           => $r['title'],
            ':buyer_name'       => $r['buyer_name'],
            ':status'           => $r['status'],
            ':status2'          => $r['status'],
            ':stage'            => $r['stage'],
            ':stage2'           => $r['stage'],
            ':value_amount'     => $r['value_amount'],
            ':value_amount2'    => $r['value_amount'],
            ':value_currency'   => $r['value_currency'] ?? 'GBP',
            ':cpv_division'     => $r['cpv_division'],
            ':cpv_division2'    => $r['cpv_division'],
            ':cpv_codes'        => $r['cpv_codes'],
            ':cpv_codes2'       => $r['cpv_codes'],
            ':region'           => $r['region'],
            ':region2'          => $r['region'],
            ':published_date'   => $r['published_date'],
            ':tender_end_date'  => $r['tender_end_date'],
            ':tender_end_date2' => $r['tender_end_date'],
            ':official_url'     => $r['official_url'],
            ':official_url2'    => $r['official_url'],
            ':source'           => $r['source'],
            ':supplier_names'   => $r['supplier_names'],
            ':supplier_names2'  => $r['supplier_names'],
            ':description'      => $r['description'],
            ':description2'     => $r['description'],
        ]);
    }

    public function classifyAll(): int
    {
        $stmt   = $this->pdo->query("SELECT ocid,title,description,cpv_division FROM ct_contracts");
        $update = $this->pdo->prepare(
            "UPDATE ct_contracts SET lgam_layer=:l,lgam_sublayer=:s,lgam_sublayer_name=:n WHERE ocid=:o"
        );
        $count = 0;
        while ($row = $stmt->fetch()) {
            $text = ($row['title'] ?? '') . ' ' . ($row['description'] ?? '');
            [$l, $s, $n] = classifyLgam($text, $row['cpv_division'] ?? '');
            $update->execute([':l' => $l, ':s' => $s, ':n' => $n, ':o' => $row['ocid']]);
            $count++;
        }
        return $count;
    }


}


// ════════════════════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════════════════════

function isLG(string $name): bool
{
    $lower = strtolower($name);
    foreach (BUYER_PATTERNS as $p) {
        if (str_contains($lower, $p)) return true;
    }
    return false;
}

/**
 * Ensure a string is valid utf8mb4 before inserting into MySQL.
 * Strips invalid byte sequences that would cause "Incorrect string value" errors.
 */
function sanitiseUtf8(?string $s): ?string
{
    if ($s === null) return null;
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    // Remove 4-byte characters (emoji etc.) that non-mb4 connections reject
    $s = preg_replace('/[\xF0-\xF7][\x80-\xBF]{3}/', '', $s);
    return $s;
}

function classifyLgam(string $text, string $cpv): array
{
    foreach (LGAM_RULES as [$layer, $sub, $name, $patterns, $requireTech]) {
        if ($requireTech && !in_array($cpv, TECH_CPVS, true)) continue;
        foreach ($patterns as $pat) {
            if (preg_match($pat, $text)) return [$layer, $sub, $name];
        }
    }
    return [null, null, null];
}

class Logger
{
    private string $file;
    public function __construct() { $this->file = __DIR__ . '/fetch_contracts.log'; }
    public function info(string $m): void  { $this->w('INFO ', $m); }
    public function error(string $m): void { $this->w('ERROR', $m); }
    private function w(string $l, string $m): void
    {
        $line = sprintf('[%s] %s  %s', date('Y-m-d H:i:s'), $l, $m);
        $class = $l === 'ERROR' ? 'error' : ($l === 'WARN ' ? 'warn' : 'info');
        $isCli = (php_sapi_name() === 'cli');
        if ($isCli) {
            echo $line . PHP_EOL;
        } else {
            echo '<span class="' . $class . '">' . htmlspecialchars($line) . '</span>' . PHP_EOL;
            ob_flush();
            flush();
        }
        file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
