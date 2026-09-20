<?php
/**
 * check_sources.php — checks council pages for new spend files using Claude to parse the page
 * Run manually or via cron: 0 6 1 * * curl -s "https://<your-host>/contracts/check_sources.php?token=YOUR_TOKEN"
 */
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$dry_run = isset($_GET['dry']);

layout_head('Check for new data sources');
?>
<p class="govuk-body-s govuk-!-colour-secondary">
  Fetches each council's transparency page, uses AI to identify new CSV/XLSX files, and adds them to data sources.
  <?php if ($dry_run): ?>
    <strong style="color:#f47738">DRY RUN — nothing will be saved.</strong>
  <?php endif; ?>
</p>
<style>
.log { font-family:monospace; font-size:0.8rem; background:#1a1a1a; color:#ccc;
       padding:16px; border-radius:4px; white-space:pre-wrap; min-height:100px }
.log .ok   { color:#68c57c }
.log .new  { color:#79c0ff; font-weight:bold }
.log .warn { color:#f0a500 }
.log .err  { color:#f87171 }
.log .head { color:#c9a0ff; font-weight:bold }
</style>
<div class="log" id="log">
<?php
ob_implicit_flush(true);
@ob_end_flush();

function out(string $msg, string $cls = ''): void {
    $ts = date('H:i:s');
    $c  = $cls ? " class=\"{$cls}\"" : '';
    echo "<span{$c}>[{$ts}] " . htmlspecialchars($msg) . "</span>\n";
    flush();
}

function fetch_page(string $url): ?string {
    $ctx = stream_context_create(['http' => [
        'timeout'       => 30,
        'user_agent'    => 'LGContractsDashboard/1.0 (phil@rumens.uk)',
        'ignore_errors' => true,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    return ($html !== false && strlen($html) > 500) ? $html : null;
}

// Strip HTML to plain text, keeping link hrefs
function html_to_text(string $html): string {
    // Replace links with "TEXT [URL]" format so Claude can see both
    $html = preg_replace('~<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>~is', '$2 [$1]', $html);
    // Strip remaining tags and collapse whitespace
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    // Keep reasonable length
    return mb_substr($text, 0, 12000);
}

// Call Claude API to extract file links from page text
function extract_files_with_ai(string $page_text, string $council, string $format): array {
    $prompt = "You are extracting spend data file links from a council transparency page.

Council: {$council}
File format to look for: {$format} files (expenditure over £500)

Page content:
{$page_text}

Extract all {$format} download links for monthly spend/expenditure data files.
For each file found, return a JSON array of objects with:
- \"url\": the full download URL
- \"period\": the month in YYYY-MM format (e.g. \"2025-03\" for March 2025)

Only include actual data file download links, not page links or navigation.
If you cannot determine the period for a file, omit it.
Return ONLY a JSON array, no explanation. Example:
[{\"url\":\"https://example.com/file.csv\",\"period\":\"2025-03\"}]";

    $response = @file_get_contents('https://api.anthropic.com/v1/messages', false,
        stream_context_create(['http' => [
            'method'  => 'POST',
            'timeout' => 30,
            'header'  => implode("\r\n", [
                'Content-Type: application/json',
                'anthropic-version: 2023-06-01',
            ]),
            'content' => json_encode([
                'model'      => 'claude-sonnet-4-20250514',
                'max_tokens' => 2000,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]),
        ]])
    );

    if (!$response) return [];

    $data = json_decode($response, true);
    $text = $data['content'][0]['text'] ?? '';

    // Strip markdown fences if present
    $text = preg_replace('/^```[a-z]*\n?|\n?```$/m', '', trim($text));

    $files = json_decode($text, true);
    if (!is_array($files)) return [];

    // Validate each entry
    $valid = [];
    foreach ($files as $f) {
        if (!isset($f['url'], $f['period'])) continue;
        if (!preg_match('/^\d{4}-\d{2}$/', $f['period'])) continue;
        if (!filter_var($f['url'], FILTER_VALIDATE_URL)) continue;
        $valid[] = ['url' => $f['url'], 'period' => $f['period']];
    }
    return $valid;
}

// ── Council configs ───────────────────────────────────────────────────────
$councils = [
    [
        'council'  => 'West Berkshire Council',
        'page_url' => 'https://www.westberks.gov.uk/expenditure-over-500',
        'format'   => 'xlsx',
    ],
    [
        'council'  => 'West Sussex County Council',
        'page_url' => 'https://www.westsussex.gov.uk/about-the-council/information-and-data/data-store/local-government-transparency-code-data/',
        'format'   => 'csv',
    ],
    [
        'council'  => 'Cambridgeshire County Council',
        'page_url' => 'https://data.cambridgeshireinsight.org.uk/dataset/cambridgeshire-county-council-expenditure-over-%C2%A3500',
        'format'   => 'csv',
    ],

    // ── Buckinghamshire Council
    [
        'council'  => 'Buckinghamshire Council',
        'page_url' => 'https://www.buckinghamshire.gov.uk/your-council/about-the-council/budgets-and-finance/spending-contracts-and-transparency/spending-over-500/',
        'format'   => 'csv',
    ],

    // ── Gloucestershire County Council
    [
        'council'  => 'Gloucestershire County Council',
        'page_url' => 'https://www.gloucestershire.gov.uk/council-and-democracy/performance-and-spending/spend-over-500',
        'format'   => 'csv',
    ],
];

// ── Get existing sources ──────────────────────────────────────────────────
$existing = [];
foreach ($pdo->query("SELECT council, period FROM ct_spend_sources") as $row) {
    $existing[$row['council']][$row['period']] = true;
}

$total_new = 0;

foreach ($councils as $cfg) {
    $council = $cfg['council'];
    out("── {$council} ──", 'head');
    out("  Fetching page…");

    $html = fetch_page($cfg['page_url']);
    if (!$html) {
        out("  Failed to fetch page", 'err');
        continue;
    }

    $text = html_to_text($html);
    out("  Page fetched (" . number_format(strlen($text)) . " chars of text). Asking AI to identify files…");

    $files = extract_files_with_ai($text, $council, $cfg['format']);

    if (!$files) {
        out("  No files identified by AI", 'warn');
        continue;
    }

    out("  AI found " . count($files) . " file(s)");

    $council_existing = $existing[$council] ?? [];
    $added = 0;

    foreach ($files as $f) {
        $period = $f['period'];
        $url    = $f['url'];

        if (isset($council_existing[$period])) {
            out("  {$period}: already exists — skip");
            continue;
        }

        out("  {$period}: NEW → " . basename(parse_url($url, PHP_URL_PATH)), 'new');

        if (!$dry_run) {
            try {
                $pdo->prepare("
                    INSERT IGNORE INTO ct_spend_sources (council, url, period, format, active)
                    VALUES (?, ?, ?, ?, 1)
                ")->execute([$council, $url, $period, $cfg['format']]);
                $added++;
                $total_new++;
            } catch (Throwable $e) {
                out("  Error: " . $e->getMessage(), 'err');
            }
        } else {
            $added++;
            $total_new++;
        }
    }

    if ($added === 0) {
        out("  All periods already in sources — nothing new", 'ok');
    } else {
        out("  Added {$added} new source(s)", 'ok');
    }
}

out('');
out('═══════════════════════════════════════════');
out("Done! {$total_new} new source(s) " . ($dry_run ? 'found (dry run)' : 'added') . ".", $total_new > 0 ? 'new' : 'ok');
?>
</div>

<div style="margin-top:16px;display:flex;gap:12px;flex-wrap:wrap">
  <?php if ($total_new > 0 && !$dry_run): ?>
    <a href="/contracts/import_transparency.php" class="govuk-button">Run import →</a>
  <?php endif; ?>
  <?php if (!$dry_run): ?>
    <a href="?dry" class="govuk-button govuk-button--secondary">Run as dry run</a>
  <?php endif; ?>
  <a href="/contracts/sources.php" class="govuk-button govuk-button--secondary">View sources</a>
</div>

<?php layout_foot(); ?>
