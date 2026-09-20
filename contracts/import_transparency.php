<?php // v2026-06-12-1
/**
 * import_transparency.php
 * Fetches CSV/XLSX spend data from councils, stores tech payments in ct_transparency_spend.
 * ?force  — clear and re-import all active sources
 * ?council=Name — limit to one council
 * ?period=YYYY-MM — limit to one period
 */
declare(strict_types=1);

if (!function_exists("str_contains")) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === "" || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists("str_starts_with")) {
    function str_starts_with(string $haystack, string $prefix): bool {
        return strncmp($haystack, $prefix, strlen($prefix)) === 0;
    }
}

if (!function_exists("str_ends_with")) {
    function str_ends_with(string $haystack, string $suffix): bool {
        return $suffix === "" || substr($haystack, -strlen($suffix)) === $suffix;
    }
}
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';

$cli = php_sapi_name() === 'cli';

if ($cli) {
    // CLI mode — no login required, plain text output
    function out(string $msg, string $cls = 'info'): void {
        echo '[' . date('H:i:s') . '] ' . strip_tags($msg) . "\n";
        flush();
    }
    // Parse CLI args: php import_transparency.php [--council=Name] [--period=YYYY-MM] [--force] [--shadow=TABLE]
    $opts = getopt('', ['council:', 'period:', 'force', 'shadow:']);
    $_GET['council'] = $opts['council'] ?? '';
    $_GET['period']  = $opts['period']  ?? '';
    if (isset($opts['force'])) $_GET['force'] = '1';
    $_GET['shadow']  = $opts['shadow'] ?? '';
} else {
    require_login();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no');
    echo '<html><head><style>
  body{font-family:monospace;font-size:13px;background:#1a1a1a;color:#d4d4d4;padding:1.5rem;white-space:pre-wrap}
  .ok{color:#4ec9b0}.err{color:#f87171}.info{color:#9cdcfe}.warn{color:#dcdcaa}
  a{color:#9cdcfe}
</style></head><body>';
    echo str_pad('', 1024) . "\n";
    echo '<div style="margin-bottom:16px"><a href="/contracts/transparency.php" style="color:#9cdcfe">← Back to Actual payments</a></div>';
    flush();
}

if (!$cli) {
    function out(string $msg, string $cls = 'info'): void {
        echo '<span class="' . $cls . '">[' . date('H:i:s') . '] ' . htmlspecialchars($msg) . '</span>' . "\n";
        ob_flush(); flush();
    }
}


// ── Supplier matching + IO/parse helpers extracted to lib/spend_import_lib.php (Phase 1 2026-09-19) ──
require_once __DIR__ . '/lib/spend_import_lib.php';

// ── Header-detection helpers ────────────────────────────────────────────────
// Shared plumbing for the per-council "find the header row, map columns by header
// text" blocks in the main loop. They capture ONLY the repetitive scaffolding;
// each council still passes its own tokens/predicate inline, and genuinely bespoke
// sites (multi-sheet probing, synthesised dates, data-cell disambiguation,
// case-sensitive or substring-guarded matching) keep their own logic.

/**
 * Up to $maxScan header-candidate rows as arrays of RAW cells, from parsed $rows
 * if present, else by splitting raw CSV $content on newlines and str_getcsv-ing
 * each line. Mirrors the `if ($rows !== null) … else …` idiom the call sites used.
 */
function header_candidate_rows(?array $rows, ?string $content, int $maxScan = 1): array {
    if ($rows !== null) return array_slice($rows, 0, $maxScan);
    if ($content === null || $content === '') return [];
    $out = [];
    foreach (explode("\n", $content) as $line) {
        if (count($out) >= $maxScan) break;
        $out[] = str_getcsv($line);
    }
    return $out;
}

/**
 * Index of the first row in $rows whose cells satisfy $pred, or null. $pred is
 * called with the row's cells NORMALISED (lowercased + trimmed).
 */
function find_header_row(array $rows, callable $pred): ?int {
    foreach ($rows as $i => $r) {
        if ($pred(array_map('strtolower', array_map('trim', $r)))) return $i;
    }
    return null;
}

/**
 * Map field columns by header-cell text. $header = raw header cells; $fieldTokens =
 * [fieldKey => [token, …]] matched by EXACT equality against normalised (lc+trim)
 * cells. Last matching column wins per field (reproduces the no-break inline loop).
 * Returns [fieldKey => colIndex] for matched fields only.
 */
function map_cols_by_name(array $header, array $fieldTokens): array {
    $hdr = array_map('strtolower', array_map('trim', $header));
    $res = [];
    foreach ($hdr as $i => $h) {
        foreach ($fieldTokens as $key => $tokens) {
            if (in_array($h, $tokens, true)) $res[$key] = $i;
        }
    }
    return $res;
}

/**
 * Convenience wrapper over map_cols_by_name(): assign the column vars by reference,
 * each only when its field matched (unmatched fields keep their current value; only
 * field keys present in $fieldTokens are ever written). Behaviour-identical to the
 * inline `$c = map_cols_by_name(...); if (isset($c['s'])) $col_s = $c['s']; ...` idiom.
 */
function apply_cols_by_name(&$col_s, &$col_a, &$col_d, &$col_v, array $header, array $fieldTokens): void {
    $c = map_cols_by_name($header, $fieldTokens);
    if (isset($c['s'])) $col_s = $c['s'];
    if (isset($c['a'])) $col_a = $c['a'];
    if (isset($c['d'])) $col_d = $c['d'];
    if (isset($c['v'])) $col_v = $c['v'];
}

/**
 * Like map_cols_by_name but each field's tokens carry a match MODE, for the header
 * loops that use str_contains/str_starts_with rather than exact equality. $spec =
 * [fieldKey => [[mode, token], …]] with mode ∈ 'exact'|'substr'|'prefix', matched
 * against lc+trim cells; empty cells skipped; first matching token per cell sets the
 * field, last matching COLUMN wins (reproduces the inline no-break foreach). Tokens
 * stay inline per council — deliberately NOT config/DB-driven.
 */
function map_cols_modes(array $header, array $spec): array {
    $hdr = array_map('strtolower', array_map('trim', $header));
    $res = [];
    foreach ($hdr as $i => $h) {
        if ($h === '') continue;
        foreach ($spec as $key => $conds) {
            foreach ($conds as [$mode, $tok]) {
                $m = $mode === 'exact'  ? ($h === $tok)
                   : ($mode === 'substr' ? str_contains($h, $tok)
                   : ($mode === 'prefix' ? str_starts_with($h, $tok) : false));
                if ($m) { $res[$key] = $i; break; }
            }
        }
    }
    return $res;
}

function apply_cols_modes(&$col_s, &$col_a, &$col_d, &$col_v, array $header, array $spec): void {
    $c = map_cols_modes($header, $spec);
    if (isset($c['s'])) $col_s = $c['s'];
    if (isset($c['a'])) $col_a = $c['a'];
    if (isset($c['d'])) $col_d = $c['d'];
    if (isset($c['v'])) $col_v = $c['v'];
}

function fetch_post_csv(string $url): ?string {
    $ua = 'Mozilla/5.0 (compatible; LGContractsDashboard/1.0)';
    $search_terms = [
        'SOFTCAT','CIVICA','PHOENIX SOFTWARE','NEC SOFTWARE','IDOX','CAPITA',
        'BYTES SOFTWARE','INSIGHT DIRECT','COMPUTACENTER','VIRGIN MEDIA',
        'MICROSOFT','DELL','ORACLE','VODAFONE','BT LIMITED','BRITISH TELECOM',
        'ACCESS UK','UNIT4','XMA','CDW','SCC','SPECIALIST COMPUTER',
        'MRI SOFTWARE','TRUSTMARQUE','NETCALL','BRIGHTLY','ZELLIS','TOTALMOBILE',
        'ARCUS GLOBAL','GRANICUS','LIQUIDLOGIC','HEYWOOD','AGILISYS',
        'GOSS INTERACTIVE','STONE TECHNOLOGIES','BOXXE','ESRI','CACI','VERSION 1','VERAN',
    ];
    $rows_csv = ['service_label,service_code,organisational_unit,expenditure_category,expenditure_code,payment_date,transaction_number,amount,supplier_name'];
    $seen = [];
    $max_pages_per_term = 10; // cap at 200 rows/term — the search is a broad keyword
                              // match (not just supplier name), so big terms like
                              // "PHOENIX SOFTWARE" can return 200+ mostly-irrelevant rows
    foreach ($search_terms as $term) {
        $norec = null;
        $cur = 1;
        for ($page = 0; $page < $max_pages_per_term; $page++) {
            usleep(400000);
            $body = 'strSearch=' . urlencode($term) . '&chrSearchType=1&blnMatchCase=false&strOB=&strOD=&blnShowAdvSearchForm=false';
            if ($cur > 1) $body .= '&intCurRow=' . $cur . '&NoRec=' . $norec;
            $ctx = stream_context_create(['http' => [
                'method'  => 'POST', 'timeout' => 30, 'user_agent' => $ua, 'ignore_errors' => true,
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n",
                'content' => $body,
            ]]);
            $html = @file_get_contents($url, false, $ctx);
            if (!$html || !preg_match('~<tbody>(.*?)</tbody>~si', $html, $tbm)) break;
            if ($norec === null && preg_match('~Displaying rows\s+\d+\s+to\s+\d+\s+of\s+(\d+)~si', $html, $nm)) {
                $norec = (int)$nm[1];
            }
            preg_match_all('~<tr[^>]*>(.*?)</tr>~si', $tbm[1], $rm);
            foreach ($rm[1] as $rh) {
                preg_match_all('~<td[^>]*>(.*?)</td>~si', $rh, $cm);
                $c = array_map(fn($x) => trim(html_entity_decode(strip_tags($x), ENT_QUOTES, 'UTF-8')), $cm[1] ?? []);
                if (count($c) < 9) continue;
                $c = array_slice($c, 0, 9);
                $txn = $c[6] ?? '';
                if ($txn && isset($seen[$txn])) continue;
                if ($txn) $seen[$txn] = true;
                $rows_csv[] = implode(',', array_map(fn($v) => str_contains($v, ',') || str_contains($v, '"') ? '"' . str_replace('"', '""', $v) . '"' : $v, $c));
            }
            $n = count($rm[1]);
            if ($n < 20) break; // last page
            $cur += 20;
            if ($norec !== null && $cur > $norec) break;
        }
    }
    return count($rows_csv) > 1 ? implode("\n", $rows_csv) : null;
}

// Dacorum Borough Council: webapps.dacorum.gov.uk/spendingdata/spendingdownload.aspx
// is an ASP.NET WebForms postback form, not a static file -- unlike Lancashire's
// post_csv (a keyword-search scraper with no period concept), Dacorum's form
// directly returns the real monthly CSV export once the right year+month are
// selected. Needs a genuine 2-step postback per period: (1) GET the form to
// harvest a fresh __VIEWSTATE/__VIEWSTATEGENERATOR/__EVENTVALIDATION triple,
// (2) POST with __EVENTTARGET=ctl00$MainContent$ddYYYY and the target year to
// trigger the dependent month-dropdown postback (refreshes the validation
// tokens and populates ctl00$MainContent$ddMMM with that year's real month
// list), (3) POST again with the target month plus
// ctl00$MainContent$Button1 to trigger the actual CSV download. Confirmed
// working and stable (8-col layout: Body Name, Body, Expense Type, Service
// Area Categorisation, Date, Transaction number, Amount, Supplier Name) across
// 2022-04 to 2026-05 by direct test, 2026-06-23.
function fetch_dacorum_csv(string $url, string $period): ?string {
    if (!preg_match('/^(\\d{4})-(\\d{2})$/', $period, $pm)) return null;
    [$year, $month] = [$pm[1], $pm[2]];
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';
    $jar = tempnam(sys_get_temp_dir(), 'dacorum_cj_');

    $grab = function (string $name, string $html): ?string {
        return preg_match('~name="' . preg_quote($name, '~') . '" id="' . preg_quote($name, '~') . '" value="([^"]*)"~', $html, $m) ? $m[1] : null;
    };
    $post = function (string $url, array $fields) use ($ua, $jar): ?array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => $ua,
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HEADER => true,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) { curl_close($ch); return null; }
        $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($resp, 0, $hsize);
        $body = substr($resp, $hsize);
        curl_close($ch);
        return ['headers' => $headers, 'body' => $body];
    };

    // Step 1: GET the form fresh.
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => $ua,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) { @unlink($jar); return null; }

    $vs = $grab('__VIEWSTATE', $html);
    $vsg = $grab('__VIEWSTATEGENERATOR', $html);
    $ev = $grab('__EVENTVALIDATION', $html);
    if ($vs === null || $ev === null) { @unlink($jar); return null; }

    // Step 2: select year -> dependent postback refreshes month dropdown + tokens.
    $r2 = $post($url, [
        '__EVENTTARGET' => 'ctl00$MainContent$ddYYYY', '__EVENTARGUMENT' => '', '__LASTFOCUS' => '',
        '__VIEWSTATE' => $vs, '__VIEWSTATEGENERATOR' => $vsg, '__EVENTVALIDATION' => $ev,
        'ctl00$MainContent$ddYYYY' => $year, 'ctl00$MainContent$ddMMM' => '12',
        'ctl00$FileCounter' => '0',
    ]);
    if ($r2 === null) { @unlink($jar); return null; }
    $vs2 = $grab('__VIEWSTATE', $r2['body']);
    $vsg2 = $grab('__VIEWSTATEGENERATOR', $r2['body']);
    $ev2 = $grab('__EVENTVALIDATION', $r2['body']);
    if ($vs2 === null || $ev2 === null) { @unlink($jar); return null; }

    // Step 3: select month + submit Button1 -> triggers the real CSV download.
    $r3 = $post($url, [
        '__EVENTTARGET' => '', '__EVENTARGUMENT' => '', '__LASTFOCUS' => '',
        '__VIEWSTATE' => $vs2, '__VIEWSTATEGENERATOR' => $vsg2, '__EVENTVALIDATION' => $ev2,
        'ctl00$MainContent$ddYYYY' => $year, 'ctl00$MainContent$ddMMM' => $month,
        'ctl00$FileCounter' => '0', 'ctl00$MainContent$Button1' => '',
    ]);
    @unlink($jar);
    if ($r3 === null) return null;
    if (!str_contains(strtolower($r3['headers']), 'text/csv')) return null;
    return $r3['body'] ?: null;
}

// Amber Valley Borough Council: info.ambervalley.gov.uk's "Spending over
// £500" page is a JS datatable backed by an ASMX web service, not a
// downloadable file. Unlike Dacorum's multi-step ASP.NET WebForms postback
// (fresh __VIEWSTATE per request) this is a single stateless POST --
// selectedYear/selectedMonth (no leading zero)/fileName -- to
// .../WebServices/AVBCFeeds/FinancialsJSON.asmx/CreateCSV, which returns the
// real monthly CSV directly (8-col layout: serviceCode, paymentDate,
// serviceLabel, transactionNumber, expenditureCode, expenditureCategory,
// netAmount, supplierName). Confirmed working and stable across 2022-04 to
// 2026-03 by direct test, 2026-06-24.
function fetch_amber_valley_csv(string $period): ?string {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $period, $pm)) return null;
    $year = $pm[1];
    $month = (string)(int)$pm[2]; // no leading zero
    $url = 'https://info.ambervalley.gov.uk/WebServices/AVBCFeeds/FinancialsJSON.asmx/CreateCSV';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'selectedYear' => $year, 'selectedMonth' => $month, 'fileName' => 'export',
        ]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LGContractsDashboard/1.0)',
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) { curl_close($ch); return null; }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    return $body !== '' ? $body : null;
}

// ── Row processor ───────────────────────────────────────────────────
// West Devon Borough Council date resolver -- see the "west devon" branch in
// process_spend_row() for the full rationale. The cumulative "PaymentsOver500"
// CSV mixes UK D/M/YYYY and US M/D/YYYY dates, with the format flipping per
// monthly upload batch (2025-07, 2026-03/04/05 are US) and ambiguous rows
// (both components <=12) sitting inside otherwise-US batches. Because the whole
// file is rescanned once per period source, a per-period format guess would
// double-count ambiguous strings across two adjacent months (e.g. "03/02/2026"
// claimed by 2026-02 as UK Feb-3 AND 2026-03 as US Mar-2). Instead resolve
// EVERY row's true date ONCE up front, using file order: each monthly batch is
// a contiguous block, so unambiguous rows (one component >12) fix that block's
// format and ambiguous rows inherit it (forward/backward fill, with a
// month-anchored tie-break at US<->UK block boundaries). Keyed by
// voucher|rawdate|amount so the per-row lookup is deterministic; the
// paid_date-in-period filter then buckets each row into exactly one month, so
// no row is ever counted twice. General to any council on the same finance
// system that publishes one cumulative CSV with per-batch date-format flips.
function wd_resolve_date(string $voucher, string $rawdate, string $amountraw,
    int $a, int $b, int $y, array $source): ?string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $url = (string)($source['url'] ?? '');
        $marker = '/contracts/';
        $pos = strpos($url, $marker);
        $path = $pos !== false ? __DIR__ . '/' . substr($url, $pos + strlen($marker)) : '';
        if ($path !== '' && is_file($path)) {
            $raw = (string)file_get_contents($path);
            if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
            $raw = str_replace(["\r\n", "\r"], ["\n", "\n"], $raw);
            $recs = [];
            $ln = 0;
            foreach (explode("\n", $raw) as $line) {
                if ($ln++ === 0) continue;            // header (skip_rows=1)
                if (trim($line) === '') continue;
                $r = str_getcsv($line);
                if (count($r) < 3) continue;
                $dr = trim((string)($r[0] ?? ''));
                if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $dr, $m)) continue;
                $ra = (int)$m[1]; $rb = (int)$m[2]; $ry = (int)$m[3];
                $fmt = null;                          // null = ambiguous (both <=12)
                if     ($ra > 12 && $rb <= 12) $fmt = 'UK';
                elseif ($rb > 12 && $ra <= 12) $fmt = 'US';
                elseif ($ra > 12 && $rb > 12)  $fmt = false; // impossible -> drop
                $recs[] = [
                    'k' => trim((string)($r[1] ?? '')) . '|' . $dr . '|' . trim((string)($r[2] ?? '')),
                    'a' => $ra, 'b' => $rb, 'y' => $ry, 'f' => $fmt,
                ];
            }
            $n = count($recs);
            // Forward/backward fill each ambiguous row's format AND the month of
            // the nearest unambiguous neighbour, so a US<->UK block boundary can
            // be resolved by whichever block's month the row actually matches.
            $ff = array_fill(0, $n, null); $fm = array_fill(0, $n, null);
            $bf = array_fill(0, $n, null); $bm = array_fill(0, $n, null);
            $cf = null; $cm = null;
            for ($i = 0; $i < $n; $i++) {
                if     ($recs[$i]['f'] === 'US') { $cf = 'US'; $cm = $recs[$i]['a']; }
                elseif ($recs[$i]['f'] === 'UK') { $cf = 'UK'; $cm = $recs[$i]['b']; }
                $ff[$i] = $cf; $fm[$i] = $cm;
            }
            $cf = null; $cm = null;
            for ($i = $n - 1; $i >= 0; $i--) {
                if     ($recs[$i]['f'] === 'US') { $cf = 'US'; $cm = $recs[$i]['a']; }
                elseif ($recs[$i]['f'] === 'UK') { $cf = 'UK'; $cm = $recs[$i]['b']; }
                $bf[$i] = $cf; $bm[$i] = $cm;
            }
            foreach ($recs as $i => $rec) {
                $f = $rec['f'];
                if ($f === false) continue;           // impossible date -> unmapped
                if ($f === null) {                    // ambiguous -> decide format
                    $pf = $ff[$i]; $nf = $bf[$i];
                    if     ($pf !== null && $pf === $nf) $f = $pf;
                    elseif ($pf !== null && $nf === null) $f = $pf;
                    elseif ($nf !== null && $pf === null) $f = $nf;
                    elseif ($pf !== null && $nf !== null) {
                        // US<->UK boundary: keep the row in the neighbouring block
                        // whose month it can actually match.
                        $prev_ok = (($pf === 'US' ? $rec['a'] : $rec['b']) === $fm[$i]);
                        $next_ok = (($nf === 'US' ? $rec['a'] : $rec['b']) === $bm[$i]);
                        if     ($prev_ok && !$next_ok) $f = $pf;
                        elseif ($next_ok && !$prev_ok) $f = $nf;
                        else                           $f = $pf; // default to prev block
                    } else {
                        $f = 'UK';                    // no anchors at all -> UK council
                    }
                }
                $mon = $f === 'US' ? $rec['a'] : $rec['b'];
                $day = $f === 'US' ? $rec['b'] : $rec['a'];
                if ($mon < 1 || $mon > 12 || $day < 1 || $day > 31) continue;
                $map[$rec['k']] = sprintf('%04d-%02d-%02d', $rec['y'], $mon, $day);
            }
        }
    }
    $key = $voucher . '|' . $rawdate . '|' . $amountraw;
    if (isset($map[$key])) return $map[$key];
    // Fallback: resolve this single row if it is itself unambiguous.
    if ($a > 12 && $b <= 12) return sprintf('%04d-%02d-%02d', $y, $b, $a);
    if ($b > 12 && $a <= 12) return sprintf('%04d-%02d-%02d', $y, $a, $b);
    return null;
}

function process_spend_row(array $row, int $col_s, int $col_a, int $col_d, int $col_v,
    int $col_cat, array $cat_filter, array $source, PDOStatement $insert, int &$tech_count): void
{
    // Cast all values to string to prevent type errors on null/empty cells
    $row = array_map(fn($v) => (string)($v ?? ''), $row);
    if ($col_cat >= 0 && !empty($cat_filter)) {
        $row_cat = strtolower(trim((string)($row[$col_cat] ?? '')));
        $pass = false;
        foreach ($cat_filter as $cf) {
            $cf = strtolower($cf);
            $pass = strlen($cf) <= 4 ? $row_cat === $cf : str_contains($row_cat, $cf);
            if ($pass) break;
        }
        if (!$pass) return;
    }

    $supplier_raw = sanitise_str(array_key_exists($col_s, $row) ? (string)$row[$col_s] : null);
    if (!$supplier_raw || strlen($supplier_raw) < 2) return;

    // London Borough of Enfield: "ACCESS UK LIMITED" (exact spelling, not
    // the separate, genuinely-small "ACCESS UK LTD" entries) is a recurring
    // ~£3M/month payment-intermediary line for Adult Social Care/Housing
    // placements routed through Access's care-commissioning platform, not
    // software licensing -- £138.58M across 126 records averaging £1.1M
    // each, flat every month from 2022-10 to 2026-03 (confirmed not tech
    // spend, 2026-06-21). Scoped to Enfield only -- "Access UK Limited" is
    // the genuine spelling used for plausible small-scale software spend
    // at many other councils (Lewisham, Ealing, Brent, Hertfordshire, etc).
    if (str_contains(strtolower($source['council']), 'enfield')
        && strtoupper(trim($supplier_raw)) === 'ACCESS UK LIMITED') {
        return;
    }

    // Kent County Council: Cantium Business Solutions (CH 11242115, SIC
    // 82110/82990 office admin/business support -- NOT software) is Kent's
    // arms-length shared-services company. Most of its spend is outsourced
    // commissioned services, building works, printing etc. booked under
    // generic expense types ("Commissioned Service - Other" alone is ~£54M)
    // -- NOT ICT. Only rows whose expense-type category (col_cat=9) names
    // Computer/ICT are genuine IT; skip the rest. Previously a one-off DB
    // script (kent_cantium_fp.php) that --force kept wiping -- baked into
    // code here so it survives re-import (2026-08-01). Keeps ~£8.2M genuine
    // IT, drops ~£58.5M non-ICT. Scoped to Cantium ONLY -- do NOT use a
    // blanket category_filter: Telent's £14.83M ITS spend is booked under
    // "Infrastructure"/"Private Contractors", not Computer/ICT, and would
    // be wrongly dropped.
    if ($col_cat >= 0
        && str_contains(strtolower($source['council']), 'kent')
        && stripos($supplier_raw, 'cantium') !== false) {
        $cantium_cat = strtolower((string)($row[$col_cat] ?? ''));
        if (strpos($cantium_cat, 'computer') === false
            && strpos($cantium_cat, 'ict') === false) {
            return;
        }
    }

    // Liverpool City Council miscodes several clearly non-tech suppliers into
    // the "Computing" expense category (col_cat=2, category_filter) under IT
    // Equipment / Software service lines, so the blanket category gate keeps
    // them despite no genuine tech content. All four are Liverpool-only
    // (confirmed no genuine tech spend at any other council, 2026-08-02):
    //   MILLBROOK HEALTHCARE LTD - community equipment (wheelchairs/beds),
    //     CH 00833987 SIC 86900 health (£5.39M/32 rows)
    //   LIVITY LIFE LTD - mobility/medical equipment retailer, CH 13968621
    //     SIC 47540/47741 (£3.10M/15 rows)
    //   RIVERSIDE - housing association (£2.05M/16 rows); "Riverside" is a
    //     collision-prone token (genuine "Riverside Education Ltd" tech spend
    //     exists at Gloucestershire) so scope to Liverpool, not a global pattern
    //   JGP RESOURCING LTD - recruitment agency, coded "Software (General)"
    //     (£0.14M/4 rows)
    // Previously DB-only validation_status='invalid' flags (FP audit 2026-07-31)
    // that --force wiped and resurrected -- baked into code so they survive
    // re-import (2026-08-02). Scoped to Liverpool ONLY.
    if (str_contains(strtolower($source['council']), 'liverpool')
        && in_array(strtoupper(trim($supplier_raw)), [
            'MILLBROOK HEALTHCARE LTD', 'LIVITY LIFE LTD',
            'RIVERSIDE', 'JGP RESOURCING LTD'], true)) {
        return;
    }

    // MLCS3 Limited is a buildings construction/repair/maintenance contractor,
    // NOT a tech supplier. It enters via category-filtered sources where a council
    // coded its housing "Voids & Repairs"/HRA works under an IT/tech category.
    // Confirmed FP 2026-09-10: 603 rows/GBP7.42M across 16 councils, zero genuine
    // IT service lines (raw name reads "Works - Construction, Repair & Maintenance
    // - Buildings"). Global exclusion so --force cannot resurrect it.
    if (str_contains(strtolower($supplier_raw), "mlcs3")) {
        return;
    }

    if ($col_cat >= 0 && !empty($cat_filter)) {
        $canon = match_supplier($supplier_raw) ?? $supplier_raw;
    } else {
        $canon = match_supplier($supplier_raw);
        if (!$canon) return;
    }

    // High Peak Borough Council: all "RICOH UK LTD" spend is managed print /
    // photocopier services booked under generic departmental service areas
    // (Central Services, HRA) -- not IT. 224 records / ~£48k, avg ~£215/payment,
    // no genuine IT lines. Previously a DB-only validation_status='invalid' fix
    // that --force wiped and resurrected -- baked into code here so it survives
    // re-import (2026-08-02). Scoped to High Peak ONLY -- Ricoh is genuine tech
    // (print/scan software, managed services) at many other councils.
    if ($canon === 'Ricoh'
        && str_contains(strtolower($source['council']), 'high peak')) {
        return;
    }

    // Skip suppliers explicitly marked confirmed_invalid in ct_supplier_patterns
    if (is_confirmed_invalid_supplier($canon)) return;

    $amount_raw_str = (string)($row[$col_a] ?? '0');
    // Harrow's Oct-Dec24 quarterly file (file/32499, 2024-07/08/09) has a
    // one-off export glitch: every amount column's decimal point was
    // exported as a space ("888361 71" instead of "888361.71"). The
    // generic sanitiser below strips spaces entirely, concatenating the
    // digits and inflating the value ~100x (caught as a single £106.6M
    // "Phoenix Software" payment, 2026-06-21). Restore the decimal point
    // before sanitising.
    if (str_contains(strtolower($source['council']), 'london borough of harrow')
        && preg_match('/^(-?\d+)\s(\d{1,2})$/', trim($amount_raw_str), $hm)) {
        $amount_raw_str = $hm[1] . '.' . $hm[2];
    }
    $raw_amt    = preg_replace('/[^0-9.,\-]/', '', $amount_raw_str);
    $amount_str = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw_amt));
    $amount     = (float)$amount_str;
    // Welsh Government amounts are negative debit entries; negate so the
    // amount > 0 check below passes and the stored value is positive.
    if (str_contains(strtolower($source['council']), 'welsh government') && $amount < 0) {
        $amount = abs($amount);
    }
    // Store negatives (credits, refunds, invoice reversals) so they net
    // against their matching positive in aggregation — previously they were
    // dropped, leaving orphaned positives as phantom spend nationally
    // (systemic bug, fixed 2026-07-30). Only exact-zero rows carry no signal.
    if ($amount == 0.0) return;

    $date = null;
    $date_raw = preg_replace('/^\p{Z}+|\p{Z}+$/u', '', (string)($row[$col_d] ?? ''));
    if ($date_raw) {
        if (is_numeric($date_raw) && (float)$date_raw > 40000 && (float)$date_raw < 60000) {
            $date = date('Y-m-d', ((int)floor((float)$date_raw) - 25569) * 86400);
            // West Sussex FY24-25 file source-side year typo: the ~20k
            // January payment rows were stamped 2024-01 instead of 2025-01
            // (exactly 366 days early -- a new-year fat-finger). The file
            // contains ZERO genuine 2025-01 serials and no legitimate
            // 2024-01 data (a FY24-25 file starts in April 2024), so any
            // 2024-01 date in wscc_spend_2024-25.xlsx is a misdated 2025-01
            // row. Bump it a year. Scoped to this exact file + period so the
            // real 2024-01 data in the FY23-24 file is never touched.
            if ($date !== null
                && strpos($date, '2024-01') === 0
                && ($source['period'] ?? '') === '2025-01'
                && str_contains(strtolower((string)$source['url']), 'wscc_spend_2024-25')) {
                $date = '2025' . substr($date, 4);
            }
        } elseif (str_contains(strtolower($source['council']), 'east sussex')
            && is_numeric($date_raw) && (float)$date_raw > 7000 && (float)$date_raw < 12000) {
            // East Sussex Q1/Q2 FY2025-26 (periods 2025-04 and 2025-07)
            // quarterly XLSX exports encode "Payment Date" as a numeric serial
            // on a 2000-01-01 epoch (numFmt 164 "dd/mm/yy"), NOT Excel's
            // standard 1900 epoch -- values are ~8000-10000 for 2022-2026 and
            // fall below the generic 40000-60000 serial guard above, leaving
            // $date null and dumping the entire quarter into its first month
            // (the same failure mode as East Devon). The 1900->2000 epoch
            // offset is exactly 36525 days; add it before the serial->date
            // conversion. Other ES quarters store dates as text (handled by
            // the generic format loop below) or as standard >40000 serials,
            // so this branch only ever fires for these two files.
            $date = date('Y-m-d', (((int)floor((float)$date_raw) + 36525) - 25569) * 86400);
        } elseif (preg_match('/^([A-Za-z]{3})-(\d{2})$/', trim($date_raw), $mmatch)) {
            // Month-year only, no day at all (e.g. Slough's "Period" column,
            // "Jun-23") -- DateTime::createFromFormat would silently fill the
            // missing day from today's real-world date (non-deterministic,
            // changes every time the importer runs), so resolve it
            // explicitly to the 1st of the month instead.
            $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
            $mon = $months[strtolower($mmatch[1])] ?? null;
            if ($mon) $date = sprintf('%04d-%02d-01', (int)$mmatch[2] + 2000, $mon);
        } elseif (str_contains(strtolower($source['council']), 'tameside') && preg_match('/^(\d{1,2})-([A-Za-z]{3})$/', trim($date_raw), $tmatch)) {
            // Tameside: "Paid Date" format varies per quarterly file --
            // dd-Mon (no year at all, e.g. "27-Apr"), dd/mm/yyyy, or
            // dd/mm/yy. The no-year form needs the year inferred, since
            // DateTime::createFromFormat has no token-less way to supply it
            // and would otherwise silently fall back to the current
            // real-world year (non-deterministic, same class of bug noted
            // for the generic Mon-YY case above). Each quarterly CSV is
            // shared by exactly 3 monthly $source rows (one process_spend_row
            // call per period, this file rescanned each time) and every
            // quarter's rows stay within that quarter's own months in
            // practice (no Dec/Jan-boundary spillover seen on inspection) --
            // safe to take the year straight from the *current* $source
            // period being processed: rows whose month doesn't match this
            // period get filtered out by the paid_date-in-period check below
            // regardless of what year is assigned here.
            $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
            $mon = $months[strtolower($tmatch[2])] ?? null;
            if ($mon) $date = sprintf('%04d-%02d-%02d', (int)substr($source['period'], 0, 4), $mon, (int)$tmatch[1]);
        } elseif (str_contains(strtolower($source['council']), 'tonbridge') && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($date_raw), $tmdm)) {
            // Tonbridge and Malling Borough Council: dates are in M/D/YYYY
            // format (US-style, e.g. "3/4/2025" = March 4, 2025). The generic
            // format loop tries j/n/Y (D/M/Y) before n/j/Y (M/D/Y), causing
            // month and day to be swapped for every row where day <= 12.
            $date = sprintf('%04d-%02d-%02d', (int)$tmdm[3], (int)$tmdm[1], (int)$tmdm[2]);
        } elseif (str_contains(strtolower($source['council']), 'west devon')
            && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($date_raw), $wddm)) {
            // West Devon: cumulative CSV mixing UK D/M/YYYY and US M/D/YYYY per
            // monthly batch (2025-07, 2026-03/04/05 are US-format), with some
            // ambiguous rows inside the US batches. wd_resolve_date() resolves
            // every row's true date ONCE from the file on disk using
            // contiguous-batch format detection, keyed by voucher|rawdate|amount,
            // so the paid_date-in-period filter buckets each row into exactly one
            // month with no cross-period double counting. See the function's
            // header comment for the full rationale.
            $date = wd_resolve_date(
                trim((string)($row[1] ?? '')), trim($date_raw),
                trim((string)($row[$col_a] ?? '')),
                (int)$wddm[1], (int)$wddm[2], (int)$wddm[3], $source);
        } elseif (str_contains(strtolower($source['council']), 'north ayrshire')
            && preg_match('/^(\d{4})\/(\d{2})\/(\d{2})/', trim($date_raw), $namatch)) {
            // North Ayrshire Council: ArcGIS Hub annual CSVs (FY22-23 to FY24-25)
            // export dates as "2022/04/08 00:00:00+00" -- slash-separated ISO
            // with a bare "+00" timezone suffix that doesn't match any PHP
            // DateTime::createFromFormat pattern in the generic loop below
            // (which expects dashes, or the "P"/"O" timezone tokens requiring
            // "+00:00"/"+0000"). The generic "Y/m/d" format also silently
            // matches but only on the date portion when tested without the
            // trailing time+tz, causing unpredictable results. Regex-extract
            // the date component directly instead.
            $date = sprintf('%04d-%02d-%02d', (int)$namatch[1], (int)$namatch[2], (int)$namatch[3]);
        } else {
            // 'd/m/y' must precede 'd/m/Y': createFromFormat('d/m/Y', '20/04/26')
            // succeeds leniently with year 0026 (rejected by the pre-2022 guard
            // below), so 2-digit-year dates must be tried first.
            // Dacorum Borough Council: dates come back as "2026-03-03 00:00:00"
            // (space-separated, not the 'T'-separated ISO variants already
            // below) -- every row failed every format above, leaving
            // paid_date NULL for all 3,572 rows and making the council
            // invisible in every FY-filtered view dashboard-wide despite
            // having a perfectly populated period field (2026-06-24, same
            // bug class as the Slough paid_date fix).
            foreach (['Y-m-d','Ymd','d/m/y','d/m/Y','d.m.Y','j/n/Y','n/j/Y','d-m-Y','Y/m/d','d-M-y','d-M-Y','j-M-y','j-M-Y','j M y','j M Y','j F Y','d/m/Y H:i','d/m/Y H:i:s','Y-m-d\TH:i:s.v','Y-m-d\TH:i:s','Y-m-d H:i:s'] as $dfmt) {
                $d = DateTime::createFromFormat($dfmt, trim($date_raw));
                if ($d) {
                    // createFromFormat is lenient about impossible calendar
                    // dates -- day 31 in a 30-day month, or day 30/31 in
                    // February -- and silently rolls them forward instead of
                    // failing (e.g. '31/04/2026' -> 2026-05-01). Caught via
                    // Cheshire East's FY2026-27 ArcGIS "EffectiveDate" column,
                    // which stamps every single row with the literal
                    // placeholder 31/04/2026: the roll-forward moved every
                    // row into the next month, so all 8,252 rows failed the
                    // paid_date-in-period check below and the whole source
                    // silently imported 0 rows (2026-08-13; fixed by pointing
                    // that one source's col_date at the genuine "PaymentDate"
                    // column instead). getLastErrors() still flags the
                    // overflow as a warning even though a DateTime object was
                    // returned, so use it to reject the roll-forward here and
                    // keep trying the remaining formats/branches -- same as
                    // any other unparseable date, $date is left null and the
                    // row falls through to the existing null-date handling
                    // (dropped by the paid_date-in-period check, or a
                    // per-council fallback) rather than being silently
                    // accepted with a fabricated date.
                    $errs = DateTime::getLastErrors();
                    if ($errs && ($errs['warning_count'] > 0 || $errs['error_count'] > 0)) continue;
                    $date = $d->format('Y-m-d');
                    break;
                }
            }
        }
    }

    // Chelmsford City Council: the 2022-11 "expenditure-over-gbp250" CSV
    // uniquely drops the Date column entirely (every other 2022-04..2022-12
    // file in the same "FORMAT CIAXLONE REPORT" era has Date as a normal
    // DD/MM/YYYY value in this position) -- the column at $col_d in this one
    // file is actually a duplicate of the Procurement Code text (e.g.
    // "HOMEPROV", "DIGIT8"), which fails every date format above and leaves
    // $date as null, so the row was inserted with paid_date NULL instead of
    // being bucketed/rejected (caught 2026-06-23: 7 genuine tech-supplier
    // rows -- Telefonica O2, Phoenix Software x3, Civica UK, Esri UK, Idox
    // Software -- all with paid_date NULL for this period only). No real
    // date is recoverable from this file, so fall back to the first of the
    // source's own period, the same convention used for Norfolk's
    // blank-"Payment date" XLSX rows above.
    if ($date === null && str_contains(strtolower($source['council']), 'chelmsford') && $source['period'] === '2022-11') {
        $date = $source['period'] . '-01';
    }

    // Payments before 2022 are out of scope for this dashboard, even when
    // they appear inside an otherwise in-scope source file/period.
    if ($date !== null && $date < '2022-01-01') return;

    // Norfolk and East Sussex both publish quarterly files with period
    // derived from paid_date per row (below). A handful of late-processed
    // payments can be dated before the file's quarter (e.g. a Jan/Mar 2022
    // payment inside the Apr-Jun 2022 report) -- these would otherwise
    // surface as their own pre-2022-04 period buckets, breaking the
    // 2022-04+ start date used for every council (#27/#28).
    // Councils whose files are quarterly/annual and must be bucketed by paid_date
    // (drop pre-2022-04 stragglers + fall back to period-start when the date is null).
    // Driven by the ct_council_config.quarterly_by_date flag (rides on $source via the
    // JOIN in the source SELECT), replacing the former ~10-council name allowlist
    // (Norfolk/Tendring/East Sussex/Wakefield/Oxfordshire/Eastbourne/Mid Sussex/Newark/
    // Elmbridge/North Ayrshire); per-council rationale preserved in
    // docs/quarterly_by_date_councils.txt. Phase 4 2026-09-19, proven behaviour-identical
    // (17,305/17,305 sources). See project_importer_rationalisation.
    $is_quarterly_by_date = !empty($source['quarterly_by_date']);
    if ($date !== null && $date < '2022-04-01' && $is_quarterly_by_date) return;

    // Norfolk's XLSX exports (2022-07, 2024-01 onwards) leave "Payment date"
    // blank for ~99% of rows ("Effective date" is a fixed report-generation
    // date, not a transaction date, so it can't be used either). Fall back
    // to the first day of the source's quarter so these rows still get a
    // usable paid_date and are bucketed under the right period.
    if ($date === null && $is_quarterly_by_date) {
        $date = $source['period'] . '-01';
    }

    // Wirral's December 2025 export uniquely omits the "Paid Date" column
    // entirely (every row shifted left by one -- confirmed by inspecting the
    // raw file, not just this row), so it can't be recovered; fall back to
    // the period start rather than leaving it NULL (a NULL paid_date makes a
    // row invisible in every FY filter dashboard-wide, the same bug found
    // and fixed for Slough).
    if ($date === null
        && str_contains(strtolower($source['council']), 'wirral')
        && $source['period'] === '2025-12') {
        $date = $source['period'] . '-01';
    }

    // East Hertfordshire District Council: weekly XLSX files merged into
    // monthly CSVs have no date column at all (col_date=-1); fall back to
    // the first of the period so paid_date is populated (a NULL paid_date
    // makes rows invisible in every FY-filtered dashboard view).
    if ($date === null && str_contains(strtolower($source["council"]), "east hertfordshire")) {
        $date = $source["period"] . "-01";
    }

    // Newcastle-Under-Lyme Borough Council: unusual format where col_date=-1
    // (no dedicated date column -- the date range is only in the period
    // descriptor at col 0, not per-row). Fall back to the first of the period
    // so paid_date is populated and rows appear in FY-filtered dashboard views.
    if ($date === null && str_contains(strtolower($source["council"]), "newcastle-under-lyme")) {
        $date = $source["period"] . "-01";
    }

    // Leicester City Council, Wigan Council, Birmingham City Council, West
    // Sussex County Council, Surrey County Council, East Riding of
    // Yorkshire Council, Hammersmith & Fulham and the London Borough of
    // Merton each publish one file covering many months (calendar year /
    // financial year / rolling ~2-year window / financial year /
    // financial-year quarter / financial-year quarter / financial-year
    // quarter / financial year respectively); one ct_spend_sources row
    // exists per month (all sharing the same file:// URL, or for
    // Surrey/East Riding/Hammersmith/Merton the same annual or quarterly
    // file URL across its constituent months) so sources.php shows correct
    // per-month counts. Keep only rows whose paid_date falls in this source
    // row's period -- otherwise every month's row would be re-inserted once
    // per source row sharing the file (many-x duplication). West Sussex's
    // final source (Dec 2025) is actually a standalone single-month CSV, but
    // the filter is harmless there too since every row already falls in
    // that one month. Merton's "Payment Date" is reliably populated across
    // all 5 annual files (unlike Norfolk's XLSX gap), so the simple
    // paid_date-in-period filter is sufficient -- no null-date fallback
    // needed, and period stays as the source row's period (not derived from
    // date) since Merton's files are annual, not quarterly. North Yorkshire's
    // quarterly XLSX/CSV exports (one file per FY quarter, 3 monthly source
    // rows each) follow the same pattern -- PAYMENT_DATE (text, e.g.
    // "18-APR-2023") is populated for all but a handful of rows per quarter.
    // Cheshire East's ArcGIS Open Data export is one CSV per financial year
    // (4 distinct files for FY22/23..FY25/26) with 12 monthly source rows
    // each sharing that FY's URL -- $col_d (EffectiveDate, detected by name
    // below) is a clean month-end label ("30/04/2022" etc, 12 distinct
    // values per FY file), reliable for this same paid_date-in-period filter.
    // Cheshire West and Chester's quarterly XLSX exports (one file per FY
    // quarter, 3 monthly source rows each, same pattern as North
    // Yorkshire/East Riding) need the same filter -- "Pay Date" (col_d=7,
    // an Excel serial date, consistent across every FY22/23..FY25/26
    // quarter checked) is populated per-row throughout the quarter.
    // London Borough of Brent's quarterly CSV exports (one per rolling
    // 3-month period, 3 monthly source rows each) follow the same pattern --
    // "Payment Date" (col_d=0) is populated for every row.
    // South Gloucestershire: 20 of its 50 periods (2024-04 to 2025-03 share
    // one 12-month cumulative file; 2025-04..06, 2025-07..09, 2025-10..11
    // each share one quarterly file) need the same per-row date filter --
    // "Transaction Date"/"Date" (the per-era col_d set above) is populated
    // for every row in all four shared files.
    // Council of the Isles of Scilly: every period shares one quarterly
    // XLSX (3 monthly rows per file) across all eras -- "Payment Date" (the
    // per-era col_d set below) is populated per-row throughout each quarter.
    // City of York Council: one cumulative CSV per calendar year, 12 monthly
    // source rows share it -- without this filter, the whole year's rows
    // landed in every one of that year's months (caught 2026-06-21 after an
    // accidental unscoped import run, ~12x-inflated totals).
    // Halton Borough Council: quarterly CSV ("Payments Over £500 Qn
    // YYYYYY"), 3 monthly source rows per file -- "Date Transaction Added"
    // (col_d=0, consistent across every era including the per-row Q2
    // FY2024-25 override) is populated per-row throughout each quarter.
    // Without this filter the whole quarter's rows landed in all 3 months
    // (caught 2026-06-21, ~3x-inflated totals during the same import that
    // surfaced the Q2 FY2024-25 column-override bug).
    // Oldham Council: quarterly XLSX ("Q1/Q2/.. Over £500 Report"), 3
    // monthly source rows per file (16 distinct files across 48 periods) --
    // "Payment Date" (col_d=6, Excel serial, generically converted above)
    // is populated per-row throughout each quarter.
    // Blackburn with Darwen Borough Council: one annual CSV per FY
    // ("Expenditure YYYY-YY.csv"), 12 monthly source rows per file --
    // "Date expenditure occurred" (col_d=0) is populated per-row throughout
    // the year. Site also has a broken cert chain (missing intermediate),
    // so the 5 distinct FY files are hosted locally under
    // /contracts/data/blackburn/ rather than fetched live (2026-06-21).
    // North Somerset Council: one annual XLSX per FY (sheet "250 Spend",
    // consistently sheet index 2, selected via #sheet=2 on the URL), 12
    // monthly source rows per file, with stray rows outside the named FY --
    // "TransactionDate" (col_d=0, Excel serial) is populated per-row.
    // London Borough of Harrow: quarterly XLSX ("Council Spend over 500"),
    // 3 monthly source rows per file (16 distinct files across 48 periods,
    // one mislabeled "Sep-Dec" instead of "Oct-Dec") -- "Date" (col_d=6) is
    // populated per-row throughout each quarter.
    // Middlesbrough Council: ArcGIS Hub CSV, monthly files through 2023-03
    // then quarterly from 2023-04 onwards (39 of 51 periods share one of 13
    // quarterly files, 3 monthly source rows each) -- "Payment Date" (col_d=4)
    // is populated per-row throughout every quarter checked.
    // Torbay Council: live transparency tool is a stateful multi-page POST
    // form (Logi rdPage AP_500_Report), not fetchable automatically -- onboarded
    // via 4 manually-downloaded rolling 12-month CSV exports (2022-06..2026-05),
    // 12 monthly source rows per file, hosted locally under /contracts/data/torbay/.
    // "Date" (col_d=15, DD/MM/YYYY) is populated per-row throughout each file.
    // 2022-04/05 are not covered by any export (genuine gap, no source available).
    // Wokingham Borough Council: one cumulative CSV/XLSX per financial year
    // (45 monthly source rows share one of 4 FY files), "Pay Date" (col_d=0,
    // DD/MM/YYYY) populated per-row throughout each FY file -- without this
    // filter the whole year's rows would land in every one of that year's
    // months (same bug class as York/Halton/Oldham/Blackburn).
    // North East Lincolnshire Council: two cumulative multi-year CSVs share
    // all 49 periods (see col-override block above) -- "Date" (col_d=12 or
    // 11 depending on file, per-row override) is populated per-row
    // throughout each file's date range.
    // Tameside Council: quarterly CSV ("Q1/Q2/.. upload"), 3 monthly source
    // rows per file (same pattern as Halton/Oldham above) -- "Paid Date"
    // (col_d=5, with the no-year dd-Mon format resolved against the current
    // period above) is populated per-row throughout each quarter.
    // Huntingdonshire District Council: the Oct-Dec22 xlsx and every
    // 2025-04-onward "FORMAT XLONE REPORT" quarterly file (3 monthly source
    // rows each) share one file per quarter -- "Paid Date" (Era B) is
    // populated per-row, and the synthetic Period-derived date (Era C/D,
    // built above in the per-council column-override block) is designed
    // specifically to be bucketed by this filter.
    // Charnwood Borough Council: quarterly CSV, 3 monthly source rows per
    // file, "PaymentDate" (col_d=10, static across the whole range) is
    // populated per-row throughout each quarter.
    // Wealden District Council: one annual "Supplier Payments" xlsx per FY
    // (4 distinct files across 2022-04..2026-03), 12 monthly source rows
    // share each file -- "Paid Date" (col_d=15, per-source DB override,
    // static across all 4 years) is populated per-row throughout each FY;
    // without this filter the whole year's rows would land in every one of
    // that year's months (same bug class as York/Halton/Oldham/Blackburn/
    // Wokingham above).
    // Oxford City Council: quarterly CSV ("Expenditure over £500"), 3
    // monthly source rows per file (16 distinct files across 48 periods) --
    // "Paid Date" (col_d=4 pre-2024-Q2, 6 from 2024-Q2, per-source DB
    // override above) is populated per-row throughout each quarter.
    // Royal Borough of Windsor and Maidenhead: 3 annual cumulative CSVs
    // ("Supplier Payments ... >= £100" for FY2022-23, FY2023-24, FY2024-25),
    // 12 monthly source rows share each file -- "Payment Date" (col_d=8,
    // static across all 3 years and the later monthly-from-2025-04 files
    // too) is populated per-row throughout each FY; without this filter the
    // whole year's rows would land in every one of that year's months (same
    // bug class as York/Halton/Oldham/Blackburn/Wokingham/Wealden above).
    // The monthly files from 2025-04 onward are already single-period (one
    // file per month) but each contains a handful of stray rows just
    // outside the named month (same harmless-filter pattern as North
    // Somerset above), so applying the filter to every period is safe.
    // North Tyneside Council: several ODS exports cover 2-3 months each
    // (MayJun25.ods, JulSep25.ods, OctDec25.ods), one monthly source row per
    // covered month sharing the file -- "Payment Date" (col_d=0) is
    // populated per-row throughout each file; without this filter every
    // covered month showed the file's full combined total (caught
    // 2026-06-24: 2025-05/06, 2025-07/08/09 and 2025-10/11/12 were each
    // identical within their group).
    // Runnymede Borough Council: one pair of periods (2025-03, 2025-04) shares
    // a single combined "March to April 2025" file -- the per-row date filter
    // also runs harmlessly across Runnymede's other (single-file-per-period)
    // months, same pattern as North Somerset/West Sussex above.
    // Bracknell Forest Borough Council: quarterly XLSX ("Payments over £500"),
    // 3 monthly source rows share each file across the full 2022-04..2026-03
    // range (16 files) -- "Date" (col_d=6, an Excel serial date, static
    // throughout) is populated per-row in every quarter checked.
    // Thanet District Council: from 2025-05 onwards quarterly CSV exports
    // (QTR1 May-Jun, QTR2 Jul-Sep, QTR3 Oct-Dec, QTR4 Jan-Mar), 2-3 monthly
    // source rows share each file -- "Paid Date" (col_d=1) is populated
    // per-row throughout each quarter.
    if (str_contains(strtolower($source['council']), 'windsor and maidenhead')
        // Erewash Borough Council: quarterly "Over 500" bundle (2026-04 onward),
        // 3 monthly source rows share the one file, bucketed by the "Month"
        // column (col_d=6 set in the per-council override above). Without this
        // filter the whole quarter's rows land in all 3 months (3x inflation).
        || str_contains(strtolower($source['council']), 'erewash')
        || str_contains(strtolower($source['council']), 'runnymede')
        || str_contains(strtolower($source['council']), 'bracknell forest')
        || str_contains(strtolower($source['council']), 'wokingham')
        || str_contains(strtolower($source['council']), 'tameside')
        || str_contains(strtolower($source['council']), 'huntingdonshire')
        || str_contains(strtolower($source['council']), 'charnwood')
        || str_contains(strtolower($source['council']), 'north east lincolnshire')
        || str_contains(strtolower($source['council']), 'leicester city')
        || str_contains(strtolower($source['council']), 'middlesbrough')
        || str_contains(strtolower($source['council']), 'torbay')
        || str_contains(strtolower($source['council']), 'halton')
        || str_contains(strtolower($source['council']), 'oldham')
        || str_contains(strtolower($source['council']), 'blackburn')
        || str_contains(strtolower($source['council']), 'north somerset')
        || str_contains(strtolower($source['council']), 'london borough of harrow')
        || str_contains(strtolower($source['council']), 'wigan')
        || str_contains(strtolower($source['council']), 'birmingham')
        || str_contains(strtolower($source['council']), 'west sussex')
        || str_contains(strtolower($source['council']), 'surrey')
        || str_contains(strtolower($source['council']), 'east riding')
        || str_contains(strtolower($source['council']), 'hammersmith')
        || str_contains(strtolower($source['council']), 'merton')
        || str_contains(strtolower($source['council']), 'north yorkshire')
        || str_contains(strtolower($source['council']), 'cheshire east')
        || str_contains(strtolower($source['council']), 'cheshire west')
        || str_contains(strtolower($source['council']), 'london borough of brent')
        || str_contains(strtolower($source['council']), 'south gloucestershire')
        || str_contains(strtolower($source['council']), 'southampton')
        || str_contains(strtolower($source['council']), 'kensington and chelsea')
        || str_contains(strtolower($source['council']), 'bath and north east somerset')
        || str_contains(strtolower($source['council']), 'sunderland')
        || str_contains(strtolower($source['council']), 'city of york')
        || str_contains(strtolower($source['council']), 'isles of scilly')
        || str_contains(strtolower($source['council']), 'wealden')
        || str_contains(strtolower($source['council']), 'oxford city')
        || str_contains(strtolower($source['council']), 'maidstone')
        || str_contains(strtolower($source['council']), 'north tyneside')
        || str_contains(strtolower($source['council']), 'burnley')
        || str_contains(strtolower($source['council']), 'west lancashire')
        || str_contains(strtolower($source['council']), 'exeter city')
        || str_contains(strtolower($source['council']), 'cambridge city')
        || str_contains(strtolower($source['council']), 'south hams')
        || str_contains(strtolower($source['council']), 'broxtowe')
        || str_contains(strtolower($source['council']), 'woking')
        || str_contains(strtolower($source['council']), 'west devon')
        || str_contains(strtolower($source['council']), 'rutland')
        || str_contains(strtolower($source['council']), 'south derbyshire')
        || str_contains(strtolower($source['council']), 'waverley')
        || str_contains(strtolower($source['council']), 'tandridge')
        || str_contains(strtolower($source['council']), 'oadby')
        || str_contains(strtolower($source['council']), 'harlow')
        || str_contains(strtolower($source['council']), 'north norfolk')
        || str_contains(strtolower($source['council']), 'mansfield')
        || str_contains(strtolower($source['council']), 'east staffordshire')
        || str_contains(strtolower($source['council']), 'epping forest')
        || str_contains(strtolower($source['council']), 'eastleigh')
        || str_contains(strtolower($source['council']), 'north east derbyshire')
        || str_contains(strtolower($source["council"]), "thanet")
        || str_contains(strtolower($source["council"]), "crawley")
        // Midlothian Council: one annual XLSX per FY (Apr-Mar), 12 monthly
        // source rows share each file -- "Date Paid" (col_d=1, Excel serial,
        // generically converted above) is populated per-row throughout each
        // FY file; without this filter the whole year's rows would land in
        // every one of that year's months (same bug class as Wokingham/
        // Blackburn/Wealden above).
        || str_contains(strtolower($source["council"]), "midlothian")
        // Angus Council: semi-annual XLSX files (one file covers 6 calendar
        // months; 6 monthly source rows share the same file URL). The Oct23-
        // Mar24 bulk export (resource 559136e7) is shared by 2023-10 and
        // 2024-04 source rows only. "Payment Date" (col_d=2 for most eras,
        // col_d=1 for 2025-10 -- both Excel serial, converted generically
        // above) is populated per-row throughout every file. Without this
        // filter, each file's full 6-month dataset would land in every one
        // of the source rows sharing it (same bug class as Midlothian above).
        || str_contains(strtolower($source["council"]), "angus council")
        // Monmouthshire County Council: two multi-period XLSX files cover
        // 2022-04..2023-03 (annual, 12 monthly source rows) and 2023-04..2023-07
        // (quarterly, 4 monthly source rows) -- "Invoice Date" (col_d=8, Excel
        // serial, generically converted above) is populated per-row throughout
        // both files; without this filter every period sharing a file would
        // receive the entire file's rows (12x and 4x duplication). From
        // 2023-08 onwards files are single-period CSVs; the filter is harmless
        // there too since all rows fall within the named month anyway.
        || str_contains(strtolower($source["council"]), "monmouthshire")
        // St Albans City Council: quarterly files (one file per FY quarter,
        // 3 monthly source rows share each file) across the full range --
        // "Payment Date" (col_d=7, Excel serial, generically converted above)
        // is populated per-row throughout each quarter. Without this filter
        // every quarter's rows land in all 3 months (3x inflation confirmed
        // 2026-07-06 -- pre-existing bug, now fixed with force reimport).
        // The Jan-Mar 2025 file has a different 11-col layout with Payment
        // Date at col5 (per-source override); the filter is still correct
        // since paid_date is populated per-row. The .xls files (Jul-Sep 2025
        // and Jan-Mar 2026) also use col_d=7 and have real date values.
        || str_contains(strtolower($source["council"]), "st albans")
        // Clackmannanshire Council: single annual XLSX for FY 2025-26
        // (14 monthly source rows share the same URL, covering 2025-04 to
        // 2026-05 including a small Apr/May 2026 overspill). "Payment Date"
        // (col_d=2, Excel serial, generically converted above) is populated
        // per-row throughout the file; without this filter all 66k rows would
        // land in every one of the 14 months (14x duplication).
        || str_contains(strtolower($source["council"]), "clackmannanshire")
        // Derry City and Strabane District Council: single XLSX
        // covering 2022-04..2026-03, 48 monthly source rows share the one file
        // -- "Trans.date" (col_d=6, dd/mm/yyyy) is populated per-row throughout;
        // without this filter all 28k rows would land in every one of the 48
        // months (48x duplication). ~270 pre-2022-04 accrual rows fall outside
        // every named period and are harmlessly dropped (same as the backfill
        // cut-off convention). Same pattern as Clackmannanshire/Wealden above.
        || str_contains(strtolower($source["council"]), "derry city and strabane")
        // Newport City Council: quarterly XLSX ("Q1 April to June 2022.xlsx"
        // etc.), 3 monthly source rows share each file across 2022-04..2023-12
        // -- "DATE" (col_d=1, Excel serial) is populated per-row throughout
        // each quarter; without this filter every quarter's rows land in all
        // 3 of its months (3x inflation). Same pattern as Wealden/Oxford/
        // Clackmannanshire above.
        || str_contains(strtolower($source["council"]), "newport city")
        // Bolsover District Council: one annual cumulative CSV per FY (6
        // distinct files across 2022-04..2026-05, ~12 monthly source rows
        // share each file) -- "Date" (Layout A col_d=12; Layout B doc 15597
        // col_d=9) is populated per-row throughout each FY. Without this
        // filter every month sharing a file received the whole file's rows
        // (~11x duplication, £47M vs true ~£4M; caught 2026-07-12 as an
        // analysis-page outlier). Same bug class as York/Wokingham/Wealden.
        || str_contains(strtolower($source["council"]), "bolsover")
        // Hartlepool Borough Council: quarterly XLSX ("Q1/Q2/Q3/Q4 YYYY-YYYY",
        // 16 distinct files across 48 periods, 3 monthly source rows share
        // each quarter file) -- "Date" (col_d=5, Excel serial, converted
        // generically above) is populated per-row throughout each quarter.
        // Without this filter every month sharing a file received the whole
        // quarter's rows (~3x duplication, £64.6M vs true ~£21.5M; caught
        // 2026-07-12 -- NEC's triplicated £180k/£119k rows). Same bug class
        // as Halton/Oldham/Harrow above.
        || str_contains(strtolower($source["council"]), "hartlepool")
        // Batch added 2026-07-12 after a dashboard-wide duplication audit found
        // these councils publishing shared quarterly/annual files (multiple
        // monthly source rows per URL) that were duplicating on import (~3x
        // each; same bug class as Halton/Oldham/York above). All have a
        // per-row date column populated throughout, so the period filter
        // correctly buckets each row into its own month. Existing duplicate
        // rows were removed in the same audit.
        || str_contains(strtolower($source["council"]), "winchester")
        || str_contains(strtolower($source["council"]), "chichester")
        || str_contains(strtolower($source["council"]), "nuneaton")
        || str_contains(strtolower($source["council"]), "ashford")
        || str_contains(strtolower($source["council"]), "rother district")
        || str_contains(strtolower($source["council"]), "babergh")
        || str_contains(strtolower($source["council"]), "rushcliffe")
        || str_contains(strtolower($source["council"]), "gedling")
        || str_contains(strtolower($source["council"]), "ashfield")
        || str_contains(strtolower($source["council"]), "rossendale")
        // Lewes District Council: quarterly files (Q1-Q4), 3 monthly source rows
        // share each file. Without this filter all 3 months landed in the first
        // period bucket (e.g. Oct held Oct+Nov+Dec), leaving the other 2 periods
        // empty — 8 populated periods instead of ~18. "Date" (col_d=4 or 6) is
        // populated per-row throughout each quarter, so the filter buckets each
        // row into its own month. Caught 2026-07-13. Same class as Hartlepool/Bolsover.
        || str_contains(strtolower($source["council"]), "lewes district")
        // East Devon (annual file, 12 months in one bucket, 445/492 rows mis-bucketed)
        // and Lancaster City (quarterly files) — same shared-file bug, caught in the
        // 2026-07-13 audit alongside Lewes. Both have valid per-row Date columns.
        || str_contains(strtolower($source["council"]), "east devon")
        || str_contains(strtolower($source["council"]), "lancaster city")
        // Argyll and Bute Council: one XLSX per financial
        // year (2022-23..2026-27), converted to one CSV each; 49 monthly source
        // rows (2022-06..2026-06) share these 5 files. "Payment Date" (col_d=2)
        // is populated per-row throughout every FY, so the filter buckets each
        // row into its own month. Without it the whole year would land in one
        // bucket (same bug class as York/Blackburn/East Devon above).
        || str_contains(strtolower($source["council"]), "argyll and bute")
        // Renfrewshire Council: single CSV covering 48 periods
        // (2022-04..2026-03); per-row Expense Date populated throughout.
        || str_contains(strtolower($source["council"]), "renfrewshire council")
        // Perth and Kinross Council: single CSV covering 52 periods
        // (2022-04..2026-07); per-row Payment Date populated throughout.
        || str_contains(strtolower($source["council"]), "perth and kinross")
        // Aberdeen City Council: five annual CSVs (one per
        // accounting year 2022-23..2026-27, hosted under /contracts/data/aberdeen/),
        // 12 monthly source rows share each FY file (4 for the part-year 2026-27).
        // "Date Paid" (col_d=6, DD/MM/YYYY) is populated per-row throughout each FY;
        // each file also carries a small next-FY overspill of late-paid rows, so
        // without this filter the whole year's rows (plus overspill) would land in
        // every one of that year's months (same bug class as York/Blackburn/Argyll).
        || str_contains(strtolower($source["council"]), "aberdeen city")
        // Merthyr Tydfil County Borough Council: single XLSX (one
        // sheet per calendar year 2018-2026) split into five annual CSVs
        // (2022..2026, hosted under /contracts/data/merthyr/); 12 monthly
        // source rows share each calendar-year file (7 for the part-year 2026).
        // "Date" (col_d=0, YYYY-MM-DD) is populated per-row throughout each
        // year, so without this filter the whole year's rows would land in
        // every one of that year's months (same bug class as York/Aberdeen).
        || str_contains(strtolower($source["council"]), "merthyr tydfil")
        // Belfast City Council: single XLSX covering 2022-04..2026-07, 41
        // monthly source rows share the one file -- "Payment Date" (col_d=1,
        // openpyxl datetime) is populated per-row throughout; without this
        // filter all 21k rows would land in every one of the 41 months (41x
        // duplication). Same pattern as Derry/Merthyr above.
        || str_contains(strtolower($source["council"]), "belfast city")
        // Mid Ulster District Council: single XLSX (midulster_spend.xlsx)
        // covering 2023-04..2026-04, ~37 monthly source rows share the one
        // file. The 7 date-range tabs are MERGED into one array upstream (see
        // the "Mid Ulster" merge block in the XLSX-parse section), so every
        // period's source row sees the whole 3-year dataset; without this
        // filter all ~16k rows would land in every one of the ~37 months.
        // "Date" (col_d=2, Excel serial, converted generically above) is
        // populated per-row throughout, so the filter buckets each row into
        // its own month -- and correctly captures rows that "bleed" across tab
        // boundaries (a payment for a month is kept regardless of which tab it
        // physically sat on). Same pattern as Belfast/Derry above.
        || str_contains(strtolower($source["council"]), "mid ulster")
        // Gwynedd Council: single XLSX (gwynedd_spend.xlsx) covering
        // 2022-04..2026-03 (134,922 rows), 48 monthly source rows all share
        // the one file -- "Date Paid" (col_d=5, Excel datetime) is populated
        // per-row throughout; without this filter all rows would land in
        // every one of the 48 months (48x duplication).
        || str_contains(strtolower($source["council"]), "gwynedd council")
        // Tewkesbury Borough Council: 2023 combined bi-monthly CSVs (Jan+Feb,
        // Apr+May, Jul+Aug -- 3 files, each shared by 2 monthly source rows).
        // "Date" (col_d=4, DD/MM/YYYY) is populated per-row throughout; without
        // this filter each combined file's full 2-month dataset would land in both
        // period buckets (2x duplication). Single-period files from 2023-10 onward
        // are harmlessly passed through the filter (all rows fall within the named month).
        || str_contains(strtolower($source["council"]), "tewkesbury borough")
        // East Lothian Council: single XLSX (198k rows) covering
        // 2022-04..2026-05, 51 monthly source rows all share the file.
        || str_contains(strtolower($source["council"]), "east lothian council")
        // West Dunbartonshire Council: single XLSX
        // (sheet "Spend Info FY21-26", 94k rows) covering 2022-04..2026-04,
        // 48 monthly source rows (2022-04..2026-03) all share the one file.
        // "Date" (col_d=1) is populated per-row (mixed Excel serial + dd/mm/yy
        // text, both handled by the generic date parser above); without this
        // filter all 85k dated rows would land in every one of the shared
        // months (48x duplication). ~8 late Apr-2026 overspill rows fall
        // outside every named period and are harmlessly dropped. Same pattern
        // as East Lothian/Derry/Gwynedd above.
        || str_contains(strtolower($source["council"]), "west dunbartonshire")
        // East Dunbartonshire Council: four annual
        // XLSX (one per FY 2022-23..2025-26, hosted under
        // files/east_dunbartonshire/). Payment dates bleed across FY-file
        // boundaries (a payment made in the next FY appears in either file as a
        // DISTINCT row -- verified ~0 cross-file duplicate rows), so each FY
        // file is registered for EVERY calendar month it contains rows for,
        // disambiguated by the department column (FY2223/FY2324/FY2425/FY2526);
        // the cross-file overlap months hold complementary (non-duplicate) rows.
        // "Payment Date" (col_d=2, mixed Excel datetime serial + DD/MM/YYYY
        // text, both handled by the generic parser above) is populated per-row
        // throughout each file, so this filter buckets each row into its own
        // month and no bled row is lost or double-counted. Same pattern as West
        // Dunbartonshire/Carmarthenshire above.
        || str_contains(strtolower($source["council"]), "east dunbartonshire")
        // Dumfries and Galloway Council: single XLSX->CSV
        // (89,549 rows) covering 2022-04..2026-07, 52 monthly source rows all
        // share the one file. "Paid Date" (col_d=3, yyyymmdd string, parsed by
        // the generic 'Ymd' format above) is populated per-row throughout;
        // without this filter all rows would land in every one of the 52 shared
        // months (52x duplication). Same pattern as East Lothian/Gwynedd above.
        || str_contains(strtolower($source["council"]), "dumfries and galloway")
        // Fermanagh and Omagh District Council: single XLSX
        // with 4 financial-year tabs (Apr22-Mar23 .. Apr25-Mar26) combined
        // upstream into one CSV (local_files/fermanagh/), 48 monthly source
        // rows all share the one file. The date column is the INVOICE
        // transaction date (col_d=1, converted to ISO YYYY-MM-DD during CSV
        // build), which spills across FY boundaries within each payment-FY
        // tab -- so bucket every row by its own invoice month rather than by
        // the tab it sat on. Without this filter all ~17k rows would land in
        // every one of the 48 shared months (48x duplication). ~600 pre-2022-04
        // old-invoice accrual rows fall outside every named period and are
        // harmlessly dropped (standard backfill cut-off). Same pattern as
        // Derry/Belfast/Mid Ulster above.
        || str_contains(strtolower($source["council"]), "fermanagh and omagh")
        // Ards and North Down Borough Council: partial coverage --
        // single FY2023-24 CSV (local_files/ards/), 12 monthly source rows
        // (2023-04..2024-03) all share the one file. "Invoice Paid" (col_d=2,
        // DD/MM/YYYY) is populated per-row throughout; without this filter all
        // ~18.7k rows would land in every one of the 12 shared months (12x
        // duplication). Same pattern as Fermanagh/Derry/Belfast above.
        || str_contains(strtolower($source["council"]), "ards and north down")
        // Royal Borough of Kingston upon Thames: publishes a single rolling
        // "payments over £500 - to be published" CSV covering every month not
        // yet formally published (May-Jul 2026 pending as of the last import at
        // 2026-04); 3 monthly source rows (2026-05..2026-07) share this one
        // file. "Date" (col_d=1, DD/MM/YYYY) is populated per-row throughout,
        // so bucket each row into its own month. Without this filter the whole
        // multi-month backlog would land in every shared period (same bug class
        // as Ards/Derry above). Older stray rows (pre-2026-05) fall outside all
        // three named periods and are harmlessly dropped.
        // Stirling Council: ArcGIS Hub open-data payments-to-suppliers feature
        // services (one per financial year, exported to CSV under
        // /contracts/data/stirling/), 12 monthly source rows share each FY file.
        // "Date" (col_d=2, ISO YYYY-MM-DD) is synthesized during export from the
        // source Period (1=April) + Financial_Year_Ending; year-end-adjustment
        // Period 13 and unclassified NULL-period rows are folded into that FY March
        // bucket. Without this filter the whole FY would land in every one of that
        // year 12 months (same bug class as Aberdeen/Argyll above).
        || str_contains(strtolower($source["council"]), "stirling")
        || str_contains(strtolower($source["council"]), "kingston upon thames")
        // Flintshire County Council: over-500 spend, 4 self-hosted
        // FY CSVs, ~52 monthly source rows share the 4 files.
        // "Payment Date" (col_d=0, YYYYMMDD) is populated per-row; bucket each row
        // into its own month. Without this every FY file would land in all its shared
        // months. Same pattern as Ards/Stirling/Kingston above.
        || str_contains(strtolower($source["council"]), "flintshire")
        // Carmarthenshire County Council: over-500 spend, one
        // self-hosted 5-sheet xlsx (files/carmarthenshire/) with a financial
        // year per worksheet, selected per source via the #sheet=N URL
        // fragment; 52 monthly source rows (2022-04..2026-07) share the file.
        // "Period (Payment Date)" (col_d=3, Excel serial, converted generically
        // above) is populated per-row; bucket each row into its own month.
        // Without this every FY sheet would land in all 12 (or, sheet5, 4) of
        // its shared months. Same pattern as Flintshire/Ards/Stirling above.
        || str_contains(strtolower($source["council"]), "carmarthenshire")
        // Ceredigion County Council: single
        // self-hosted xlsx (files/ceredigion/, ~276k invoice-LINE
        // rows) covering 2022-04..2026-08; 53 monthly source rows all share the
        // one file. "GL_Date" (col_d=2, Excel serial, converted generically
        // above) is populated per-row; bucket each row into its own month.
        // Without this filter every row would land in all 53 shared months
        // (53x duplication). Line-level import is intentional (amount is
        // Inv_Line_Goods_Amount col 3, NOT the invoice total col 4) -- do not
        // dedup. Same pattern as Carmarthenshire/Gwynedd above.
        || str_contains(strtolower($source["council"]), "ceredigion")) {
        if ($date === null || substr($date, 0, 7) !== $source['period']) return;
    }

    // Havant Borough Council: from 2025-04 onward the council switched from
    // monthly single-period XLSX files to QUARTERLY files (one file per FY
    // quarter, 3 monthly source rows share each file URL). The quarterly
    // layout also differs from the monthly one -- the real payment value is
    // "Inclusive Amount" (col 7), not the monthly "Line Value" (col 8), and
    // the per-row date to bucket on is "Payment Date" (col 9, Excel serial),
    // not "Invoice Date" (col 6). Per-source col_amount=7/col_date=9 are
    // recorded in ct_spend_sources for these periods and applied via the
    // per-source override block below; here we additionally drop rows whose
    // payment month falls outside this source row's period so the shared
    // quarterly file isn't triple-counted across its 3 months (same pattern
    // as Bracknell Forest / St Albans above). Scoped to >= 2025-04 so the
    // monthly era (2022-04..2025-03), whose single-period files legitimately
    // carry a handful of stray invoice-dated rows just outside the named
    // month, is left untouched.
    if (str_contains(strtolower($source['council']), 'havant')
        && $source['period'] >= '2025-04') {
        if ($date === null || substr($date, 0, 7) !== $source['period']) return;
    }

    // Tendring 2026-03 top-up: source 9190 ("Copy of April to February 2026.xlsx")
    // captured the FY25-26 rolling file at February, so March 2026 -- the final
    // month -- was never imported (single-month gap between 2026-02 and 2026-04).
    // The complete "April to March 2026.xlsx" now exists on legacy, but importing
    // it wholesale would duplicate the 11 months 9190 already holds AND clobber
    // their FP surgery. Instead a dedicated 2026-03 source points at the complete
    // file; keep ONLY its March rows here so the overlap months are dropped and no
    // --force can ever re-duplicate them. Scoped to period 2026-03 so 9190
    // (2025-04) and 18058 (2026-04) are unaffected.
    if (str_contains(strtolower($source['council']), 'tendring')
        && $source['period'] === '2026-03') {
        if ($date === null || substr($date, 0, 7) !== $source['period']) return;
    }

    $service = $col_v >= 0 ? mb_strimwidth(sanitise_str(array_key_exists($col_v, $row) ? (string)$row[$col_v] : null) ?? '', 0, 200, '…') : null;
    $category = $col_cat >= 0 ? mb_strimwidth(sanitise_str(array_key_exists($col_cat, $row) ? (string)$row[$col_cat] : null) ?? '', 0, 200, '…') : null;

    // Skip rows whose service description is unambiguously non-IT (loaded from ct_service_keywords)
    if ($service !== null) {
        static $svc_kw_cache = null;
        if ($svc_kw_cache === null) {
            global $pdo;
            $svc_kw_cache = $pdo->query("SELECT keyword FROM ct_service_keywords WHERE ruling='invalid' ORDER BY LENGTH(keyword) DESC")->fetchAll(PDO::FETCH_COLUMN);
        }
        $svc_l = strtolower($service);
        foreach ($svc_kw_cache as $kw) {
            if (str_contains($svc_l, $kw)) return;
        }
    }

    // Skip rows excluded for a specific supplier+service combination
    if ($canon !== null && is_supplier_service_excluded($canon, $service, $category)) return;

    // Serco and AtkinsRealis hold huge general outsourcing/engineering
    // contracts with councils (Serco: waste, leisure, highways, children's
    // services case management, "strategic partnership" back-office;
    // AtkinsRealis: highways/flood-defence/planning engineering consultancy)
    // — only a small fraction (if any) is genuinely IT/tech. Version 1 is a
    // genuine ERP/IT company but also has a small tail of departmental
    // recharge lines under non-IT cost centres. Capita holds huge back-office/
    // customer-services BPO contracts at several councils (Barnet "One Barnet",
    // Lambeth, Westminster, Sheffield's "transformation partnership", Kent,
    // West Sussex, Birmingham, Devon etc.) recorded under generic department
    // cost-centre labels ("Customer and Place", "Deputy Chief Executive's
    // Department", "Business Information Services") rather than IT-specific
    // ones — 89% of Capita's recorded total (£272M of £306M) carried no
    // IT-relevant label. Delt Shared Services Ltd (Plymouth City Council's
    // own wholly-owned ICT/digital company) follows the same pattern —
    // 75% of its recorded total at Plymouth (£39.8M of £53.2M) sits under
    // the generic "Other Agency & Contracted Services" category, dwarfing
    // its clearly IT-coded lines (Computer Hardware/Software/Licensing/
    // Maintenance, ~£2.5M combined) — confirmed not genuinely tech spend,
    // 2026-06-21. Agilisys runs North Somerset's outsourced "Shared Services
    // Partnership" (back-office, customer contact, business support, plus
    // recharged work for partner orgs under "SSP - Trading - WECA/Sandwell/
    // Harrow/Rainford") in addition to its ICT/transformation work — 54% of
    // its recorded total at North Somerset (£36.1M of £66.6M) sat under
    // non-IT SSP/trading categories, confirmed 2026-06-21. For all six, keep
    // only rows whose service/cost-centre description looks IT-related.
    // Hoople Ltd / Hoople Group Ltd is Herefordshire Council's own wholly-owned
    // shared-services company (finance, HR, payroll, care commissioning, schools
    // DSG, highways recharges) -- only its "Business Systems" line is genuine IT.
    // At Herefordshire ALL £96.5M of Hoople spend sits under non-IT cost centres
    // (Strategic Finance, All Ages Commissioning, Schools, Learning Disabilities
    // etc.), dwarfing any IT line; at Lincolnshire the genuine "Business Systems"
    // IT line (£3.0M) sits alongside a small non-IT tail. Same shared-services
    // pattern as Delt (Plymouth) and Agilisys (North Somerset) -- keep only rows
    // whose service/cost-centre description looks IT-related (ruled 2026-07-12,
    // baked as durable code fix 2026-08-02 replacing the DB-only invalid flags).
    if (in_array($canon, ['Serco', 'AtkinsRealis', 'Version 1', 'Capita', 'Delt Shared Services', 'Agilisys', 'Hoople'], true)) {
        $svc_lower = strtolower($service ?? '');
        $is_it = (bool)preg_match('/\b(it|ict)\b/', $svc_lower)
            || str_contains($svc_lower, 'software')
            || str_contains($svc_lower, 'technology')
            || str_contains($svc_lower, 'digital')
            || str_contains($svc_lower, 'computer')
            || str_contains($svc_lower, 'computing')
            || str_contains($svc_lower, 'information system')
            || str_contains($svc_lower, 'erp')
            || str_contains($svc_lower, 'business systems');

        // West Dunbartonshire scoped carve-out (2026-08-04): WD's file has
        // col_service=-1 (Cost Centre is free-text narrative — a global
        // col_service filter there would drop every OTHER supplier's rows,
        // the known landmine), so $service is null and the generic IT-test
        // above binned ALL Capita rows. But WD's raw Cost Centre field
        // (row index 3) cleanly separates genuine ICT (£2.18M: "Information
        // services" £1.13M, "ICT Security & Data Recovery" £909k, SIP
        // telephony) from Capita-as-payment-conduit money that MUST stay
        // binned (£1.25M: "Cost of Living Capital Fund", cultural "Capital
        // funding", "Capital Valuation Joint Board", accountancy). Read the
        // cost centre directly from $row for WD+Capita only and allow just
        // the genuine-IT signatures — NOT via col_service (no global filter).
        if ($canon === 'Capita'
            && str_contains(strtolower($source['council']), 'west dunbartonshire')) {
            $cc = strtolower(implode(' ', array_map('strval', $row)));
            $is_it = str_contains($cc, 'information services')
                || str_contains($cc, 'ict security')
                || str_contains($cc, 'sip project')
                || str_contains($cc, 'session initi')          // "Session Initial Protocol (SIP)"
                || str_contains($cc, 'payment card industry')
                || str_contains($cc, 'cash receipting');
        }

        if (!$is_it) return;

        // Even when the service text mentions IT/ICT, some Serco lines are
        // ICT-flavoured components of a non-IT operational outsourcing
        // contract (e.g. Lambeth's "ICT Maintenance and Support" sits under
        // "Highways, Environment & FM" — the same division as Serco's
        // recurring highways BPO contract, not a corporate IT contract).
        // Exclude rows whose row data places them in an operational
        // service area.
        if (in_array($canon, ['Serco', 'AtkinsRealis'], true)) {
            $row_lower = strtolower(implode(' ', $row));
            $is_operational = str_contains($row_lower, 'highways')
                || str_contains($row_lower, 'leisure')
                || str_contains($row_lower, 'waste')
                || str_contains($row_lower, 'facilities management');
            if ($is_operational) return;
        }
    }

    // "Access UK Ltd" is also Suffolk's highways/infrastructure contractor —
    // £216.9M across 185 records, avg £930k/payment, paid roughly fortnightly
    // under directorate "Growth, Highways and Infrastructure" — a completely
    // different business from The Access Group's software products despite
    // the identical supplier name (same pattern as Haringey's "Access UK Ltd
    // T/A Adam" above, but here the supplier name is identical to genuine
    // Access Group payments, so exclude by council + directorate instead).
    if ($canon === 'The Access Group'
        && str_contains(strtolower($source['council']), 'suffolk')
        && str_contains(strtolower($service ?? ''), 'highways')) {
        return;
    }

    // Same name collision at Sutton: "Access UK Limited" under supplier
    // category "TAXI AND COACH HIRE" is a transport/taxi contractor, not
    // The Access Group's software business — £27.8M/171 records, avg
    // £163k/payment, ~85% of Sutton's apparent tech spend before this
    // exclusion (2026-06-21).
    if ($canon === 'The Access Group'
        && str_contains(strtolower($source['council']), 'sutton')
        && str_contains(strtolower($service ?? ''), 'taxi')) {
        return;
    }

    // At Essex, "XMA LTD"/"XMA LIMITED" payments come through a general
    // office-supplies punch-out catalogue covering IT hardware, furniture,
    // stationery, printing, clothing, building repairs etc across every
    // department (~25,000 records, far more than any peer council) — not a
    // dedicated IT contract. Only the "IT COSTS" service-coded lines
    // (~10,000 records, ~£972k) represent genuine tech spend; the rest
    // (furniture, printing/stationery, repairs, communications, clothing
    // etc) are general procurement via the same catalogue.
    if ($canon === 'XMA' && str_contains(strtolower($source['council']), 'essex')) {
        if (strtolower(trim($service ?? '')) !== 'it costs') return;
    }

    // Strata Service Solutions is East Devon/Exeter's shared IT company.
    // Non-IT service lines routed through Strata are excluded.
    // "Strata Contract Payment" rows are kept but flagged as internal_provider
    // so they are excluded from headline IT spend totals.
    $internal_provider = 0;
    if ($canon === 'Strata Service Solutions') {
        $svc_l = strtolower($service ?? '');
        if (str_contains($svc_l, 'agency staff')
            || str_contains($svc_l, 'consultant')
            || str_contains($svc_l, 'corporate complaint')
            || str_contains($svc_l, 'grant') && str_contains($svc_l, 'payable')) {
            return;
        }
        if ($svc_l === 'strata contract payment') {
            $internal_provider = 1;
        }
    }

    // Civica UK runs non-IT managed services alongside its software products.
    // Civica Election Services (formerly Electoral Reform Services) is a physical
    // print/mail/ballot-scanning operation — not IT. School meals and agency staff
    // rows are also excluded.
    if ($canon === 'Civica UK') {
        $svc_l = strtolower($service ?? '');
        $raw_l = strtolower($supplier_raw ?? '');
        if (str_contains($raw_l, 'election service')
            || str_contains($raw_l, 'electoral reform')
            || str_contains($raw_l, 'civica electoral')
            || (str_contains($raw_l, 'civica') && str_contains($raw_l, ' ers'))
            || str_contains($raw_l, 'civica - ers')) {
            return;
        }
        if (str_contains($svc_l, 'school meal')
            || str_contains($svc_l, 'agency staff')) {
            return;
        }
        // Physical postal dispatch and election canvass printing are not IT
        $council_l = strtolower($source['council'] ?? '');
        if (str_contains($council_l, 'oldham') && str_contains($svc_l, 'postage')) {
            return;
        }
        if ((str_contains($council_l, 'sefton') && str_contains($svc_l, 'elector') && str_contains($svc_l, 'print'))
            || (str_contains($council_l, 'hertsmere') && str_contains($svc_l, 'canvass print'))) {
            return;
        }
    }

    try {
        $insert->execute([
            ':council'           => $source['council'],
            ':lad_code'          => $source['lad_code'] ?? null,
            ':supplier_raw'      => mb_strimwidth($supplier_raw, 0, 300, '…'),
            ':supplier_canon'    => $canon,
            ':internal_provider' => $internal_provider,
            ':service'           => $service ?: null,
            ':category'          => $category ?: null,
            ':amount'            => round($amount, 2),
            ':paid_date'         => $date,
            // Norfolk and East Sussex publish quarterly files (one source
            // row covers 3 calendar months); derive the per-row period from
            // paid_date (with the null-date fallback above) so the period
            // filter and anomaly screening stay at monthly granularity
            // instead of bucketing everything under the quarter's first month.
            ':period'            => $is_quarterly_by_date
                ? substr($date, 0, 7)
                : $source['period'],
            ':source_id'         => $source['id'],
        ]);
        $tech_count++;
    } catch (Throwable $e) { /* skip duplicates */ }
}

// ── Database setup ────────────────────────────────────────────────────────
$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS ct_transparency_spend (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    council       VARCHAR(200)  NOT NULL,
    supplier_raw  VARCHAR(300)  NOT NULL,
    supplier_canon VARCHAR(200) NOT NULL,
    service       VARCHAR(200)  NULL,
    amount        DECIMAL(14,2) NOT NULL,
    paid_date     DATE          NULL,
    period        VARCHAR(7)    NOT NULL,
    source_id     INT UNSIGNED  NULL,
    fetched_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_council  (council),
    INDEX idx_supplier (supplier_canon),
    INDEX idx_period   (period),
    INDEX idx_date     (paid_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS ct_spend_sources (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    council     VARCHAR(200) NOT NULL,
    department  VARCHAR(100) NOT NULL DEFAULT '',
    url         TEXT         NOT NULL,
    period      VARCHAR(7)   NOT NULL,
    format      ENUM('csv','xlsx','post_csv') NOT NULL DEFAULT 'csv',
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    last_fetched DATETIME    NULL,
    last_count  INT          NULL,
    encoding    VARCHAR(20)  NOT NULL DEFAULT 'utf8',
    notes       TEXT         NULL,
    UNIQUE KEY uq_council_dept_period (council(150), department(50), period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS ct_council_config (
    council         VARCHAR(200) PRIMARY KEY,
    encoding        VARCHAR(20)  NOT NULL DEFAULT 'utf8',
    col_supplier    TINYINT      NOT NULL DEFAULT 0,
    col_amount      TINYINT      NOT NULL DEFAULT 1,
    col_date        TINYINT      NOT NULL DEFAULT 2,
    col_service     TINYINT      NOT NULL DEFAULT -1,
    skip_rows       TINYINT      NOT NULL DEFAULT 1,
    col_category    TINYINT      NOT NULL DEFAULT -1,
    category_filter VARCHAR(200) NOT NULL DEFAULT '',
    notes           VARCHAR(300),
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

try { $pdo->exec("ALTER TABLE ct_spend_sources MODIFY COLUMN format ENUM('csv','xlsx','post_csv') NOT NULL DEFAULT 'csv'"); } catch(Throwable $e) {}
try { $pdo->exec("ALTER TABLE ct_spend_sources ADD COLUMN department VARCHAR(100) NOT NULL DEFAULT '' AFTER council"); } catch(Throwable $e) {}
try { $pdo->exec("ALTER TABLE ct_spend_sources DROP INDEX uq_council_period, ADD UNIQUE KEY uq_council_dept_period (council(150), department(50), period)"); } catch(Throwable $e) {}
try { $pdo->exec("ALTER TABLE ct_council_config ADD COLUMN col_category TINYINT NOT NULL DEFAULT -1 AFTER skip_rows"); } catch(Throwable $e) {}
try { $pdo->exec("ALTER TABLE ct_council_config ADD COLUMN category_filter VARCHAR(200) NOT NULL DEFAULT '' AFTER col_category"); } catch(Throwable $e) {}
try { $pdo->exec("ALTER TABLE ct_council_config ADD COLUMN quarterly_by_date TINYINT NOT NULL DEFAULT 0"); } catch(Throwable $e) {}
try { $pdo->exec("ALTER TABLE ct_council_config ADD COLUMN use_source_cols TINYINT NOT NULL DEFAULT 0 AFTER category_filter"); } catch(Throwable $e) {}

$pdo->exec("INSERT IGNORE INTO ct_council_config (council,encoding,col_supplier,col_amount,col_date,col_service,skip_rows,notes) VALUES
    ('West Sussex County Council','utf8',7,8,5,1,1,'Col 7=supplier, 8=total, 5=date, 1=service'),
    ('West Berkshire Council','utf8',5,4,3,0,1,'Col 5=supplier, 4=net amount, 3=date (Excel serial), 0=service'),
    ('London Borough of Ealing','utf8',14,12,10,4,1,'Col 14=Amended Supplier Name, 12=Net Amount, 10=Date, 4=Service Label'),
    ('Cambridgeshire County Council','utf8',4,3,1,6,1,'Col 4=Supplier Name, 3=Amount, 1=Date Paid, 6=Expense Area'),
    ('Lancashire County Council','utf8',8,7,5,2,1,'Col 8=supplier_name, 7=amount, 5=payment_date, 2=organisational_unit'),
    ('Manchester City Council','utf16le',5,6,3,1,1,'Col 5=Supplier Name, 6=Net Amount, 3=Invoice Payment Date, 1=Service Area'),
    ('Amber Valley Borough Council','utf8',7,6,1,2,1,'Col 7=supplierName, 6=netAmount, 1=paymentDate (DD/MM/YYYY), 2=serviceLabel; ASMX post_csv source')
");
$pdo->exec("UPDATE ct_council_config SET col_category=2, category_filter='ICT' WHERE council='Gloucestershire County Council'");
$pdo->exec("UPDATE ct_council_config SET col_supplier=2, col_amount=8, col_date=7, col_service=1, skip_rows=1 WHERE council='Buckinghamshire Council'");
$pdo->exec("UPDATE ct_council_config SET col_supplier=2, col_amount=4, col_date=3, col_service=5, skip_rows=2, col_category=9, category_filter='' WHERE council='Kent County Council'");

// ── Load sources ──────────────────────────────────────────────────────────
$force          = isset($_GET['force']);
$filter_council = $_GET['council'] ?? '';
$filter_period  = $_GET['period']  ?? '';
// Debug: show raw GET values
if (!empty($filter_council) || !empty($filter_period)) {
    out("Filters: council='" . htmlspecialchars($filter_council) . "' period='" . htmlspecialchars($filter_period) . "'");
}
$limit          = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 0;

// ── Shadow-table mode (regression testing for the importer rationalisation) ──
// --shadow=ct_transparency_spend_<suffix> redirects EVERY write (row inserts,
// the --force pre-wipe, and the summary reads) to a clone table, so a re-import
// can be diffed against live via importer_goldenmaster.php WITHOUT touching
// production rows or the ct_spend_sources last_fetched/last_count metadata.
// Inert when the flag is absent: $SPEND_TABLE stays 'ct_transparency_spend'.
$SPEND_TABLE = 'ct_transparency_spend';
$SHADOW_MODE = false;
if (!empty($_GET['shadow'])) {
    if (!preg_match('/^ct_transparency_spend_[a-z0-9_]+$/', $_GET['shadow'])) {
        out("Refused: bad shadow table name '" . htmlspecialchars($_GET['shadow']) . "'", 'err');
        exit;
    }
    $SPEND_TABLE = $_GET['shadow'];
    $SHADOW_MODE = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$SPEND_TABLE}` LIKE ct_transparency_spend");
    out("SHADOW MODE: writing to {$SPEND_TABLE} — live data and source metadata untouched", 'warn');
}

$where_clauses = $force ? 'WHERE s.active = 1' : "
    WHERE s.active = 1 AND s.last_count IS NULL";

$sql = "SELECT s.*, COALESCE(s.lad_code, cc.lad_code) as lad_code, COALESCE(cc.quarterly_by_date,0) AS quarterly_by_date FROM ct_spend_sources s LEFT JOIN ct_council_config cc ON cc.council = s.council {$where_clauses}"
     . ($filter_council ? " AND s.council = " . $pdo->quote($filter_council) : "")
     . ($filter_period  ? " AND s.period  = " . $pdo->quote($filter_period)  : "")
     . " ORDER BY s.council, s.period ASC"
     . ($limit ? " LIMIT {$limit}" : "");

try {
    $sources = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    out("SQL error: " . $e->getMessage(), 'err');
    out("SQL: " . htmlspecialchars($sql), 'warn');
    exit;
}

if (empty($sources)) {
    if (!$force && $filter_council) {
        $any = $pdo->query("SELECT COUNT(*) FROM ct_spend_sources WHERE active=1 AND council=" . $pdo->quote($filter_council))->fetchColumn();
        if ($any > 0) {
            out("All {$any} active source(s) for " . htmlspecialchars($filter_council) . " are already imported — nothing new to fetch.", 'ok');
            exit;
        }
    }
    out("No active sources found.", 'warn');
    out("SQL used: " . htmlspecialchars($sql), 'warn');
    out('Go to <a href="/contracts/sources.php">sources.php</a> to add sources.');
    exit;
}

out("Found " . count($sources) . " active sources to process" . ($force ? " (force re-import)" : " (incremental)"));

if ($force) {
    if ($filter_period !== '') {
        // --force + --period is a documented footgun: the council-wide wipe
        // below deletes EVERY period's rows, but the period-filtered $sources
        // SELECT (above) then only reinserts the one filtered period, leaving
        // the council with a single surviving month. This is the confirmed
        // root cause of the West Sussex data loss on 2026-08-03: a
        // --force --period=2025-01 verification run of the Jan-2025 year-typo
        // fix wiped all 44 other periods, leaving only 2025-01 (87 rows).
        // When a period filter is present, scope the pre-import delete to ONLY
        // the source rows actually being reprocessed, by source_id. Deleting
        // by source_id is the correct scope for quarterly/annual multi-period
        // files too: every row a source produced carries that source's id,
        // regardless of how its per-row period was derived (so a quarterly
        // source's 3 constituent months are all cleared and cleanly reinserted).
        $ids = array_values(array_filter(array_column($sources, 'id'), fn($i) => $i !== null));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM `{$SPEND_TABLE}` WHERE source_id IN ($in)")->execute($ids);
            out("Cleared existing rows for source_ids " . implode(',', $ids) . " (period-scoped force, siblings preserved)");
        }
    } else {
        $to_clear = array_unique(array_column($sources, 'council'));
        foreach ($to_clear as $c) $pdo->prepare("DELETE FROM `{$SPEND_TABLE}` WHERE council=?")->execute([$c]);
        out("Cleared existing data for: " . implode(', ', $to_clear));
    }
}

$insert = $pdo->prepare("INSERT INTO `{$SPEND_TABLE}`
    (council,lad_code,supplier_raw,supplier_canon,internal_provider,service,category,amount,paid_date,period,source_id,fetched_at,validation_status,validation_notes,validated_at)
    VALUES (:council,:lad_code,:supplier_raw,:supplier_canon,:internal_provider,:service,:category,:amount,:paid_date,:period,:source_id,NOW(),NULL,NULL,NULL)");

$update_src = $pdo->prepare("UPDATE ct_spend_sources SET last_fetched=NOW(), last_count=:count WHERE id=:id");

$grand_rows = 0;
$grand_tech = 0;
$current_council = '';

foreach ($sources as $source) {
    if ($source['council'] !== $current_council) {
        $current_council = $source['council'];
        out("\n── {$current_council} ──", 'warn');
    }

    out("  {$source['period']}: fetching " . basename(parse_url($source['url'], PHP_URL_PATH)) . "…");

    // Fetch
    $fmt = strtolower($source['format'] ?? 'csv');
    if ($fmt === '' && str_contains(strtolower($source['council']), 'lancashire')) $fmt = 'post_csv';

    // Some XLSX sources point at a single workbook with one month per sheet
    // (e.g. Essex's quarterly "Day to day spending" files: sheet1/2/3 = the
    // 3 months of the quarter, sheet4 = Field Descriptions). A URL fragment
    // "#sheet=N" selects worksheet N (1-based); the fragment is stripped
    // before fetching since it's not part of the actual request.
    $fetch_url_str = $source['url'];
    $sheet_num = 1;
    $frag = parse_url($fetch_url_str, PHP_URL_FRAGMENT);
    if ($frag && preg_match('/sheet=(\d+)/', $frag, $fm)) {
        $sheet_num = (int)$fm[1];
        $fetch_url_str = strtok($fetch_url_str, '#');
    }

    if ($fmt === 'post_csv') {
        if (($prev_fmt ?? '') === 'post_csv') usleep(2000000);
        // Dacorum's post_csv source is a genuine year+month ASP.NET WebForms
        // postback (see fetch_dacorum_csv above) -- distinct from Lancashire's
        // keyword-search post_csv scraper, which takes no period argument.
        // Amber Valley's post_csv source is a stateless single-POST ASMX
        // service call (see fetch_amber_valley_csv above) -- simpler than
        // both, no form tokens or multi-step postback required.
        if (str_contains(strtolower($source['council']), 'dacorum')) {
            $raw = fetch_dacorum_csv($fetch_url_str, $source['period']);
        } elseif (str_contains(strtolower($source['council']), 'amber valley')) {
            $raw = fetch_amber_valley_csv($source['period']);
        } else {
            $raw = fetch_post_csv($fetch_url_str);
        }
    } else {
        // Basingstoke and Deane's server sends an incomplete TLS chain
        // (missing intermediate cert, confirmed via openssl s_client) --
        // a misconfiguration on their end, not a sign of tampering. No
        // Wayback fallback exists, so peer verification is skipped for
        // this one council only; every other council keeps full verification.
        $verify_peer = !str_contains(strtolower($source['council']), 'basingstoke') && !str_contains(strtolower($source['council']), 'tendring') && !str_contains(strtolower($source['council']), 'staffordshire moorlands');
        $raw = fetch_url($fetch_url_str, $verify_peer);
    }
    $prev_fmt = $fmt;

    if ($raw === null) {
        out("  ✗ Failed to fetch — check URL", 'err');
        // Diagnostic: try fetching and show what we got
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [CURLOPT_URL => $fetch_url_str, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_ENCODING => '']);
            $test = curl_exec($ch);
            $info = curl_getinfo($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            out("    HTTP: " . ($info['http_code'] ?? '?') . " | Size: " . strlen((string)$test) . " | Error: " . ($err ?: 'none'), 'warn');
            if ($test) out("    Start: " . htmlspecialchars(substr($test, 0, 80)), 'warn');
        }
        continue;
    }

    $content = decode_content($raw);

    // Luton: encoding flips win1252/utf8 per file (ct_spend_sources.encoding
    // is set correctly per-row by the source-finder agent but was never
    // actually read here). sanitise_str()'s preg_replace uses the /u flag,
    // which silently returns null on invalid-UTF-8 input -- so any win1252
    // row containing a non-ASCII byte (accented name, curly quote, £ as
    // 0xA3) was dropped outright rather than mis-decoded, undercounting
    // matches with no visible error (2026-06-21).
    // Oxford City Council: same win1252/utf8 per-file flip as Luton -- every
    // quarter is win1252 except the 2024-Q1 file (2024-01/02/03), which is
    // genuine UTF-8 (BOM + UTF-8-encoded £), confirmed by byte-level
    // inspection at onboarding (2026-06-23).
    // New Forest District Council: ct_council_config.encoding holds
    // win1252 but that static value is not read anywhere -- only the
    // per-source $source['encoding'] is, and only inside this allowlist.
    // Needed for the one CSV source (2022-04); the xlsx sources declare
    // UTF-8 shared strings internally regardless of the originating app's
    // encoding, so must be skipped here -- running mb_convert_encoding on
    // raw XLSX zip binary corrupts it (every New Forest xlsx source has
    // encoding=win1252 in the DB too, not just the one CSV; previously this
    // silently broke ZipArchive::open on all of them, 2026-06-24).
    // Maidstone Borough Council: all 14 CSV sources are genuinely win1252
    // (confirmed by byte inspection -- mojibaked apostrophes/en-dashes in a
    // handful of supplier/service names, e.g. "Hood\xEFs Tree Services",
    // "Doug\xB4s Maintenance Services"); the 2 XLSX sources declare UTF-8
    // internally like New Forest's and must be skipped here.
    if ((str_contains(strtolower($source['council']), 'luton')
            || str_contains(strtolower($source['council']), 'oxford city')
            || str_contains(strtolower($source['council']), 'new forest')
            || str_contains(strtolower($source['council']), 'maidstone'))
        && strtolower($source['encoding'] ?? '') === 'win1252'
        && substr($content, 0, 2) !== 'PK') {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    // Parse
    $is_xlsx = substr($content, 0, 2) === 'PK' || $fmt === 'xlsx';
    // Mid Devon's source is a plain HTML page, not a real XLSX -- routed
    // through the same in-memory-$rows branch as XLSX below (rather than the
    // CSV-streaming branch) since parse_html_table() produces the same
    // array-of-arrays shape, just via a different parser.
    $is_html_table = str_contains(strtolower($source['council']), 'mid devon');
    if ($is_html_table) $is_xlsx = true;

    // For XLSX, parse all rows at once (files are small enough)
    // For CSV, validate we have content then stream row by row
    if ($is_xlsx) {
        $rows = $is_html_table ? parse_html_table($content) : parse_xlsx($content, $sheet_num);
        // Bury: workbook has two sheets ("Spend Over £500 ( Distributed )" —
        // the real data — and "Parameters", a small config sheet) but their
        // order isn't consistent (data sheet is sheet1 some months, sheet2
        // others, depending which one Bury's export tool wrote first).
        // Detect by header content instead of trusting position 1.
        if (str_contains(strtolower($source['council']), 'bury')
            && !in_array('supplier', array_map('strtolower', array_map('trim', $rows[0] ?? [])), true)) {
            $rows = parse_xlsx($content, 2);
        }
        // West Suffolk: the 2025-05 workbook uniquely has a small
        // "client/user_id/language/Supplier ID !()/..." report-parameters
        // sheet as sheet 1 (12 rows, no real payment data at all -- every
        // other month checked, 2025-04 and 2025-06, has the real data
        // directly on sheet 1) with the actual "Updated,Transaction
        // number,Amount,..." data on sheet 2 instead. Without this, the
        // parameters sheet's "client"/"WS" row was read as the header and
        // every column position was wrong, silently producing 0 matches
        // (caught 2026-06-23). Detect by checking for "amount" in the
        // header row rather than trusting sheet 1 unconditionally.
        if (str_contains(strtolower($source['council']), 'west suffolk')
            && $source['period'] !== '2023-04'
            && !in_array('amount', array_map('strtolower', array_map('trim', $rows[0] ?? [])), true)) {
            $rows = parse_xlsx($content, 2);
        }
        // East Hampshire District Council: new-format workbooks (2024-07+)
        // have an IBReport 935 sheet with the actual payment data, but the
        // sheet order is inconsistent -- some months export "Parameters" as
        // sheet1 (a small client/user_id/language config sheet with no
        // payment data), others export IBReport 935 as sheet1 directly.
        // Detect by checking whether a known amount-column header appears
        // in the first row; if not, the Parameters sheet was sheet1 and the
        // real data is on sheet2. Old-era files (2022-04 to 2024-06) are
        // single-sheet with "Line Value" as the amount header (not "Amount")
        // -- include "line value" in the check so they are not incorrectly
        // sent to sheet2 (which doesn't exist in old-era files and returns []).
        if (str_contains(strtolower($source['council']), 'east hampshire')) {
            $hdr_lower = array_map('strtolower', array_map('trim', $rows[0] ?? []));
            if (!in_array('amount', $hdr_lower, true) && !in_array('line value', $hdr_lower, true)) {
                $rows = parse_xlsx($content, 2);
            }
        }
        // South Derbyshire District Council: annual XLSX files (one per FY,
        // e.g. Payments-over-250-2022-23.xlsx) contain 12 sheets named
        // "April Spending", "May Spending", ... "March Spending". Sheet 1
        // (April) is already correct for the 2022-04/2023-04/2024-04/2025-04
        // source rows. For all other months, select the sheet matching the
        // period: April=1, May=2, Jun=3, Jul=4, Aug=5, Sep=6, Oct=7, Nov=8,
        // Dec=9, Jan=10, Feb=11, Mar=12 within each fiscal year.
        if (str_contains(strtolower($source['council']), 'south derbyshire')) {
            $mo_to_sheet = ['04'=>1,'05'=>2,'06'=>3,'07'=>4,'08'=>5,'09'=>6,
                            '10'=>7,'11'=>8,'12'=>9,'01'=>10,'02'=>11,'03'=>12];
            $mo = substr($source['period'], 5, 2);
            $sheet_num = $mo_to_sheet[$mo] ?? 1;
            if ($sheet_num !== 1) {
                $rows = parse_xlsx($content, $sheet_num);
            }
        }
        // Peterborough City Council: quarterly XLSX (Cambridgeshire Insight Open
        // Data), one MONTH PER SHEET -- sheet1/2/3 = the three months of the
        // quarter (confirmed across all 15 files 2022-04..2025-12). Each file is
        // registered against all 3 of its months in ct_spend_sources; without a
        // sheet map the importer only ever read sheet 1, so months 2 and 3 of
        // every quarter were never imported (~23M of tech spend missing). Map the
        // month-within-quarter to the sheet: Apr/Jul/Oct/Jan=1, May/Aug/Nov/Feb=2,
        // Jun/Sep/Dec/Mar=3. Period stays the source month (the sheet month), so
        // each source reads only its own tab -- no overlap, no triplication.
        // (Discovered 2026-07-30 during the multi-period dup-bug scan; the earlier
        // period-filter-allowlist entry was WRONG for this -- it left 2 empty
        // months per quarter -- and has been removed.)
        if (str_contains(strtolower($source['council']), 'peterborough')) {
            $q_mo_to_sheet = ['04'=>1,'07'=>1,'10'=>1,'01'=>1,
                              '05'=>2,'08'=>2,'11'=>2,'02'=>2,
                              '06'=>3,'09'=>3,'12'=>3,'03'=>3];
            $mo = substr($source['period'], 5, 2);
            $sheet_num = $q_mo_to_sheet[$mo] ?? 1;
            if ($sheet_num !== 1) {
                $rows = parse_xlsx($content, $sheet_num);
            }
        }
        // Mid Ulster District Council: single XLSX (midulster_spend.xlsx)
        // covering 2023-04..2026-04, with the data split across SEVEN tabs by
        // date range ('Apr23-Sept24','Oct-Dec24','Jan-Mar25','Apr-Jun25',
        // 'Jul-Sept25','Oct-Dec25','Jan-Mar26'). Unlike Peterborough (one clean
        // month per sheet), Mid Ulster's tabs OVERLAP -- payments bleed across
        // tab boundaries (e.g. Oct-2025 rows appear in both 'Jul-Sept25' and
        // 'Oct-Dec25' as DISTINCT rows, verified zero cross-sheet duplication),
        // so a single-sheet-per-month map would silently lose the bled rows.
        // Instead we MERGE all 7 sheets into one array here and rely on the
        // per-row paid_date-in-period filter (allowlist below) to bucket each
        // row into its own month. Sheets 1-6 are [Supplier,Amount,Date] but
        // sheet 7 ('Jan-Mar26') has date and amount SWAPPED
        // ([Supplier,'Payment Date','Payment']) -- reorder it to the canonical
        // [supplier,amount,date] (col_s=0/col_a=1/col_d=2) on merge. Dates are
        // Excel serials (~45000-46000), converted by the generic >40000 guard.
        // Supplier names in the first four tabs are source-truncated to 15
        // chars (a genuine export limitation, not a parse artifact); short
        // patterns still match on prefix, and truncation-recovery patterns
        // (e.g. 'phoenix softwar') were added for the ones that would miss.
        // The merged array is cached per-URL (static) so the 3MB workbook is
        // parsed once, not once per each of the ~37 monthly source rows.
        if (str_contains(strtolower($source['council']), 'mid ulster')) {
            static $mu_merged_cache = [];
            $mu_key = $source['url'];
            if (!isset($mu_merged_cache[$mu_key])) {
                $mu_all = [['Supplier', 'Amount', 'Date']]; // canonical header (skip_rows=1)
                for ($mu_s = 1; $mu_s <= 7; $mu_s++) {
                    $mu_rows = parse_xlsx($content, $mu_s);
                    $mu_swap = ($mu_s === 7); // 'Jan-Mar26' tab: date/amount swapped
                    for ($mu_i = 1; $mu_i < count($mu_rows); $mu_i++) { // skip each tab's header
                        $mu_r = $mu_rows[$mu_i];
                        if ($mu_swap) {
                            $mu_all[] = [$mu_r[0] ?? '', $mu_r[2] ?? '', $mu_r[1] ?? ''];
                        } else {
                            $mu_all[] = [$mu_r[0] ?? '', $mu_r[1] ?? '', $mu_r[2] ?? ''];
                        }
                    }
                }
                $mu_merged_cache[$mu_key] = $mu_all;
            }
            $rows = $mu_merged_cache[$mu_key];
        }
        // Welsh Government: the annual XLSX/ODS file (one per calendar year) has
        // one sheet per month named "January_YYYY", "February_YYYY", etc. in
        // order. Sheet 1 = January, sheet 2 = February, ..., sheet 12 = December.
        // Amounts are debit entries (all negative) -- abs() is applied in
        // process_spend_row(). Serial dates (2026 XLSX) are handled globally by
        // the 40000-60000 numeric detection; DD.MM.YYYY dates (2023/2024 ODS)
        // are handled by the generic 'd.m.Y' format in the date loop.
        // ODS multi-sheet selection now works via parse_ods_rows($sheet_num).
        // 2025 sources are all 404 -- those stay active=0.
        if (str_contains(strtolower($source['council']), 'welsh government')) {
            $mo_to_sheet = ['01'=>1,'02'=>2,'03'=>3,'04'=>4,'05'=>5,'06'=>6,
                            '07'=>7,'08'=>8,'09'=>9,'10'=>10,'11'=>11,'12'=>12];
            $mo = substr($source['period'], 5, 2);
            $sheet_num = $mo_to_sheet[$mo] ?? 1;
            if ($sheet_num !== 1) {
                $rows = parse_xlsx($content, $sheet_num);
            }
        }
        // St Helens Borough Council: most monthly workbooks are simple
        // single-sheet exports, but several months (2024-06 to 2025-03ish,
        // each 12-16MB vs ~150-500KB normal) are full internal working
        // files with the real data on a sheet named after the month (e.g.
        // "August") sandwiched between large hidden pivot-source sheets
        // ("Full summary", "AP Transp Report", "TT2 Only" -- one of these,
        // "TT2 Only", carries a padded 100,000+ row range with almost all
        // rows blank). The real data sheet is consistently the 2nd sheet by
        // physical file order (sheet2.xml) in every bloated workbook except
        // 04._July_2025.xlsx, where it's 1st (sheet1.xml) and "Notes"/"Full
        // summary"/etc follow after. "Full summary" itself ALSO has a
        // "Supplier Name" column (just reordered: "Transaction reference,
        // Body Name, Service Area, Division, Directorate, Expenditure
        // Category, Supplier Name, Pay Date, Sum of Amount", with its
        // header row starting at spreadsheet row 3 -- but parse_xlsx()'s
        // $rows array is dense/positional by occurrence order of <row>
        // elements actually present in the XML, not by their r="N"
        // attribute, so that row-3 header lands at $rows[0] -- so a generic
        // "does some row contain supplier name" scan matches "Full summary"
        // too and never falls back (caught 2026-06-24: every 2024-04..
        // 2025-03 period silently stored 0 tech payments). The reliable
        // discriminator confirmed across every bloated month is column A
        // (index 0) of the header row: always exactly "Body Name" (or
        // "Body"/"Directorate" for the few single-sheet variants without
        // the bloat) on the real data sheet, vs "Transaction reference" on
        // "Full summary".
        if (str_contains(strtolower($source['council']), 'st helens')) {
            $has_hdr = false;
            for ($hi = 0; $hi <= 2; $hi++) {
                $first_cell = strtolower(trim($rows[$hi][0] ?? ''));
                if (in_array($first_cell, ['body name', 'body', 'directorate'], true)) {
                    $has_hdr = true;
                    break;
                }
            }
            if (!$has_hdr) {
                $rows = parse_xlsx($content, 2);
            }
        }
        if (count($rows) < 2 && !str_contains(strtolower($source['council']), 'sheffield')) {
            out("  ✗ No rows parsed (got " . count($rows) . ")", 'err');
            $hex  = bin2hex(substr($raw, 0, 20));
            $text = htmlspecialchars(preg_replace('/[^\x20-\x7E]/', '.', substr($raw, 0, 100)));
            out("    Length: " . strlen($raw) . " bytes", 'warn');
            out("    Hex start: {$hex}", 'warn');
            out("    Printable: {$text}", 'warn');
            unset($raw);
            continue;
        }
    } else {
        // Quick sanity check on raw content
        if (strlen($content) < 100) {
            out("  ✗ Content too short (" . strlen($content) . " bytes)", 'err');
            continue;
        }
        // Hyndburn Borough Council 2026-04: "April 26 (CVS).csv" is old-Mac
        // lone-CR throughout (465 CR vs 15 LF across ~72KB) but a handful of
        // quoted multi-line description fields (e.g. "Clean up Car park\n\n
        // Car Park, Tottleworth Bridge...") carry genuine embedded LF bytes
        // within the first 50KB, and one of those LFs happens to land right
        // after a CR record-end, producing one incidental CRLF pair too --
        // both defeat the generic lone-CR guard below (which requires zero
        // LF in the first 50KB AND zero CRLF anywhere), so the file was
        // never normalised. Without normalisation fgets() reads from the
        // start up to the first genuine LF (~9.3KB, ~30 records) as ONE
        // giant garbage "line", corrupting every column from row 1 onward --
        // caught 2026-08-07: 0 tech payments stored despite ~£440.8k of
        // genuine tech spend in the file (Softcat, MRI Community Software,
        // Civica Election Services, NEC Software Solutions, Granicus, BT,
        // Vodafone, Aspire Technology Solutions, Aurora Managed Services,
        // Ctrack, Triscan, Thomson Reuters, Auto Time Systems). Verified by
        // direct byte-level inspection that CR is this file's true record
        // separator throughout; force the conversion for this one file
        // rather than loosening the general guard, which exists specifically
        // to protect properly LF-terminated CIAXLONE-style files with wide
        // preamble rows (see comment below).
        if (str_contains(strtolower($source['council']), 'hyndburn')
            && $source['period'] === '2026-04') {
            $content = str_replace("\r", "\n", $content);
        }
        // Sevenoaks District Council 2025-02: this one file is tab-separated
        // (every other month, before and after, is comma-separated with the
        // same 9-column layout). parse_csv()'s str_getcsv() call always uses
        // comma as the delimiter, so on this file the literal tabs are never
        // recognised as separators, and the opening quote of a quoted amount
        // like "5,932.22" isn't adjacent to a comma delimiter so it's never
        // recognised as opening a quoted field either -- the comma *inside*
        // that quoted amount then gets read as if it were the real field
        // separator, shattering each row into 2 garbage fields instead of 9
        // and leaving col_supplier (idx7) out of range -- caught 2026-08-09:
        // 0 tech payments stored. Converting tabs to commas up front makes
        // every quoted amount's opening quote land immediately after a
        // (former-tab) comma, so str_getcsv() then quotes it correctly and
        // re-parses cleanly into the standard 9-column layout (verified
        // against the raw file: 276 data rows, all columns line up with
        // every neighbouring month).
        if (str_contains(strtolower($source['council']), 'sevenoaks')
            && $source['period'] === '2025-02') {
            $content = str_replace("\t", ",", $content);
        }
        // Normalize lone-CR line endings (old Mac style, e.g. some Lincolnshire exports)
        // Lone-CR (old Mac CR-only style) normalisation. Only apply when the
        // file genuinely has no LF characters in the first 50 KB (covers any
        // reasonable preamble length) AND has no CRLF sequences (CRLF files
        // are handled correctly by the fgets/rtrim path and must not be
        // mangled here -- CIAXLONE CSVs with 16 KB-wide preamble rows were
        // incorrectly triggering this check with the original 1 KB window,
        // causing every CR to be doubled into CRNL and shifting all line
        // indices by +1 per row, breaking the header-scan detection).
        if (!str_contains(substr($content, 0, 50000), "\n")
            && !str_contains($content, "\r\n")
            && str_contains($content, "\r")) {
            $content = str_replace("\r", "\n", $content);
        }
        $rows = null;
        $data_rows = null;
    }

    // Column config
    $cfg_stmt = $pdo->prepare("SELECT * FROM ct_council_config WHERE council=?");
    $cfg_stmt->execute([$source['council']]);
    $cfg = $cfg_stmt->fetch() ?: [];

    $col_s   = (int)($cfg['col_supplier']  ?? 0);
    $col_a   = (int)($cfg['col_amount']    ?? 1);
    $col_d   = (int)($cfg['col_date']      ?? 2);
    $col_v   = (int)($cfg['col_service']   ?? -1);
    $col_cat = (int)($cfg['col_category']  ?? -1);
    $cat_filter = array_filter(array_map('trim', explode(',', $cfg['category_filter'] ?? '')));
    $skip    = (int)($cfg['skip_rows']     ?? 1);

    // Erewash Borough Council: from 2026-04 the council stopped publishing
    // monthly "Expenditure over £250" files and switched to a single quarterly
    // "Over 500 April to June 2026" bundle. That bundle DROPS the leading
    // "Body Name" column present in the monthly files (every column shifts
    // left by 1 vs ct_council_config's supplier=5/amount=6/date=4/service=2)
    // and adds a title row above the header (skip 2, not 1). Its invoice-date
    // column (col 3) holds transaction dates that frequently precede the
    // reporting month, so bucket on the "Month" column (col 6, "Apr-26" Mon-YY,
    // resolved to the 1st by the generic month-year parser) -- 3 monthly source
    // rows share the one file and are split by the paid_date-in-period filter
    // below (Erewash added to that council list). Detected by the bundle URL so
    // any future monthly files keep the static ct_council_config layout.
    if (str_contains(strtolower($source['council']), 'erewash')
        && str_contains(strtolower($source['url']), 'over%20500')) {
        $col_s = 4; $col_a = 5; $col_d = 6; $col_v = 1; $skip = 2;
    }

    // ── Per-source column overrides (Phase 2 2026-09-19) ──────────────────────────
    // Councils flagged use_source_cols=1 in ct_council_config record the correct
    // col_supplier/col_amount/col_date/col_service/skip_rows PER PERIOD on their own
    // ct_spend_sources rows (their published layouts shift over time), so honour those
    // per-source values instead of the single static ct_council_config layout. This
    // replaces the former ~98-council name allowlist; the per-council rationale it
    // carried is preserved verbatim in docs/per_source_col_councils.txt. Proven
    // behaviour-identical (17,305/17,305 sources) -- see project_importer_rationalisation
    // and reference_importer_matcher_divergence.
    if (!empty($cfg['use_source_cols'])) {
        $col_s = (int)$source['col_supplier'];
        $col_a = (int)$source['col_amount'];
        $col_d = (int)$source['col_date'];
        $col_v = (int)$source['col_service'];
        $skip  = (int)$source['skip_rows'];
    }

    // Mole Valley District Council: user-provided CSV files (periods 2023-04
    // onwards) with a consistent 7-column layout: effective_date, payment_date,
    // supplier, directorate, net_amount, vat, purpose. Detection by header
    // name ensures correct column alignment regardless of any future format
    // variations in new uploads. Headers use underscores, so normalize them
    // to spaces before matching.
    if (str_contains(strtolower($source['council']), 'mole valley')) {
        $first_line = $rows !== null ? $rows[0] : strtok($content, "\n");
        $raw_hdr = is_array($first_line) ? $first_line : str_getcsv($first_line);
        $hdr = array_map(fn($h) => str_replace('_', ' ', strtolower(trim($h))), $raw_hdr);
        apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $hdr, [
            's' => ['supplier (beneficiary) name', 'supplier'],
            'a' => ['net amount'],
            'd' => ['payment date'],
            'v' => ['directorate/service where expenditure incurred', 'directorate'],
        ]);
    }

    // Clackmannanshire Council: the annual "Trade Creditor transaction data"
    // XLSX files use two subtly different layouts. The current FY file (doc
    // 7590, 2025-26) carries a leading junk column (header "*", values "LIST")
    // so its columns are Supplier(1)/PaymentDate(2)/NetAmount(3)/GBP(4)/
    // NominalDescription(5)/Service(6) -- matching the static config. The
    // backfill files (docs 7627/7628/7629, FYs 2024-25/2023-24/2022-23) have
    // NO leading column, shifting everything left by one: Supplier(0)/
    // PaymentDate(1)/NetAmount(2)/GBP(3)/NominalDescription(4)/Service(5).
    // There are also TWO "Net Amount" headers (the real value column and a
    // "GBP" currency-code column that Excel labelled the same), so match only
    // the FIRST occurrence for the amount. Detect all columns by header name
    // so both layouts import correctly regardless of which annual file each
    // period's source row points at. (Multi-period single-file dedup by
    // paid-date is already handled by the allowlist entry above.)
    if (str_contains(strtolower($source['council']), 'clackmannanshire') && $rows !== null) {
        $ck_hdr = $rows[0] ?? [];
        $ck_amt_set = false;
        foreach ($ck_hdr as $ci => $ch) {
            $ch = strtolower(trim((string)$ch));
            if ($ch === 'supplier name') $col_s = $ci;
            if ($ch === 'net amount' && !$ck_amt_set) { $col_a = $ci; $ck_amt_set = true; }
            if ($ch === 'payment date') $col_d = $ci;
            if ($ch === 'service') $col_v = $ci;
        }
    }

    // Newport City Council: quarterly XLSX. The 2022-04..2023-12 era files
    // carry a leading "BODY NAME" column so their layout is BODY NAME(0)/
    // DATE(1)/AMOUNT(2)/SUPPLIER NAME(3) with the header on row 0 -- matching
    // the static config (col_date=1/col_amount=2/col_supplier=3/skip_rows=1).
    // The 2024/2025 quarterly files dropped the leading column, shifting
    // everything left by one: Date(0)/Amount(1)/Supplier Name(2), and prepend
    // a free-text "Please note..." preamble row so the header sits on row 1
    // (or row 2 for the Jan-Mar/Oct-Dec 2025 file, which has an extra blank
    // row). Against the static config both the column shift and the moved
    // header row would drop every 2024/25 row. Detect the header row (the one
    // containing both "date" and "amount") within the first few rows and set
    // col_d/col_a/col_s + skip from it by name, so every era imports correctly
    // regardless of which quarter file the period's source row points at.
    // (Multi-period single-file dedup is handled by the paid_date-in-period
    // allowlist entry above; there is no col_service -- do NOT set one, the
    // files have no service/narrative column.)
    if (str_contains(strtolower($source['council']), 'newport city') && $rows !== null) {
        foreach (array_slice($rows, 0, 6, true) as $ni => $nrow) {
            $ncells = array_map(fn($v) => strtolower(trim((string)$v)), $nrow);
            if (in_array('date', $ncells, true) && in_array('amount', $ncells, true)) {
                apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $ncells, [
                    'd' => ['date'],
                    'a' => ['amount'],
                    's' => ['supplier name'],
                ]);
                $skip = $ni + 1;
                break;
            }
        }
    }

    // Babergh District Council: from Q4 2025/26 (periods 2026-01/02/03, the
    // "...202526-q4-bdc" file) an extra "Legentity (T)" column is inserted at
    // index 3, right after Amount -- shifting Service Area from col 3 to 4,
    // Category from 4 to 5, and Transaction Date from col 5 to 6. Config has
    // col_date=5/col_service=3 (the pre-Q4 layout), so on the shifted file the
    // importer read "Category" text as the date, failed to parse it, and
    // dropped every row via the null-date period filter -- 0 tech payments for
    // all three Q4 months despite the file fetching fine (flagged in
    // ct_council_config.notes, caught 2026-08-01). Detect by header name rather
    // than a hardcoded period so future quarters that keep the Legentity column
    // are handled automatically: find "transaction date" and "service area" by
    // header text and point col_d/col_v at them. Supplier(1)/Amount(2) are
    // unaffected by the insert in either layout.
    if (str_contains(strtolower($source['council']), 'babergh') && !$is_xlsx) {
        $cand = header_candidate_rows($rows, $content, 1);
        if ($cand) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[0], [
                'd' => ['transaction date'],
                'v' => ['service area'],
            ]);
        }
    }

    // Ipswich Borough Council: the standard monthly CSV layout (used for every
    // period except 2025-11 and 2025-12) has 10 columns -- "Date"(4),
    // "Amount"(6), "Supplier Name"(8) -- matching ct_council_config. The
    // November and December 2025 files were exported from a different system
    // with a narrow 6-column layout: "Posting Date"(0), "Expenditure Area"(1),
    // "Supplier Name"(2), "Reason for Expense"(3), "Amount"(4), "Expense
    // Type"(5). Against the static config cols (supplier=8/amount=6/date=4)
    // those two files read past the end of every row and stored 0 tech
    // payments (both periods last_count=0, caught 2026-08-02). Detect the
    // actual columns by header name so both layouts import correctly
    // regardless of future format drift: map Supplier Name / Amount / Date
    // (or Posting Date). The standard files resolve to the identical config
    // indices, so this is a no-op there. Guard is 'ipswich borough' so it
    // never touches the separate "Ipswich and South Suffolk Council" config.
    if (str_contains(strtolower($source['council']), 'ipswich borough') && !$is_xlsx) {
        $first_line = $rows !== null ? $rows[0] : strtok($content, "\n");
        $ips_hdr = is_array($first_line) ? $first_line : str_getcsv($first_line);
        $ips_hdr = array_map(fn($ih) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string)$ih))), $ips_hdr);
        apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $ips_hdr, [
            's' => ['supplier name'],
            'a' => ['amount'],
            'd' => ['date', 'posting date'],
        ]);
    }

    // Oxford City Council: 3 of the 16 quarterly files in the pre-2024-Q2
    // 10-col era (2022-Q3 "...269...", 2022-Q4 "...266...", 2023-Q2
    // "...264...", covering periods 2022-07/08/09, 2022-10/11/12 and
    // 2023-04/05/06) uniquely insert an extra blank column right after
    // "Expenditure Catergory" (sic), shifting Paid Date/Amount/Supplier Name
    // right by one (Date 4->5, Amount 6->7, Supplier Name 9->10) relative to
    // the other 13 quarters in the same era -- the source-finder's per-row
    // col_supplier/col_amount/col_date values only captured the more common
    // non-shifted layout. Detect by actual header column count for this
    // council/era rather than trusting the per-row DB override above: if the
    // header row has 11 columns (instead of the usual 10), every column from
    // "Expenditure Catergory" onwards is one position further right.
    if (str_contains(strtolower($source['council']), 'oxford city')
        && !$is_xlsx
        && $col_s === 9 && $col_a === 6 && $col_d === 4) {
        $lines = explode("\n", str_replace(["\r\n", "\r"], ["\n", "\n"], $content));
        $hdr_line = $lines[$skip - 1] ?? '';
        $hdr = str_getcsv($hdr_line);
        if (count($hdr) === 11) {
            $col_d = 5;
            $col_a = 7;
            $col_s = 10;
        }
    }

    // Cambridgeshire: the 2026-01 file uniquely inserts an extra "Supplier ID"
    // column between Amount and Supplier Name (every other month checked,
    // 2025-09 through 2026-03 except 2026-01, has no such column) -- a single
    // one-month publishing glitch, not a permanent layout change, so it's
    // handled as a scoped one-off here rather than via the generic per-source
    // override above (which isn't read for this council and would need every
    // other period's row populated to avoid regressing them). Shifts
    // col_supplier from 4->5, col_service from 6->7, and col_category (used
    // by the 'ICT Service' category_filter gate) from 7->8 so it keeps
    // reading "Cost Centre Description" rather than "Expense Area" for this
    // period only.
    if (str_contains(strtolower($source['council']), 'cambridgeshire county') && $source['period'] === '2026-01') {
        $col_s = 5;
        $col_v = 7;
        $col_cat = 8;
    }

    // Wirral: the December 2025 export uniquely omits the "Paid Date"
    // column entirely (confirmed in the raw file -- every row has only 7
    // fields where every other month has 8), shifting Paid Amount/
    // Department/Cost Centre/Description left by one. Normal layout:
    // Supplier Name(0),Transaction Number(1),Paid Date(2),Paid Amount(3),
    // Department(4),Cost Centre(5),Description(6). This file: Supplier
    // Name(0),Transaction Number(1),Paid Amount(2),Department(3),Cost
    // Centre(4),Description(5) -- no date column at all. col_d is left
    // pointing past the end of the row so it reads as empty (the null-date
    // fallback to period-start above then applies), rather than at the
    // amount column where it would misread a number as a date.
    if (str_contains(strtolower($source['council']), 'wirral') && $source['period'] === '2025-12') {
        $col_a = 2;
        $col_d = 99;
        $col_v = 5;
    }

    // Tameside: the Q2 2024-25 quarterly file (2024-07/08/09, "q2-upload2024-csv")
    // uniquely inserts two extra columns -- a "String" column (concatenated
    // transaction-ID+supplier-name) right after Supplier Name, and a "Redact
    // Name" column right before Paid Date -- shifting Paid Date from col 5 to
    // 7 and Sum of Inv Amount from col 6 to 8 (every other quarter checked
    // keeps the standard 7-col layout). Without this, col 5 ("Account
    // Description", a text string) was read as the date and col 6 ("Redact
    // Name", empty) as the amount, so every row failed the amount>0 check and
    // was silently dropped (caught 2026-06-23: 0 tech payments stored for all
    // 3 months despite every neighbouring quarter having 10-30+). A single
    // one-quarter publishing glitch, not a permanent layout change, so it's
    // handled as a scoped one-off here rather than via the generic per-source
    // override above (which isn't populated for this council and would need
    // every other period's row backfilled to avoid regressing them).
    if (str_contains(strtolower($source['council']), 'tameside')
        && in_array($source['period'], ['2024-07', '2024-08', '2024-09'], true)) {
        $col_d = 7;
        $col_a = 8;
    }

    // West Suffolk: the 2022-04 file uniquely inserts an extra "Ap/Ar ID"
    // numeric column before "Ap/Ar ID(T)" (every other month, 2022-05
    // onwards, goes straight from Irrecoverable VAT to Ap/Ar ID(T)) --
    // shifts Supplier from col 4 to 5 and Account(T) (service) from col 6
    // to 7; Date (col 0) and Amount (col 2) are unaffected. A single
    // one-month layout glitch, not a permanent change, so handled as a
    // scoped one-off rather than a generic override.
    if (str_contains(strtolower($source['council']), 'west suffolk')
        && $source['period'] === '2022-04') {
        $col_s = 5;
        $col_v = 7;
    }

    // West Suffolk: the 2023-04 file uniquely inserts an extra title row
    // (Payments to suppliers over 250...) before the normal header row,
    // shifting all data rows down by one -- standard skip_rows=1 then reads
    // the header as data row 1 and misreads column positions. Bump skip to
    // 2 for this month only.
    if (str_contains(strtolower($source['council']), 'west suffolk')
        && $source['period'] === '2023-04') {
        $skip = 2;
    }

    // Rochford District Council: 2024-04 onwards switched from a 12-column
    // layout (Supplier=2, Date=10, Amount=11, Service=7) to a 10-column
    // layout (Supplier=2, Date=8, Amount=9, Service=3). The DB config still
    // reflects the old layout. Detect by checking column count in the header
    // row -- if <= 10 cols, apply the new positions.
    if (str_contains(strtolower($source['council']), 'rochford')
        && $rows !== null && count($rows[0] ?? []) <= 10) {
        $col_d = 8;
        $col_a = 9;
        $col_v = 3;
    }

    // Newark & Sherwood: annual files (FY 2022-23) and Q1-Q3 FY 2023-24 use a
    // 9-column layout (BODY NAME, SERVICE LABEL, SERVICE CODE, EXPENDITURE
    // CATEGORY, DATE, TRANSACTION NUMBER, NET AMOUNT, SUPPLIER NAME, SUPPLIER
    // ID), while quarterly files from 2024-01 onwards use a 7-column layout
    // (BODY NAME, EXPENDITURE CATEGORY, DATE, TRANSACTION NUMBER, NET AMOUNT,
    // SUPPLIER NAME, SUPPLIER ID). Detect by header name rather than period so
    // both formats are handled transparently.
    if (str_contains(strtolower($source['council']), 'newark')) {
        // CSV file — $rows is null; read header from first line of $content
        $newark_hdr_line = $rows !== null ? $rows[0] : str_getcsv(strtok($content, "\n"));
        $hdr_nk = array_map('strtolower', array_map('trim', $newark_hdr_line ?? []));
        if (in_array('service label', $hdr_nk, true) || in_array('service label committee', $hdr_nk, true)) {
            // Old 9-col annual/early-quarterly format
            $col_s = array_search('supplier name', $hdr_nk) ?: 7;
            $col_a = array_search('net amount', $hdr_nk) ?: 6;
            $col_d = array_search('date', $hdr_nk) ?: 4;
            $col_v = array_search('expenditure category', $hdr_nk) ?: 3;
            $skip  = 1;
        }
        // 7-col quarterly format: existing ct_council_config values are already correct
    }

    // Colchester: user-supplied local files now cover almost the whole
    // 2022-04..2026-04 range (previously only 2 of 49 periods were reachable
    // at all, the rest behind a SharePoint login wall). At least 3 distinct
    // header layouts appear across that range, not tied to any clean date
    // boundary -- e.g. 2022-04/2023-09/2024-09/2025-06 use a 21-column
    // layout ("organisation,code,Effective Date,Service,...,Supplier,...,
    // Payment Date,Transaction,Net Amount,..."), 2025-10/11 use a different
    // 13-column layout ("Organisation,Code,Effective Date,Service,...,
    // Supplier Name,...,Date Paid,Transaction Reference,Net Amount,..."),
    // and 2025-12 onwards use a 7-column layout ("Date Paid,Service,Service
    // Category,Supplier Name,Purpose,Net Amount,irrvat"). All 3 share enough
    // header vocabulary that name-based detection (rather than a per-period
    // lookup table) handles every layout uniformly -- header is always row 0.
    if (str_contains(strtolower($source['council']), 'colchester')) {
        $cand = header_candidate_rows($rows, null, 1);   // $rows only (no content fallback)
        if ($cand) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[0], [
                's' => ['supplier', 'supplier name'],
                'a' => ['net amount'],
                'd' => ['payment date', 'date paid'],
                'v' => ['service'],
            ]);
        }
        $skip = 1;
    }

    // East Suffolk: most months are a clean static 9-column layout
    // (col_s=8, col_a=6, col_d=4, col_v=0, skip=8). A handful of months
    // (e.g. 2025-11, 2026-02) instead have 4 junk preamble rows (including a
    // stray "#REF!" Excel-formula-error artifact) followed by a completely
    // different 15-column layout with "Supplier Name" as the LAST column
    // instead of one of the first -- detected here by header text so the
    // static config keeps working for the normal months.
    if (str_contains(strtolower($source['council']), 'east suffolk')) {
        $cand = header_candidate_rows(null, $content, 10);   // $content only
        $hi = find_header_row($cand, fn($h) => in_array('supplier name', $h, true));
        if ($hi !== null) {
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[$hi], [
                's' => ['supplier name'],
                'a' => ['amount'],
                'd' => ['date'],
                'v' => ['service area categorisation'],
            ]);
        }
    }

    // Maidstone Borough Council: fully Cloudflare-blocked (dynamic search
    // tool only, no fetchable export URL), onboarded via 16 user-supplied
    // quarterly files (financial-year quarters: Q1=Apr-Jun, Q2=Jul-Sep,
    // Q3=Oct-Dec, Q4=Jan-Mar) hosted locally under /contracts/data/maidstone/,
    // 3 monthly ct_spend_sources rows sharing each file (same pattern as
    // Norfolk/Halton/Oldham above) plus the per-row paid_date-in-period
    // filter in the $is_quarterly_by_date-style block below. Column layout
    // ("Service Area,Purpose,Date,Transaction Number,Beneficiary,Merchant
    // Category,VAT that Cannot be Recovered,Amount excl VAT £,Expenditure
    // Type") is stable by position (Date=2, Beneficiary/supplier=4,
    // Amount=7, Service Area=0) across 14 of the 16 files, but one quarter
    // (Quarter-Four-2022.csv, periods 2023-01/02/03) uniquely inserts a
    // leading "Invoice Number" column shifting every field right by 1, so
    // columns are detected by header name rather than trusting the static
    // position. Two files are XLSX (2025Q2.xlsx, Quarter-three-2025.xlsx)
    // with the identical 9-column header/order as the CSVs. Every quarterly
    // file also contains a handful of stray rows just outside its named
    // quarter (e.g. 2024Q1.csv's bulk is Apr-Jun 2024 but also carries 260
    // genuine Jul 2024 rows) -- harmless under the per-row date filter,
    // which buckets each row by its own Date value into the matching
    // ct_spend_sources period regardless of source file.
    if (str_contains(strtolower($source['council']), 'maidstone')) {
        if ($is_xlsx) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line)));
        }
        apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
            's' => [['exact', 'beneficiary']],
            'a' => [['prefix', 'amount excl vat']],
            'd' => [['exact', 'date']],
            'v' => [['exact', 'service area']],
        ]);
        $skip = 1;
    }

    // Braintree: header text ("Date Paid,Net ,Supplier Name,Expense Area,
    // Expense Type", optionally preceded by "Supplier Code" and/or followed
    // by "Redacted Personal(sal) Data") is stable, but both the row offset of
    // the header (3 blank/title preamble lines most months, but sometimes 2
    // or 4 -- e.g. a stray leading-BOM "Braintree District Council" title
    // row some months has no trailing blank line after it) and the column
    // count (5 cols pre-2023 with no "Supplier Code", 6-7 cols from 2023
    // onwards) vary unpredictably file-to-file, not at any clean date
    // boundary -- confirmed by direct inspection of several months across
    // the full 2022-04..2026-04 range at onboarding (2026-06-23). Detect the
    // header row by scanning for "supplier name" rather than trusting any
    // fixed skip_rows/column position.
    if (str_contains(strtolower($source['council']), 'braintree')) {
        $cand = header_candidate_rows(null, $content, 9);   // $content only
        $hi = find_header_row($cand, fn($h) => in_array('supplier name', $h, true));
        if ($hi !== null) {
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[$hi], [
                's' => ['supplier name'],
                'a' => ['net', 'net amount'],
                'd' => ['date paid'],
                'v' => ['expense area'],
            ]);
        }
    }

    // Wychavon District Council: live site is behind a Sucuri/Cloudproxy WAF
    // (JS challenge) so the importer's plain fetch was bounced for every live
    // URL -- all historical periods are now self-hosted under
    // /contracts/data/wychavon/ (downloaded via a one-off challenge-solver).
    // The standard monthly layout (2022-04..2024-11 except 2024-01, plus the
    // 2024-08 XLSX and 2025-05+) is 11 columns: "Voluntary, Community and
    // Social Grants"(0), Creditor Number(1), Creditor Name(2), Voucher
    // Number(3), Type Of Creditor(4), Narrative(5), DCN 03 Sub Group(6),
    // Section(7), Payment Date(8), Net Amount(9), Voucher Status(10) --
    // matching the static config (col_s=2/col_v=7/col_d=8/col_a=9, skip=1).
    // The 2024-01 CSV is a one-off different export: a junk title row
    // ("Payments over £250 January 2024") on line 1, then a 9-column layout
    // with a different order -- Creditor Name(0), Section(1), Voucher No(2),
    // Cost Code(3), Detail Code(4), Type of Expenditure(5), Description(6),
    // Payment Date(7), Net Amount(8). Detect the header row by scanning for
    // "creditor name" / "payment date" / "net amount" so both layouts import
    // correctly regardless of column order or preamble rows. This is a no-op
    // for the standard files (resolves to the identical config indices).
    if (str_contains(strtolower($source['council']), 'wychavon') && !$is_xlsx) {
        $lines = explode("\n", (string)$content);
        for ($hi = 0; $hi <= 8; $hi++) {
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[$hi] ?? '')));
            if (!in_array('creditor name', $hdr, true) || !in_array('payment date', $hdr, true)) continue;
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $hdr, [
                's' => ['creditor name'],
                'a' => ['net amount'],
                'd' => ['payment date'],
                'v' => ['section'],
            ]);
            break;
        }
    }

    // Lewes District Council: Q1-Q4 2023-24 and Q1-Q2 2024-25 use a 10-column
    // layout (Body Name, Body, Organisational Unit, CIPFA Type, Date, Transaction
    // Number, Net Amount, Supplier Name, Supplier_ID, Ledger_Code) giving the
    // static DB config of col_d=4, col_a=6, col_s=7. Q3 and Q4 2024-25 add two
    // extra columns ("Service Label" and "Service Code") at positions 2-3,
    // shifting Date->6, Net Amount->8, Supplier Name->9. Detect by scanning the
    // header row for "service label" and re-mapping if found. skip_rows=2 is
    // stable across all layouts (row 0 = headers, row 1 = blank).
    if (str_contains(strtolower($source['council']), 'lewes district')) {
        $first_line = strtok($content, "\n");
        $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line)));
        if (in_array('service label', $hdr, true)) {
            // 12-column layout with extra Service Label + Service Code columns
            $col_d = 6;
            $col_a = 8;
            $col_s = 9;
        }
    }

    // Basingstoke and Deane: column *positions* are fully static across the
    // whole 2022-04..2026-05 range (Period(date)=2, Account Description=4,
    // Amount=5, Creditor Name(supplier)=6, Type=7), but the header row toggles
    // unpredictably between row 2 and row 3 (an extra blank preamble row)
    // file-by-file -- not date-bounded, confirmed by checking every month
    // 2026-06-23. Column 0's header label also renames from "Business Unit"
    // to "Service" from 2024-04 onwards (and "Service Category" ->
    // "Service Categorisation" later still) but the position itself never
    // moves, so detecting by either label text and reading the skip position
    // from wherever it's actually found avoids needing a second column-index
    // change for the label rename.
    if (str_contains(strtolower($source['council']), 'basingstoke')) {
        for ($hi = 0; $hi <= 4; $hi++) {
            $line = $rows !== null ? implode(',', $rows[$hi] ?? []) : (explode("\n", $content)[$hi] ?? '');
            $first = trim((string)(str_getcsv($line)[0] ?? ''));
            if ($first === 'Business Unit' || $first === 'Service') {
                $skip = $hi + 1;
                break;
            }
        }
    }

    // Hounslow: the per-source col_* overrides turned out unreliable -- a
    // one-off June 2022 file has an undocumented 4th column variant (extra
    // "SupplierName" column inserted after BeneficiaryName, shifting
    // PaymentDate/Amount right by 1) that the static era-based positions
    // silently misread (TransactionNumber as Amount, a non-date string as
    // PaymentDate -- the exact "huge wrong-column number" bug class seen
    // before at Cheshire East). Header names ("BeneficiaryName", "Amount",
    // "PaymentDate", "ServiceCategoryLabel") are however identical and
    // stable across every era/variant seen (9, 10, 11-col, and the xlsx
    // sheet-2 layout), so detect columns by name instead of trusting any
    // static position. XLSX-era files also have a small "Options dialog"
    // config sheet as sheet 1; the real payment data is on sheet 2.
    if (str_contains(strtolower($source['council']), 'hounslow')) {
        if ($is_xlsx) {
            $rows = parse_xlsx($content, 2);
        }
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line)));
        }
        apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $hdr, [
            's' => ['beneficiaryname'],
            'a' => ['amount'],
            'd' => ['paymentdate'],
            'v' => ['servicecategorylabel'],
        ]);
        $skip = 1;
    }

    // Hertsmere: each monthly workbook has two worksheets, "Capital" (sheet 1)
    // and "Revenue" (sheet 2) -- Revenue is far larger (the bulk of monthly
    // spend, including most tech-supplier payments) but was never being read,
    // since parse_xlsx() only reads the sheet selected by $sheet_num (1 by
    // default) and no Hertsmere source URL carries a "#sheet=2" fragment.
    // Some early months (e.g. Apr 2023) also insert an extra
    // "TransactionRefFormula" column between Department and Payment Date,
    // shifting every later column right by 1 -- detected here by header name
    // (rows 0-6) rather than relying on a fixed column count, so one block
    // handles both column layouts. After detecting columns from sheet 1's
    // header, sheet 2 is parsed separately and its own header rows dropped
    // before appending its data rows onto $rows, so the existing
    // array_slice($rows, $skip) below picks up both sheets' data in one pass.
    if ($is_xlsx && str_contains(strtolower($source['council']), 'hertsmere')) {
        for ($hi = 0; $hi <= 6; $hi++) {
            $hdr = array_map('strtolower', array_map('trim', $rows[$hi] ?? []));
            if (!in_array('supplier name', $hdr, true)) continue;
            $skip = $hi + 1;
            foreach ($hdr as $i => $h) {
                if ($h === 'supplier name') $col_s = $i;
                if ($h === 'net value') $col_a = $i;
                if ($h === 'payment date') $col_d = $i;
                if ($h === 'nature of expenditure') $col_v = $i;
            }
            break;
        }
        $sheet2_rows = parse_xlsx($content, 2);
        $rows = array_merge($rows, array_slice($sheet2_rows, $skip));
    }

    // Plymouth City Council: extremely inconsistent column layout across
    // the user-supplied .xls archive used to unblock this council (2026-06-21)
    // -- column count varies from 11 to 32 across different months (extra
    // "Service code"/"Department"/"Division" columns inserted at various
    // points, one file's real header buried at row 8 after 8 blank rows,
    // another with scattered blank-string columns from merged-cell
    // artifacts). "Supplier Name"/"Amount"/"Date"/"Detailed Expenses type"
    // are the only header text that stays literally identical throughout,
    // so detect columns by name (scanning up to row 14 for safety) rather
    // than trusting any fixed position -- the static ct_council_config
    // indices only happen to match the single most common layout.
    // col_v deliberately maps to "Detailed Expenses type" (e.g. "Purchase
    // Of Computer Software"), not the broader "Service label" (e.g. "Adult
    // Social Care") -- Capita is on the IT-relevance-filter list further
    // down which checks $service for IT keywords, and the broad service
    // label almost never contains them even for genuine IT-coded Capita
    // payments, silently dropping rows that should have matched (found via
    // a "Capita Business Services Ltd, Purchase Of Computer Software" row
    // in the 2022-07 file that matched but never inserted, 2026-06-21).
    if (str_contains(strtolower($source['council']), 'plymouth')) {
        $cand = header_candidate_rows($rows, $content, 15);
        $hi = find_header_row($cand, fn($h) => in_array('supplier name', $h, true));
        if ($hi !== null) {
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[$hi], [
                's' => ['supplier name'],
                'a' => ['amount'],
                'd' => ['date'],
                'v' => ['detailed expenses type'],
            ]);
        }
    }

    // West Northamptonshire: most months use a 9-column layout ("Body Name,
    // Date Paid,Transaction Number,Amount,Supplier Name,Supplier Type,
    // Expense Area,Cost Centre Description,Expense Type" -- matches the
    // static config col_supplier=4/col_amount=3/col_date=1/col_service=6),
    // but 2023-04 to 2023-12 use a 17-column layout with an extra "Invoice
    // Date" column inserted before Supplier Name, shifting it from index 4
    // to 5. Detect columns by header name on row 0 rather than a fixed
    // position. The 2026-01 onwards XLSX exports also have a "Control
    // Worksheet" instructions sheet as sheet 1, with the real data (same
    // 9-column layout) on sheet 2 -- parse sheet 2 directly for those.
    if (str_contains(strtolower($source['council']), 'west northamptonshire')) {
        if ($is_xlsx) {
            $rows = parse_xlsx($content, 2);
        }
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line)));
        }
        apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
            's' => [['exact', 'supplier name']],
            'a' => [['exact', 'amount']],
            'd' => [['exact', 'date paid']],
            'v' => [['prefix', 'expense area']],
        ]);
    }

    // Dorset: monthly XLSX exports up to 2025-03 ("Invoice Number,Profit
    // Center,Profit Centre Description,Order,Order Description,G/L
    // Account,G/L Description,Vendor,Vendor Account Group,Clearing,
    // Amount  £", header on row 2, skip_rows=2) match the static config
    // (col_supplier=7/col_amount=10/col_date=9/col_service=2) and need no
    // fix. From 2025-04 the source switched to a much narrower 6-column CSV
    // ("Directorate,Description,Invoice Number,Vendor,Date,Amount" -- the
    // date column is named "Clearing" in 2025-04/05 and "Date" from 2025-06
    // onwards) which the static (XLSX-era) config can't read at all
    // (col_supplier=7/col_amount=10 are out of bounds for a 6-column row),
    // so every CSV-era month silently produced 0 matches. Also varies 0-2
    // blank rows before the header across different months. Detect the
    // header row by scanning for "directorate" and map columns by name.
    if (!$is_xlsx && str_contains(strtolower($source['council']), 'dorset')) {
        for ($hi = 0; $hi <= 2; $hi++) {
            $lines = explode("\n", $content);
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[$hi] ?? '')));
            if (($hdr[0] ?? '') !== 'directorate') continue;
            $skip = $hi + 1;
            apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
                's' => [['exact', 'vendor']],
                'a' => [['substr', 'amount']],
                'd' => [['exact', 'date'], ['exact', 'clearing']],
                'v' => [['exact', 'directorate']],
            ]);
            break;
        }
    }

    // London Borough of Brent: quarterly CSV exports (one per rolling
    // 3-month period). Column positions are static and already correct
    // (Payment Date,Vendor Name 2,Cost Centre Code,Cost Centre
    // Description,Subjective(Code),Subjective Description,Amount,Non
    // Recoverable VAT -- col_supplier=1/col_amount=6/col_date=0/
    // col_service=3) across every quarter checked, but the first two
    // quarterly files (Mar-May 2022, Jun-Aug 2022) have an extra blank row
    // between the title and header rows that later quarters dropped --
    // detect the header row by scanning for "payment date" rather than
    // trusting the stored skip_rows (recorded correctly per source row at
    // onboarding time but, like Trafford's col_*, never read elsewhere).
    if (str_contains(strtolower($source['council']), 'london borough of brent')) {
        for ($hi = 0; $hi <= 2; $hi++) {
            $lines = explode("\n", $content);
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[$hi] ?? '')));
            if (($hdr[0] ?? '') !== 'payment date') continue;
            $skip = $hi + 1;
            break;
        }
    }

    // Cheshire East: the ArcGIS Open Data export gained an extra
    // "Supplier_Number" column (inserted after BeneficiaryName) for
    // FY2023/24 and FY2024/25 only, shifting every later column right by 1
    // (Amount: 7 -> 8) relative to FY2022/23 and FY2025/26. The static
    // per-council config only stores one col_amount value; ct_spend_sources
    // correctly recorded the per-FY override at onboarding time but (like
    // Trafford) that per-source value is never read elsewhere in this
    // script -- under the single static value, whichever FYs didn't match
    // read Amount from the neighbouring TransactionNumber column instead (an
    // 11-digit integer like "11300054760", misread as ~£11.3bn) -- this is
    // what caused the wave-2 corruption incident's £44.7 trillion total.
    // Detect every column by header name instead of a fixed position.
    if (str_contains(strtolower($source['council']), 'cheshire east')) {
        $first_line = strtok($content, "\n");
        $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line)));
        $col_pd = null;
        foreach ($hdr as $i => $h) {
            if ($h === 'beneficiaryname') $col_s = $i;
            if ($h === 'amount') $col_a = $i;
            if ($h === 'effectivedate') $col_d = $i;
            if ($h === 'paymentdate') $col_pd = $i;
            if ($h === 'proclasslabel_level1') $col_v = $i;
        }
        // FY2026/27's export stamps every row's EffectiveDate with the
        // literal placeholder "31/04/2026" (April has only 30 days) instead
        // of a real per-row date -- caught 2026-08-13 when the whole source
        // imported 0 tech rows despite genuine tech suppliers (Kainos,
        // Oracle Care, etc) being present. PaymentDate holds real
        // per-transaction dates in every FY seen (including the working
        // FY22/23..FY25/26 files), so prefer it whenever present.
        if ($col_pd !== null) $col_d = $col_pd;
    }

    // London Borough of Bromley: column layout has changed repeatedly across
    // 2022-04..2026-04 -- a 9-column CSV/XLSX with a leading "Organisation"
    // column up to 2023-03; a narrower 6-column XLSX (Organisation/Service
    // Area dropped) for 2023-04..2023-06; a 10-column layout adding
    // "SUBJECTIVE"/"SUPPLIER_NUMBER" columns for 2023-07..2024-05; back to 8
    // columns from 2024-06 onwards. The static config (col_supplier=3/
    // col_amount=6/col_date=5) only matches the most recent layout --
    // earlier months misread PAYMENT_NUMBER (a large integer) as the date,
    // or in the 10-column era read straight past the end of the row.
    // "Net Amount" is the one header spelled identically in every layout
    // seen, so detect every column by name instead. Underscores are
    // normalised to spaces before matching so "SUPPLIER_NAME_REDACTED" and
    // "PAYMENT_DATE" match the same way as their space-separated CSV-era
    // equivalents -- and so that "SUPPLIER_NUMBER" (present alongside
    // SUPPLIER_NAME_REDACTED in the 10-column era) does NOT get picked up
    // by a bare "supplier" substring check.
    if (str_contains(strtolower($source['council']), 'bromley')) {
        $raw_hdr = $rows !== null ? ($rows[0] ?? []) : str_getcsv(strtok($content, "\n"));
        $hdr = array_map(fn($h) => str_replace('_', ' ', strtolower(trim($h))), $raw_hdr);
        $col_portfolio = -1;
        foreach ($hdr as $i => $h) {
            if (str_contains($h, 'supplier name')) $col_s = $i;
            if ($h === 'net amount') $col_a = $i;
            if (str_contains($h, 'payment date')) $col_d = $i;
            if (str_contains($h, 'service area')) $col_v = $i;
            if ($h === 'portfolio') $col_portfolio = $i;
        }
        if ($col_v === -1) $col_v = $col_portfolio;
    }

    // Huntingdonshire: 4 distinct eras across 2022-04..2026-03, with this
    // council's static ct_council_config (col_supplier=2/col_amount=19/
    // col_date=5) matching none of them.
    // Era A (2022-04..2024-03, CSV+one xlsx month): "BODY,BODY NAME,SUPPLIER
    // CODE,SUPPLIER NAME,TRANSACTION NUMBER,INVOICE DATE,THEIR REF,NARR1,
    // PAID DATE,VAT AMOUNT,EXCLUSIVE AMOUNT,INCLUSIVE AMOUNT,EXPENSE AREA"
    // (supplier=3,date=8,amount=11,service=12), header on row 0.
    // Era A2 (2024-04..2024-09): identical columns to Era A but with an
    // extra "DefnSheetName=_defntmp_" junk row inserted before the header,
    // so the header is on row 1 instead of row 0 -- detect by scanning.
    // Era B (2024-10..2024-12 only): a completely different "Account Number
    // (Edited),Description,Period,Document Type,...,Paid Date,...,
    // LineInclusive,..." layout -- Description (col 1) holds the
    // supplier/payee name, Paid Date (col 14) and LineInclusive (col 22) are
    // the date/amount; no separate service/category column.
    // Era C/D (2025-04 onwards): "FORMAT XLONE REPORT" layout with no Paid
    // Date column at all -- 2025-04..06 (Q1 2025/26) has a "Body Name"
    // column, every quarter from 2025-07 onwards drops it, shifting every
    // later column left by 1. Neither variant has a usable per-row date
    // string; "Period" is a small integer (1-12, FY-month-number, 1=April)
    // that reliably indicates which calendar month each row belongs to
    // within the quarter (checked against Invoice Date across all 4
    // quarters, 2026-06-23) -- a synthetic date is built from it below and
    // written back into the row so the existing paid_date-in-period filter
    // (this is also a one-file-per-quarter, 3-monthly-source-rows-per-file
    // source, like Huntingdonshire's older eras' Q-grouped xlsx) can use it.
    if (str_contains(strtolower($source['council']), 'huntingdonshire')) {
        $hdr0 = $rows !== null ? array_map('strtolower', array_map('trim', $rows[0] ?? []))
                                 : array_map('strtolower', array_map('trim', str_getcsv(strtok($content, "\n") ?: '')));
        // Era C/D header signature: a row carrying both "Supplier Name" and
        // "Invoice Amount (Excluding VAT)". It sits on row 1 when the
        // "FORMAT XLONE REPORT" banner leads the file (2025-04..2026-03), but
        // the FY2026-27 quarterly export DROPPED that banner, putting the same
        // header on row 0. Detect the layout by the header content, not the
        // banner string, so both variants import -- banner-less files used to
        // fall through to the Era A/A2 branch, match nothing and yield 0 rows.
        $cd_hdr_row = -1;
        for ($hi = 0; $hi <= 1; $hi++) {
            $h = $rows !== null ? array_map('strtolower', array_map('trim', $rows[$hi] ?? []))
                                : array_map('strtolower', array_map('trim', str_getcsv((explode("\n", $content)[$hi] ?? ''))));
            if (in_array('supplier name', $h, true)
                && in_array('invoice amount (excluding vat)', $h, true)) { $cd_hdr_row = $hi; break; }
        }
        if (in_array('account number (edited)', $hdr0, true)) {
            // Era B: fixed positions, header always on row 0.
            $col_s = 1; $col_d = 14; $col_a = 22; $col_v = -1;
            $skip = 1;
        } elseif ($cd_hdr_row >= 0) {
            // Era C/D: header on $cd_hdr_row (row 1 with the banner, row 0 once
            // it was dropped). Detect Body Name presence to distinguish C vs D.
            $hdr1 = $rows !== null ? array_map('strtolower', array_map('trim', $rows[$cd_hdr_row] ?? []))
                                     : array_map('strtolower', array_map('trim', str_getcsv((explode("\n", $content)[$cd_hdr_row] ?? ''))));
            $col_period = -1;
            foreach ($hdr1 as $i => $h) {
                if ($h === 'supplier name') $col_s = $i;
                if ($h === 'invoice amount (excluding vat)') $col_a = $i;
                if ($h === 'period') $col_period = $i;
                if (str_contains($h, 'cost centre') && str_contains($h, 'description')) $col_v = $i;
            }
            $skip = $cd_hdr_row + 1;
            // Synthesize a date from the FY-month-number Period column and
            // overwrite $rows/$content in place so process_spend_row's
            // normal date column ($col_d, pointed at a new last column
            // below) sees a real parseable date string.
            $fy_year = (int)substr($source['period'], 0, 4);
            if ((int)substr($source['period'], 5, 2) < 4) $fy_year--; // Jan-Mar belongs to the FY that started the previous calendar year
            $col_d = count($hdr1); // append a synthetic column after the last real one
            $build_date = function (array $row) use ($col_period, $fy_year): string {
                $p = (int)trim((string)($row[$col_period] ?? ''));
                if ($p < 1 || $p > 12) return '';
                $cal_month = (($p + 2) % 12) + 1;
                $cal_year  = $p <= 9 ? $fy_year : $fy_year + 1;
                return sprintf('%04d-%02d-15', $cal_year, $cal_month); // mid-month placeholder, real day unavailable
            };
            if ($rows !== null) {
                foreach ($rows as $ri => $r) {
                    if ($ri < $skip) continue;
                    $rows[$ri][$col_d] = $build_date($r);
                }
            } else {
                $lines = explode("\n", $content);
                foreach ($lines as $li => $line) {
                    if ($li < $skip || trim($line) === '') continue;
                    $r = str_getcsv($line);
                    $lines[$li] = $line . ',' . $build_date($r);
                }
                $content = implode("\n", $lines);
            }
        } else {
            // Era A/A2: scan rows 0-1 for the real header (row 1 when the
            // junk DefnSheetName row is present).
            for ($hi = 0; $hi <= 1; $hi++) {
                $hdr = $rows !== null ? array_map('strtolower', array_map('trim', $rows[$hi] ?? []))
                                       : array_map('strtolower', array_map('trim', str_getcsv((explode("\n", $content)[$hi] ?? ''))));
                if (($hdr[0] ?? '') !== 'body') continue;
                $skip = $hi + 1;
                foreach ($hdr as $i => $h) {
                    if ($h === 'supplier name') $col_s = $i;
                    if ($h === 'inclusive amount') $col_a = $i;
                    if ($h === 'paid date') $col_d = $i;
                    if ($h === 'expense area') $col_v = $i;
                }
                break;
            }
        }
    }

    // South Cambridgeshire District Council: the monthly transparency export
    // changed format at 2026-03. Pre-2026-03 ("plain") files have a title+month
    // preamble then a header row "SUPPLIER NAME,INVOICE DATE,COST CENTRE,
    // SUBJECTIVE,INVOICE AMOUNT" with data immediately after (Supplier=0,
    // Invoice Date=1, Cost Centre=2, Invoice Amount=4) -- the static
    // ct_council_config layout (s=0,a=4,d=1,v=2,skip=1). From 2026-03 the
    // council switched to a "FORMAT XLONE REPORT" export: a multi-row preamble,
    // a header row "*,Supplier Name,,Invoice Date,Cost Centre/Project
    // Description,Subjective description,Invoice Amount (Excluding VAT)", then
    // LIST-prefixed data rows (col0="LIST"), shifting every field right by one
    // (Supplier=1, Invoice Date=3, Cost Centre=4, Invoice Amount=6). South
    // Cambridgeshire is not in the per-source-override list, so detect the
    // header row by content (present in both eras: a row containing "supplier
    // name") and set columns/skip from it, which handles both layouts.
    if (str_contains(strtolower($source['council']), 'south cambridgeshire')) {
        for ($hi = 0; $hi <= 8; $hi++) {
            $hdr = $rows !== null ? array_map('strtolower', array_map('trim', $rows[$hi] ?? []))
                                  : array_map('strtolower', array_map('trim', str_getcsv((explode("\n", $content)[$hi] ?? ''))));
            if (!in_array('supplier name', $hdr, true)) continue;
            $skip = $hi + 1;
            apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
                's' => [['exact', 'supplier name']],
                'd' => [['exact', 'invoice date']],
                'v' => [['substr', 'cost centre']],
                'a' => [['prefix', 'invoice amount']],
            ]);
            break;
        }
    }

    // Waverley Borough Council: one cumulative CSV per FY quarter (3 monthly
    // source rows share each file URL), header "Period,Quarter,Supplier ID,
    // Supplier ID(T),Cipfa1(T),Account(T),Cost Centre(T),Amount,Inv. Date" on
    // row 1 (row 0 is a title banner), skip_rows=2. The "Inv. Date" column
    // (config col_date=8) is NOT a reliable per-row payment date -- late-
    // settled invoices carry dates months/years outside the file's own quarter
    // (the FY2026-27 Q1 file's invoice dates spread across 2024..2026), so
    // bucketing the shared quarterly file by Inv. Date (via the paid_date-in-
    // period allowlist entry above, which Waverley is already in) would badly
    // misbucket and undercount. The "Period" column (col 0) is a YYYYPP fiscal
    // code where YYYY is the FY start year and PP is the FY-month-number
    // (01=April..12=March), and reliably indicates which calendar month each
    // row belongs to (checked against the raw file 2026-08-05). Synthesize a
    // mid-month date from it and point col_date at a new appended column so
    // process_spend_row's generic date parsing + the existing paid_date-in-
    // period filter split the quarterly file correctly by Period rather than by
    // the unreliable invoice date (same technique as Huntingdonshire Era C/D
    // above). Detect the Period column by header name (not fixed position) so
    // the block is robust to layout drift; if no Period header is found, leave
    // the config col_date untouched.
    if (str_contains(strtolower($source['council']), 'waverley')) {
        $col_period = -1;
        if ($rows !== null) {
            for ($hi = 0; $hi <= 3; $hi++) {
                $hdr = array_map('strtolower', array_map('trim', $rows[$hi] ?? []));
                if (!in_array('supplier id(t)', $hdr, true)) continue;
                $skip = $hi + 1;
                foreach ($hdr as $i => $h) {
                    if ($h === 'period') $col_period = $i;
                }
                break;
            }
        } else {
            $lines = explode("\n", $content);
            for ($hi = 0; $hi <= 3; $hi++) {
                $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[$hi] ?? '')));
                if (!in_array('supplier id(t)', $hdr, true)) continue;
                $skip = $hi + 1;
                foreach ($hdr as $i => $h) {
                    if ($h === 'period') $col_period = $i;
                }
                break;
            }
        }
        if ($col_period >= 0) {
            $build_date = function (array $row) use ($col_period): string {
                $v = trim((string)($row[$col_period] ?? ''));
                if (!preg_match('/^(\d{4})(\d{2})$/', $v, $m)) return '';
                $fy_year = (int)$m[1];
                $p = (int)$m[2];
                if ($p < 1 || $p > 12) return '';
                $cal_month = (($p + 2) % 12) + 1;
                $cal_year  = $p <= 9 ? $fy_year : $fy_year + 1;
                return sprintf('%04d-%02d-15', $cal_year, $cal_month); // mid-month placeholder, real day unavailable
            };
            if ($rows !== null) {
                $col_d = count($rows[$skip - 1] ?? []); // append after last header column
                foreach ($rows as $ri => $r) {
                    if ($ri < $skip) continue;
                    $rows[$ri][$col_d] = $build_date($r);
                }
            } else {
                $lines = explode("\n", $content);
                $col_d = count(str_getcsv($lines[$skip - 1] ?? '')); // append after last header column
                foreach ($lines as $li => $line) {
                    if ($li < $skip || trim($line) === '') continue;
                    $r = str_getcsv($line);
                    $lines[$li] = rtrim($line, "\r") . ',' . $build_date($r);
                }
                $content = implode("\n", $lines);
            }
        }
    }

    // Charnwood: columns themselves (Service=0,Supplier Name=7,Payment
    // Date=10,Net amount=11) are static across every quarter 2022-04..
    // 2026-03 including the one xlsx quarter (2023 Q4, Jan-Mar 2023), but
    // that xlsx file has only 1 title row before the header instead of the
    // 4 (blank/period-label/blank/effective-date) rows every CSV quarter
    // has -- under the static skip_rows=5 the xlsx quarter would skip past
    // its own header into real data rows. Detect the header row by scanning
    // for "Service" rather than trusting the fixed skip.
    if (str_contains(strtolower($source['council']), 'charnwood')) {
        for ($hi = 0; $hi <= 5; $hi++) {
            $hdr = $rows !== null ? array_map('trim', $rows[$hi] ?? [])
                                   : str_getcsv((explode("\n", $content)[$hi] ?? ''));
            if (($hdr[0] ?? '') !== 'Service') continue;
            $skip = $hi + 1;
            break;
        }
    }

    // Arun District Council: column *positions* are fully static across the
    // whole 2022-04..2026-05 range (Sysref/System Reference=0, Supplier
    // Name=1, Line/Net Value=2, Paid/Payment Date=3, Directorate/Parent
    // Element Description=4 -- only the header text itself was renamed at
    // some point in 2026, the indices never move), but whether there's a
    // blank row between the header and the first data row toggles
    // unpredictably file-by-file (not date-bounded -- e.g. 2026-01/02/04
    // have one, 2026-03 doesn't, despite all four sharing the post-rename
    // header text) -- confirmed by checking 2022-04, 2024-08, 2025-01,
    // 2025-06, 2025-12, 2026-01..04 (2026-06-23). The one CSV-format month
    // (2026-05) has no blank row either (header on row 0, data from row 1).
    // Detect the header row by matching "supplier name" case-insensitively
    // on column 1, then skip forward past any immediately-following blank
    // row rather than trusting a fixed skip_rows.
    if (str_contains(strtolower($source['council']), 'arun')) {
        for ($hi = 0; $hi <= 2; $hi++) {
            $hdr = $rows !== null ? array_map('trim', $rows[$hi] ?? [])
                                   : str_getcsv((explode("\n", $content)[$hi] ?? ''));
            if (strtolower(trim((string)($hdr[1] ?? ''))) !== 'supplier name') continue;
            $skip = $hi + 1;
            $next = $rows !== null ? ($rows[$skip] ?? []) : str_getcsv((explode("\n", $content)[$skip] ?? ''));
            if (count(array_filter($next, fn($v) => trim((string)$v) !== '')) === 0) {
                $skip++;
            }
            break;
        }
    }

    // Chelmsford: three format eras detected by scanning the header row.
    // Era A (2022-04..2022-12): "FORMAT CIAXLONE REPORT" preamble (4 rows)
    //   with a leading "*" sentinel column; real columns shifted right by 1
    //   (Supplier=1, Date=3, Net Amount=4). Detected by $first === '*'.
    // Era B (2023-01..2023-08, 2023-10+): preamble dropped, Supplier at col0
    //   (Supplier=0, Date=2, Net Amount=3). Detected by $first === 'Supplier'.
    // Era C (2023-09 only -- one-off export variant): a leading "Type" column
    //   prepended, shifting all subsequent columns right by 1
    //   (Supplier=1, Date=3, Net Amount=4). Detected by $first === 'Type'.
    // NOTE: the lone-CR normalisation at line 1726 was incorrectly
    // triggering for CIAXLONE files (whose first row is ~16 KB wide, beyond
    // the old 1 KB window) -- fixed at the same time this block was updated.
    if (str_contains(strtolower($source['council']), 'chelmsford')) {
        for ($hi = 0; $hi <= 4; $hi++) {
            $line = $rows !== null ? implode(',', $rows[$hi] ?? []) : (explode("\n", $content)[$hi] ?? '');
            $hdr = str_getcsv($line);
            $first = trim((string)($hdr[0] ?? ''));
            if ($first === 'Supplier') {
                $skip = $hi + 1;
                $col_s = 0; $col_d = 2; $col_a = 3; $col_v = 1;
                break;
            }
            if ($first === '*') {
                $skip = $hi + 1;
                $col_s = 1; $col_d = 3; $col_a = 4; $col_v = 2;
                break;
            }
            if ($first === 'Type') {
                $skip = $hi + 1;
                $col_s = 1; $col_d = 3; $col_a = 4; $col_v = 2;
                break;
            }
        }
    }

    // Manchester: header row position and column order both vary. Most months
    // have the header on row 0 ("Body Name,Service Area,Expenses Type,Invoice
    // Payment Date,Transaction Number,Supplier Name,Net Amount" from Dec 2024
    // onwards, or the same columns with Net Amount/Supplier Name swapped in
    // 2022-04..2023-03). Some months (e.g. 2023-04 onwards for FY2023-24) have
    // a 2-row preamble (a totals row, then a blank row) before the header.
    // Search rows 0-2 for the row whose first column is "Body Name" and detect
    // columns by name from there.
    if (str_contains(strtolower($source['council']), 'manchester')) {
        for ($hi = 0; $hi <= 2; $hi++) {
            if ($rows !== null) {
                $hdr = array_map('strtolower', array_map('trim', $rows[$hi] ?? []));
            } else {
                $lines = explode("\n", $content);
                $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[$hi] ?? '')));
            }
            if (($hdr[0] ?? '') !== 'body name') continue;
            $skip = $hi + 1;
            apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
                's' => [['substr', 'supplier name']],
                'a' => [['exact', 'net amount']],
                'd' => [['substr', 'invoice payment date']],
                'v' => [['exact', 'service area']],
            ]);
            break;
        }
    }

    // Bournemouth, Christchurch and Poole: column positions are static
    // ("BODY,LOCAL AUTHORITY DEPARTMENT,DESCRIPTION 1,DESCRIPTION 2,DATE,
    // AMOUNT,BENEFICIARY") but one file (Jul 2024) has 2 blank rows before
    // the header instead of the usual 0, shifting the data rows down by 2
    // under the static skip_rows=1. Detect the header row by scanning for
    // "body" rather than trusting a fixed skip.
    if (str_contains(strtolower($source['council']), 'bournemouth')
        || str_contains(strtolower($source['council']), 'christchurch')) {
        $cand = header_candidate_rows($rows, $content, 4);
        $hi = find_header_row($cand, fn($h) => ($h[0] ?? '') === 'body');
        if ($hi !== null) $skip = $hi + 1;
    }

    // South Tyneside: most CSV months have the header directly on row 0
    // ("Name of Organisation,Body,Service Area,Service Detail,Spend
    // Description,Date,Amount (Net of VAT),Unrecoverable VAT,Supplier Name,
    // Spend,Spend,Transaction Reference" -- matches static config
    // col_supplier=8/col_amount=6/col_date=5/col_service=3 with skip_rows=1),
    // but the 2025-06/2025-07 "Accessible Format" XLSX exports have a title
    // row ("Council Spending over £500: <Month> <Year>") on row 0 with the
    // real header on row 1. Column positions are identical either way --
    // only skip_rows needs to shift when a title row is present.
    if (str_contains(strtolower($source['council']), 'tyneside')) {
        if ($rows !== null) {
            $hdr0 = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $lines = explode("\n", $content);
            $hdr0 = array_map('strtolower', array_map('trim', str_getcsv($lines[0] ?? '')));
        }
        if (($hdr0[0] ?? '') !== 'name of organisation') $skip = 2;
    }

    // East Sussex: quarterly XLSX with a title row (0), an info row (1) and
    // the real header on row 2, data from row 3 (skip_rows=3). The column
    // layout has changed repeatedly (an extra leading "Vendor" column in
    // Q1/Q2 2022-23, an extra "PO" column in 2024-10, and a completely
    // different SAP export -- Supplier Name/Paid Amount/Nominal
    // Description/Payment Date -- from Q1 2025-26 onwards), so detect
    // columns by header name on row 2 rather than fixed positions.
    if (str_contains(strtolower($source['council']), 'east sussex')) {
        $cand = header_candidate_rows($rows, $content, 3);   // header fixed on row 2
        if (isset($cand[2])) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[2], [
                's' => ['name', 'supplier name'],
                'a' => ['amount', 'paid amount'],
                'd' => ['payment date'],
                'v' => ['department', 'nominal description'],
            ]);
        }
    }

    // Barnet: header layout changed at the FY2023/24 -> FY2024/25 boundary.
    // FY2022/23 and FY2023/24: "Directorate,Department,Expenditure Type,
    // Ref.Doc.1,Vendor Name,Expenditure Amount (exc VAT),Payment Date[,Comment
    // from Database...]" (dates d/m/Y, amounts sometimes quoted with commas).
    // FY2024/25 and FY2025/26: "Cost Centre Hierarchy - Directorate,Cost
    // Centre Hierarchy - Department,Expenditure Type,Payment Reference
    // Number,Payment Number,Supplier Name,Distribution Amount,Payment Date"
    // (dates d-M-Y, mojibake "£" prefix on amounts -- already stripped by the
    // generic amount regex). March 2026 additionally swaps the Expenditure
    // Type/Payment Reference Number columns and pads " Distribution Amount "
    // with spaces -- detect everything by header name on row 0.
    if (str_contains(strtolower($source['council']), 'barnet')) {
        $cand = header_candidate_rows($rows, $content, 1);
        if ($cand) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[0], [
                's' => ['vendor name', 'supplier name'],
                'a' => ['expenditure amount (exc vat)', 'distribution amount'],
                'd' => ['payment date'],
                'v' => ['department', 'cost centre hierarchy - department'],
            ]);
        }
    }

    // Wakefield: 16 quarterly "Supplier Spend" CSVs (FY2022-23 to FY2025-26).
    // FY2022-23 quarters use a 25-col SERCOP layout (title row 0, header row
    // 1, data row 2+): "...,Directorate,Service (Sercop),...,Supplier
    // Name,...,Payment Date,TransNo,Seq No,Net Amount,...". FY2023-24 onwards
    // use an 18-col SAP layout: "Organisation Name,Organisation Code,
    // Effective Date,Cost Centre Narrative,Supplier Name,...,Date,TransNo,Seq
    // No,Amount,...". The SAP layout's header sits on row 0 (2025-26
    // quarters, no title) or row 3 (title + 2 blank rows, earlier quarters).
    // Search rows 0-5 for the row starting "Organisation Name" and detect
    // columns by header name from there.
    if (str_contains(strtolower($source['council']), 'wakefield')) {
        $cand = header_candidate_rows($rows, $content, 6);
        $hi = find_header_row($cand, fn($h) => ($h[0] ?? '') === 'organisation name');
        if ($hi !== null) {
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[$hi], [
                's' => ['supplier name'],
                'a' => ['net amount', 'amount'],
                'd' => ['payment date', 'date'],
                'v' => ['directorate', 'cost centre narrative'],
            ]);
        }
    }

    // Hammersmith & Fulham: quarterly XLSX, one file covering 3 calendar
    // months (date-filtered to the matching period by the generic
    // paid_date-in-period block above). Column layout changed at the
    // 2023-04-30/2023-07-01 boundary: Q1 2022-23 through Q1 2023-24
    // (2022-04 to 2023-06) use a 9-col SAP-style export "Organisation,
    // Supplier Name,Document Number,Payment Date,Cost Center/Capital
    // Project,Cost Center/Capital Project Description,GL Account,GL Account
    // Description,Amount £ (Ex VAT)" -- amount in col 8, service text in
    // col 5 ("Cost Center/Capital Project Description"). Q2 2023-24 onwards
    // (2023-07+) drop the GL Account/Cost Center code columns down to a
    // 7-col layout "Organisation,Supplier Name,<reference>,Payment Date,
    // <service description>,GL Account Description,Amount £ (Ex VAT)" --
    // amount in col 6, service in col 4 (this matches the static
    // ct_council_config defaults). Detect by header name on row 0 (always
    // present, no title rows) rather than trusting column count alone.
    if (str_contains(strtolower($source['council']), 'hammersmith')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
            's' => [['exact', 'supplier name']],
            'd' => [['exact', 'payment date']],
            'a' => [['substr', 'amount']],
            'v' => [['exact', 'cost center/capital project description'], ['exact', 'service area/capital project description']],
        ]);
        $skip = 1;
    }

    // Cumberland Council: most months use an 11-column CSV/XLSX layout
    // (title row 0, header row 1, data from row 2): "Company,coded supplier,
    // Supplier Name,Date,System Reference,Directorate Description,Directorate
    // Area,Expenditure Description,Type of Expenditure,Line Number,Line
    // Amount". March 2026 onwards gained an extra "Supplier Number" col at
    // position 1 and "Transaction Reference" at position 8, shifting Supplier
    // Basildon Borough Council: XLSX files with 10 columns; layout changed across
    // years (header row shifts between row 4 and row 5; 2026+ moved columns around).
    // Detect by scanning rows 0-7 for the first row containing "supplier name".
    if (str_contains(strtolower($source['council']), 'basildon')) {
        for ($hi = 0; $hi <= 7; $hi++) {
            if ($rows !== null) {
                $hdr = array_map(fn($h) => preg_replace('/\\s+/', ' ', strtolower(trim($h))), $rows[$hi] ?? []);
            } else {
                $lines = explode("\n", $content);
                $hdr = array_map(fn($h) => preg_replace('/\\s+/', ' ', strtolower(trim($h))), str_getcsv($lines[$hi] ?? ''));
            }
            if (!in_array('supplier name', $hdr) && !in_array('supplier_name', $hdr) && !in_array('suppler name', $hdr)) continue;
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $hdr, [
                's' => ['supplier name', 'supplier_name', 'suppler name'],
                'a' => ['total invoice amount', 'invoice total'],
                'd' => ['date paid'],
                'v' => ['narrative'],
            ]);
            break;
        }
    }

    // Name from col 2->3 and Line Amount from col 10->12. Detect by scanning
    // header row 1 for column names.
    if (str_contains(strtolower($source['council']), 'cumberland')) {
        $cand = header_candidate_rows($rows, $content, 2);   // header fixed on row 1
        if (isset($cand[1])) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[1], [
                's' => ['supplier name'],
                'a' => ['line amount'],
                'd' => ['date'],
                'v' => ['expenditure description'],
            ]);
        }
    }

    // London Borough of Croydon: monthly XLSX exports in two layouts.
    // 2022 files have a simplified 10-column set (skip_rows=5, Amount at col
    // 8, Subjective Description at col 6). 2023+ files have a full Cost
    // Centre hierarchy (skip_rows=4, Amount at col 21, Subjective Description
    // at col 18). Both have Payment Date at col 0, Vendor Name at col 1.
    // Detect by scanning rows 0-5 for the first row with "payment date" as
    // col A; static config covers 2023+ and auto-detect overrides for 2022.
    if (str_contains(strtolower($source['council']), 'croydon')) {
        $cand = header_candidate_rows($rows, $content, 6);
        $hi = find_header_row($cand, fn($h) => ($h[0] ?? '') === 'payment date');
        if ($hi !== null) {
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[$hi], [
                's' => ['vendor name'],
                'a' => ['amount'],
                'd' => ['payment date'],
                'v' => ['subjective description'],
            ]);
        }
    }

    // Isle of Wight Council: column layout has changed repeatedly. Standard
    // 10-col layout has col_d=5, col_a=8, col_s=9. Some months insert an extra
    // short-code column after col 0, shifting all columns right by one (11 cols).
    // Feb 2024 has an 18-col SAP export with supplier at col 13, amount at 11.
    // Jan 2025 has a 21-col layout with two header rows, supplier at 17, amount
    // at 15, date at 9. Detect by scanning rows 0-1 for 'Supplier Name' header.
    if (str_contains(strtolower($source['council']), 'isle of wight')) {
        $cand = header_candidate_rows($rows, $content, 2);
        $hi = find_header_row($cand, fn($h) => in_array('supplier name', $h, true));
        if ($hi !== null) {
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[$hi], [
                's' => ['supplier name'],
                'd' => ['date'],
                'a' => ['amount'],
                'v' => ['service area'],
            ]);
        }
    }

    // Newcastle City Council: monthly CSVs. Row 0 is a title ("Newcastle City
    // Council Invoices over £250 paid in Month YYYY"), row 1 is blank, row 2 is
    // the column header, data from row 3. Supplier Name and Paid Date are always
    // cols 4 and 3; Total (excludes VAT) was col 9 in the 10-column layout
    // (Apr 2022 – Sep 2024) but moved to col 11 when two capital-code columns
    // were inserted in Oct 2024. Date format changes from d/m/Y to Excel serial
    // in Jan 2026 (handled generically). Detect by header name.
    // Guard explicitly excludes Newcastle-Under-Lyme (different format entirely,
    // handled via per-source override block below).
    if (str_contains(strtolower($source['council']), 'newcastle')
        && !str_contains(strtolower($source['council']), 'newcastle-under-lyme')) {
        $cand = header_candidate_rows($rows, $content, 3);   // header fixed on row 2
        if (isset($cand[2])) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[2], [
                's' => ['supplier name'],
                'd' => ['paid date'],
                'a' => ['total (excludes vat)'],
                'v' => ['directorate'],
            ]);
        }
    }

    // Wiltshire Council: 2022-04 to 2023-10 used a 13-col layout with an extra
    // "Transaction Number" column, shifting Supplier Name/Amount/Service Area
    // to indices 5/4/9. The 2023-11+ layout (one-off Nov-2023 9-col variant
    // and the stable Dec-2023 onwards 9-col layout) has no such column and
    // both share identical indices 4/3/6 despite differing header text
    // ("Date Paid"/"Amount"/"Supplier Name" vs "Payment Date"/"Invoice Amount
    // Paid"/"Supplier or Party Name") -- already the static config default,
    // so only the older layout needs an override here.
    if (str_contains(strtolower($source['council']), 'wiltshire')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        if (in_array('transaction number', $hdr, true)) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $hdr, [
                's' => ['supplier name'],
                'a' => ['amount'],
                'd' => ['date paid'],
                'v' => ['service area'],
            ]);
        }
    }

    // St Helens Borough Council: two header eras. 2022-04 to 2024-03 use a
    // 9/10-col layout with a title row (0), a blank row (1) and the real
    // header on row 2: "Body Name,Department,Service Label,Portfolio,
    // Expenditure Category,Supplier Name,Date,Net Amount[,Capital or
    // Revenue],Narrative" (Supplier Name/Date/Net Amount always at indices
    // 5/6/7 regardless of whether the optional "Capital or Revenue" column
    // is present, since it always sits after Net Amount). The 2023-02
    // workbook's first physical sheet uniquely reorders this to "...,
    // Expenditure Category,Date,Net Amount,Capital or Revenue,Supplier
    // Name,Narrative" (no title row) -- detected separately below since the
    // generic 2022-04..2024-03 scan for a row 2 header would silently grab
    // the wrong row. 2024-04 onwards switch to "Body Name,Directorate,
    // Division,Service Area,Expenditure Category,Supplier Name,Pay Date,
    // Amount,Transaction reference,Narrative" with no title row (header on
    // row 0) and Supplier Name/Pay Date/Amount at indices 5/6/7 -- the same
    // positions as the older era, so no override is needed for most months,
    // but 2025-09 (Sept_2025.xlsx) uniquely drops the leading "Body Name"
    // column, shifting everything left by one (Supplier Name/Pay Date/
    // Amount at 4/5/6). Detect columns by header name (scanning rows 0-3)
    // rather than trusting fixed positions, to cover both eras and their
    // one-off variants in a single block.
    if (str_contains(strtolower($source['council']), 'st helens')) {
        $cand = header_candidate_rows($rows, $content, 4);
        $hi = find_header_row($cand, fn($h) => in_array('supplier name', $h, true));
        if ($hi !== null) {
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[$hi], [
                's' => ['supplier name'],
                'a' => ['net amount', 'amount', 'sum of amount', 'total'],
                'd' => ['date', 'pay date'],
                'v' => ['expenditure category'],
            ]);
        }
    }

    // Bury Metropolitan Borough Council: the standard XLSX layout
    // "Authority,Pro Class,Supplier,Date,Dept,Cost Code,Invoiced (Amount)"
    // (Supplier=2, Date=3, Amount=6) is handled correctly by the existing
    // column logic for every month EXCEPT 2025-08, which uniquely inserts an
    // extra "InvoiceNo" column at index 3. That shifts Date to index 4 and the
    // amount ("Invoiced (Amount)") to index 7, so the static config silently
    // mis-reads it (col 6 = "Cost Code" text -> 0 tech matches, col 3 =
    // InvoiceNo -> no usable date). Detect this specific shifted layout by the
    // presence of a standalone "InvoiceNo" header column and, only then, remap
    // Supplier/Date/Amount by header name. Every other Bury month is left
    // entirely untouched (no regression risk to the working periods).
    if (str_contains(strtolower($source['council']), 'bury metropolitan')) {
        if ($rows !== null) {
            $bury_hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $bury_first = strtok($content, "\n");
            $bury_hdr = array_map('strtolower', array_map('trim', str_getcsv($bury_first ?: '')));
        }
        $bury_has_invoiceno = false;
        foreach ($bury_hdr as $h) {
            if (str_contains($h, 'invoice') && !str_contains($h, 'invoiced')) { $bury_has_invoiceno = true; break; }
        }
        if ($bury_has_invoiceno) {
            foreach ($bury_hdr as $i => $h) {
                if (str_contains($h, 'supplier')) $col_s = $i;
                if ($h === 'date')                $col_d = $i;
                if (str_contains($h, 'invoiced'))  $col_a = $i; // "Invoiced (Amount)"
            }
        }
    }

    // Kent: auto-detect columns from row 1 (row 0 is title, row 1 is actual header)
    if (str_contains(strtolower($source['council']), 'kent')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[1] ?? []));
        } else {
            $lines = explode("\n", $content);
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[1] ?? '')));
        }
        apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
            's' => [['substr', 'supplier']],
            'a' => [['substr', 'invoice net'], ['exact', 'net value']],
            'd' => [['substr', 'payment date']],
            'v' => [['substr', 'directorate']],
        ]);
        $skip = 2;
    }

    // Lincolnshire: auto-detect columns (layout has changed several times, 2023-2026)
    if (str_contains(strtolower($source['council']), 'lincolnshire')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        foreach ($hdr as $i => $h) {
            // "Supplier Description" is an alternate vocabulary used in some
            // Datopian-format exports (e.g. 2024-06, 2024-12, 2025-01 to 2025-05)
            // that have a different schema from the BeneficiaryName layout used
            // by working periods. Both resolve to col20/col21 in the 22-col files.
            if (in_array($h, ['beneficiaryname', 'beneficiary name', 'supplier description'], true)) $col_s = $i;
            if ($h === 'amount' || $h === 'net amount') $col_a = $i;
            if (in_array($h, ['effectivedate', 'effective date', 'payment date'], true)) $col_d = $i;
            if (in_array($h, ['organisationalunit', 'service area'], true)) $col_v = $i;
        }

        // 2024-03/04/05 and 2025-09 exports have a data-row column-order bug
        // around the "LCC_Period" field (a "YYYYMM" code): either it moves to
        // just before "Amount" (2024-03/04/05, leaving col 20 = "202412"-style
        // code) or it swaps with "OrganisationalUnit" and shifts everything
        // after it left by one (2025-09, leaving col 20 empty). In both cases
        // "BeneficiaryName" ends up one column earlier than the header says
        // (19, not 20) and "OrganisationalUnit" ends up at 14, not 15 --
        // "Amount" at 21 is unaffected either way. Detect by sampling the
        // first data row: if the header-indicated supplier column looks wrong
        // (empty or a 6-digit period code) while the column before it looks
        // like a real name, shift col_s and col_v back by one.
        $sample_row = null;
        if ($rows !== null) {
            $sample_row = $rows[1] ?? null;
        } else {
            $lines = explode("\n", $content, 3);
            if (isset($lines[1])) $sample_row = str_getcsv($lines[1]);
        }
        if ($sample_row !== null && isset($sample_row[$col_s], $sample_row[$col_s - 1])) {
            $v_at  = trim((string)$sample_row[$col_s]);
            $v_bef = trim((string)$sample_row[$col_s - 1]);
            if (($v_at === '' || preg_match('/^\d{6}$/', $v_at)) && $v_bef !== '' && !preg_match('/^\d{6}$/', $v_bef)) {
                $col_s--;
                if ($col_v > 0) $col_v--;
            }
        }
    }

    // Cornwall: auto-detect columns (header layout/column count changes almost every month)
    // Cornwall's "Net Amount" column is an invoice-header total repeated on
    // every line of a multi-line invoice (e.g. an 11-line Computacenter
    // invoice in 2024-05 has the same £3,068,844.46 "Net Amount" on all 11
    // rows, but 11 different "Line Amount" values that sum to it) -- summing
    // "Net Amount" massively over-counts multi-line invoices. "Line Amount"
    // is the correct per-row figure (equals "Net Amount" for single-line
    // invoices, sums to it for multi-line ones). Use "Line Amount".
    if (str_contains(strtolower($source['council']), 'cornwall')) {
        $cand = header_candidate_rows($rows, $content, 1);
        if ($cand) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[0], [
                's' => ['supplier name'],
                'a' => ['line amount'],       // deliberately NOT 'net amount' (repeated invoice total)
                'd' => ['payment date'],      // last match wins if duplicated
                'v' => ['directorate'],
            ]);
        }
    }

    // Stockport: format changed Apr 2025 - old 6-col 'Over £500' (col_supplier=1,col_amount=5,col_date=4,col_service=2,
    // matches config defaults) vs new 8-col 'All Spend' format starting with transaction_id.
    // 2022-07 and 2022-09 use a one-off 5-col variant of the old format with no
    // 'invoice_date' column at all (Merchant Category,Supplier,Service,Summary of
    // Purpose of Expenditure,net_amount) -- detect by header name and shift
    // col_amount to 4 with no date column.
    if (str_contains(strtolower($source['council']), 'stockport')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        // Strip a UTF-8 BOM from the first header cell, if present.
        if (isset($hdr[0])) $hdr[0] = preg_replace('/^\xEF\xBB\xBF/', '', $hdr[0]);
        if (($hdr[0] ?? '') === 'transaction_id') {
            // transaction_id,Merchant Category,Supplier,Directorate,Service Area,Summary...,paid_date,net_amount
            $col_s = 2; $col_a = 7; $col_d = 6; $col_v = 4;
        } elseif (in_array('net_amount', $hdr, true) && !in_array('invoice_date', $hdr, true)) {
            // Merchant Category,Supplier,Service,Summary of Purpose of Expenditure,net_amount
            $col_a = array_search('net_amount', $hdr, true);
            $col_d = -1;
        }
    }

    // Bristol: 2025-11 file ("copy-of-supplier-spend-nov-2025-v1-1") has Amount/Pay Date columns
    // swapped relative to every other month (Body,Body Name,Name,Pay Date,Transaction Number,Amount,...)
    if (str_contains(strtolower($source['council']), 'bristol')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        if (($hdr[3] ?? '') === 'pay date') {
            $col_a = 5; $col_d = 3;
        }
    }

    // Herefordshire: most months use a 10-col layout (Updated/Trans date, TT,
    // Trans No, Amount, Ap/Ar ID, Ap/Ar ID (T) [=supplier], Period, Expense Area
    // (T) [=service], Cipfa (T), Indflag/Individual Supplier (T)) matching the
    // static config (s=5,a=3,d=0,v=7). But 2024-11, 2025-04 and 2025-05 (and any
    // future months) DROP the "TT" column entirely, shifting everything after
    // "Trans No" left by one (supplier=4, amount=2, service=6) -- static config
    // then reads Period as the supplier and Cipfa as service, matching no
    // patterns => 0 rows. Detect columns by header name so both layouts resolve
    // correctly. Supplier is the "(T)" text-name variant of the Ap/Ar/Supplier
    // ID column -- NOT "Individual Supplier (T)"/"Indflag" which is a Yes/No flag.
    if (str_contains(strtolower($source['council']), 'herefordshire')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        foreach ($hdr as $i => $h) {
            if ($h === 'amount') $col_a = $i;
            if (in_array($h, ['updated', 'trans date'], true)) $col_d = $i;
            if (in_array($h, ['expense area (t)', 'expense area(t)'], true)) $col_v = $i;
            // Supplier = the "(T)" name column for Ap/Ar ID or Supplier ID,
            // excluding the "Individual Supplier"/Indflag Yes/No indicator.
            if ((str_contains($h, 'ap/ar id') || str_contains($h, 'supplier id'))
                && str_contains($h, '(t)')
                && !str_contains($h, 'individual')) {
                $col_s = $i;
            }
            // "Supplier name" (2025-10 xlsx) carries the name directly, no "(T)".
            if ($h === 'supplier name') $col_s = $i;
        }
    }

    // Islington: header layout/date format changed several times FY2023-24 to FY2025-26
    // (8-col with "Service", 8-col with "Capital or Revenue" instead of Service, 6-col with
    // no service-type column, and the 2026 "Input Date/Directorate/Nominal Description" layout).
    // Detect supplier/amount/date columns by header name, and pick the best available
    // "service" column with a fallback order.
    if (str_contains(strtolower($source['council']), 'islington')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        $col_v = -1;
        foreach ($hdr as $i => $h) {
            if ($h === 'supplier name') $col_s = $i;
            if (str_contains($h, 'amount')) $col_a = $i;
            if (str_contains($h, 'date')) $col_d = $i;
            if ($h === 'service') $col_v = $i;
        }
        if ($col_v < 0) {
            foreach ($hdr as $i => $h) { if ($h === 'nominal description') { $col_v = $i; break; } }
        }
        if ($col_v < 0) {
            foreach ($hdr as $i => $h) { if ($h === 'department') { $col_v = $i; break; } }
        }
    }

    // Hertfordshire: column order varies between the "over £250" quarterly/monthly files
    // (2022-2025) and the "over £500" monthly files (Apr 2025+) — supplier/beneficiary-id
    // and procurement/purpose columns are swapped between formats. Detect by header name.
    if (str_contains(strtolower($source['council']), 'hertfordshire')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
            $data1 = $rows[1] ?? [];
        } else {
            $lines = explode("\n", $content, 3);
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[0] ?? '')));
            $data1 = str_getcsv($lines[1] ?? '');
        }
        foreach ($hdr as $i => $h) {
            if ($h === 'supplier (beneficiary)') $col_s = $i;
            if ($h === 'net amount') $col_a = $i;
            if ($h === 'date') $col_d = $i;
            if ($h === 'purpose of expenditure (expenditure category)') $col_v = $i;
        }
        // Several months' published headers have "Beneficiary ID" and
        // "Supplier (Beneficiary)" the wrong way round compared to most
        // other months (Supplier immediately before Beneficiary ID).
        // Sometimes that reordered header is itself wrong relative to the
        // actual data columns (confirmed for Jan/Feb 2025: header says
        // col5=Supplier but the data has the numeric beneficiary ID
        // there and the supplier name in col4); other months with the
        // same header order have correctly-aligned data (e.g. Feb 2026).
        // Disambiguate using the data: if the detected supplier column's
        // first-row value is purely numeric (a beneficiary ID, not a
        // name), the real supplier column is one to the left.
        if ($col_s >= 1 && ($hdr[$col_s - 1] ?? '') === 'beneficiary id'
            && ctype_digit(trim((string)($data1[$col_s] ?? 'x')))) {
            $col_s--;
        }
    }

    // Blackpool: header row position and column layout changed across financial years
    // (2022/23: 1 header row, 13 cols ending "...4CCN - Level 4 Cost Centre Name";
    //  2023/24 & 2024/25: 2 header rows — row 0 is a "FORMAT CIAXLONE REPORT" banner,
    //  row 1 is the real header starting "*,Body Name,Supplier Name,...";
    //  2025/26: 1 header row with "Body Name" but no leading "*";
    //  2026/27: 1 header row with no "Body Name" column at all;
    //  2023-12: no usable header at all — row 0 is a stray "DefnSheetName=..."
    //  line and data starts on row 1; 2024-01: a single header row "Body Name,,
    //  Supplier Code,Transaction Ref/No,Payment Date,Gross Value (Detail),..."
    //  where the Supplier Name column header is blank). For these last two,
    //  Supplier Name is col 1, Gross Value col 5, Payment Date col 4, Service
    //  Label col 7.
    // Find whichever of rows 0/1 contains "Supplier Name", or failing that
    // whichever row's first column is "Body Name" (a header) or the council's
    // own name (the first data row, with no header present), and detect
    // columns accordingly.
    if (str_contains(strtolower($source['council']), 'blackpool')) {
        $council_lc = strtolower(trim($source['council']));
        for ($hi = 0; $hi <= 1; $hi++) {
            if ($rows !== null) {
                $hdr = array_map('strtolower', array_map('trim', $rows[$hi] ?? []));
            } else {
                $lines = explode("\n", $content);
                $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[$hi] ?? '')));
            }
            if (in_array('supplier name', $hdr, true)) {
                $skip = $hi + 1;
                foreach ($hdr as $i => $h) {
                    if ($h === 'supplier name') $col_s = $i;
                    if (str_contains($h, 'gross value')) $col_a = $i;
                    if (str_contains($h, 'date paid') || str_contains($h, 'payment date')) $col_d = $i;
                    if ($h === 'service label' || str_contains($h, 'cost centre name')) $col_v = $i;
                }
                break;
            }
            if (($hdr[0] ?? '') === 'body name' || ($hdr[0] ?? '') === $council_lc) {
                $skip = ($hdr[0] === 'body name') ? $hi + 1 : $hi;
                $col_s = 1;
                $col_a = 5;
                $col_d = 4;
                $col_v = 7;
                break;
            }
        }
    }

    // Westmorland and Furness: "Trade Suppliers over £250" monthly CSVs use 2
    // layouts — an 11-column layout (...,Directorate Area,Expenditure
    // Description,Type of Expenditure,Line Number,Line Amount) and a 13-column
    // layout with 2 extra "Cost Centre"/"Cost Centre Description" columns
    // inserted before Expenditure Description, which shifts Line Amount from
    // index 10 to index 12. The header row is preceded by either a title row
    // (11-col) or a title row + blank row (13-col). Detect the header row and
    // columns by name.
    if (str_contains(strtolower($source['council']), 'westmorland')) {
        $cand = header_candidate_rows($rows, $content, 3);
        $hi = find_header_row($cand, fn($h) => in_array('supplier name', $h, true));
        if ($hi !== null) {
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[$hi], [
                's' => ['supplier name'],
                'd' => ['date'],
                'v' => ['expenditure description'],
                'a' => ['line amount'],
            ]);
        }
    }

    // Hackney: monthly "Spend over £250" reports are individual Google Sheets with
    // a consistent layout (1. YEAR,2. MONTH,3. DATE INCURRED,4. DATE PAID,
    // 5. BENEFICIARY,6. SUPPLIER NO.,7. DEPARTMENT,8. PURPOSE OF EXPENDITURE,
    // 9. MERCHANT CATEGORY,Total), but one month (2023-11) was uploaded as a
    // "Purchase Orders" report instead (YEAR,MONTH,PURCHASE ORDER REFERENCE,
    // ORDER DATE,PUBLISHED SUPPLIER NAME,TITLE OF AGREEMENT,PROVIDED GOODS &
    // SERVICES,SME Y/N,VCSO STATUS,COST CENTRE / DEPARTMENT,Total) — with the
    // static config this points col_amount at "COST CENTRE / DEPARTMENT" (a
    // cost-centre code, not an amount). Detect columns by header name so both
    // layouts work; "Total" is the last column in both.
    if (str_contains(strtolower($source['council']), 'hackney')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        $col_d = -1;
        foreach ($hdr as $i => $h) {
            if (str_contains($h, 'beneficiary') || str_contains($h, 'supplier name')) $col_s = $i;
            if ($h === 'total') $col_a = $i;
            if (str_contains($h, 'purpose of expenditure') || str_contains($h, 'provided goods')) $col_v = $i;
            if (str_contains($h, 'date paid')) $col_d = $i;
        }
        if ($col_d < 0) {
            foreach ($hdr as $i => $h) { if (str_contains($h, 'order date')) { $col_d = $i; break; } }
        }
    }

    // Essex: quarterly "Day to day spending" XLSX workbooks have one sheet per
    // month (selected via the #sheet=N URL fragment) with a fixed column
    // layout (Date,Name,Value,Function,Source,Spend Description,Merchant
    // Group,Merchant Category), but the header row's position in $rows varies:
    // parse_xlsx()'s row regex drops any spreadsheet row with no <c> elements
    // (e.g. fully-blank rows in the summary block above the header), so the
    // header lands at $rows[5] in some files and $rows[7] in others (locally
    // reconstructed .xls→.xlsx conversions drop no rows). Find the header by
    // content instead of assuming a fixed offset.
    if (str_contains(strtolower($source['council']), 'essex')) {
        for ($hi = 0; $hi <= 10; $hi++) {
            $hdr = array_map('strtolower', array_map('trim', $rows[$hi] ?? []));
            if (($hdr[0] ?? '') === 'date' && ($hdr[1] ?? '') === 'name') {
                $skip = $hi + 1;
                $col_d = 0; $col_s = 1; $col_a = 2; $col_v = 5;
                break;
            }
        }
    }

    // Suffolk: most months are 10-col CSV (Body Name,Body,Directorate,Service
    // Area,Sub-Service Area,Sub-description,Payment Date,Sub Amount,Invoice/Inv
    // Amount,Supplier Name [Revised]) but 2024 was republished as 8-col XLSX
    // (Directorate,Service Area,Sub-Service Area,Sub-description,Payment Date,
    // Sub Amount,Invoice Amount,Supplier Name Revised) with a title row before
    // the header. Detect the header by content (rows 0-1) and map columns by
    // name rather than relying on fixed indices.
    if (str_contains(strtolower($source['council']), 'suffolk')) {
        for ($hi = 0; $hi <= 1; $hi++) {
            if ($rows !== null) {
                $hdr = array_map('strtolower', array_map('trim', $rows[$hi] ?? []));
            } else {
                $lines = explode("\n", $content);
                $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[$hi] ?? '')));
            }
            foreach ($hdr as $i => $h) {
                if (str_contains($h, 'supplier name')) {
                    $col_s = $i;
                    $skip  = $hi + 1;
                    foreach ($hdr as $j => $h2) {
                        if ($h2 === 'directorate') $col_v = $j;
                        if ($h2 === 'payment date') $col_d = $j;
                        if (str_contains($h2, 'amount') && $h2 !== 'sub amount') $col_a = $j;
                    }
                    break 2;
                }
            }
        }
    }

    // Barking and Dagenham: "Amounts paid greater than £250" monthly files have
    // a title row then a header row, in two layouts: Jan-Aug 2022 used
    // "Payment Date,Vendor Name,Vendor Type,Cost Centre Code,Cost Centre
    // Description,Department,Division,Subjective Description,Amount,Non
    // Recoverable VAT" (10 cols); Sep 2022 onwards uses "Payment Date,Supplier,
    // Category,Cost Centre,Cost Centre Description,Cost Centre Parent,Cost
    // Centre Parent,Cost Centre Parent,Nominal Description,Gross,Vat" (11 cols,
    // one period as XLSX). Map columns by header name from row 1.
    if (str_contains(strtolower($source['council']), 'barking')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[1] ?? []));
        } else {
            $lines = explode("\n", $content);
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[1] ?? '')));
        }
        apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
            's' => [['substr', 'supplier'], ['substr', 'vendor name']],
            'd' => [['substr', 'payment date']],
            'a' => [['exact', 'gross'], ['exact', 'amount']],
            'v' => [['substr', 'nominal description'], ['substr', 'subjective description']],
        ]);
        $skip = 2;
    }

    // Bolton: monthly "Expenditure over £500" CSVs keep a fixed 6-column shape
    // for most months (Authority,Service Area,<description col>,<date/payment
    // col>,<amount col>,Supplier -> indices 2-5 match the static config), but
    // 2022-09 inserts an extra "Cost Center Code" column (7 cols total,
    // shifting description/date/amount/supplier to 3-6) and 2023-07 reorders
    // columns (Supplier before Payment/Amount). Detect supplier/amount/date/
    // description columns by header name so both layouts work.
    if (str_contains(strtolower($source['council']), 'bolton')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
            's' => [['exact', 'supplier']],
            'a' => [['substr', 'amount']],
            'd' => [['substr', 'payment'], ['substr', 'invoice']],
            'v' => [['substr', 'description']],
        ]);
    }

    // Coventry: most months use a 14-column "Proclass,Proclass(T),Supplier,
    // Supplier(T),Directorate(T),Cost Centre,Cost Centre(T),Account Code,
    // Account Code(T),Transaction No,Period,Transaction Date,Amount,Comments"
    // layout (col_supplier=3, col_amount=12, col_date=11, col_service=1,
    // matching the static config), but 2024-12, 2025-06 to 2025-09, 2025-11,
    // 2025-12 and 2026-02 insert an extra "Supplier Group" column before
    // "Supplier", shifting Supplier(T)/Transaction Date/Amount to 4/12/13 and
    // demoting "Transaction Date" out of the static col_date=11 slot (which
    // then points at "Period", e.g. "202503" — not a date, so every row fails
    // date parsing and 0 rows get stored). Detect columns by header name so
    // both layouts work.
    if (str_contains(strtolower($source['council']), 'coventry')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
            'v' => [['exact', 'proclass(t)']],
            's' => [['prefix', 'supplier(t']],
            'd' => [['exact', 'transaction date']],
            'a' => [['exact', 'amount']],
        ]);
    }

    // Portsmouth: most months use a 13-column "Body Name,Body,Service Area
    // Categorisation,Service Division Categorisation,Responsible Unit,
    // Expenses Type,Detailed Expenses Type,Payment Date,Transaction Number,
    // Payment Amount,Supplier Type,Supplier Name,Supplier Id" layout
    // (col_supplier=11, col_amount=9, col_date=7, col_service=2, matching the
    // static config), but 2024-10's export omits the "Body" column entirely,
    // giving 12 columns and shifting Supplier Name/Payment Amount/Payment
    // Date/Service Area Categorisation to 10/8/6/1 — under the static config
    // this points col_supplier at "Supplier Id" (numeric), col_amount at
    // "Supplier Type" (text) and col_date at "Transaction Number", so every
    // row fails matching/parsing and 0 rows get stored. Detect columns by
    // header name so both layouts work.
    if (str_contains(strtolower($source['council']), 'portsmouth')) {
        $cand = header_candidate_rows($rows, $content, 1);
        if ($cand) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[0], [
                's' => ['supplier name'],
                'a' => ['payment amount'],
                'd' => ['payment date'],
                'v' => ['service area categorisation'],
            ]);
        }
    }

    // Buckinghamshire: 2022-04 to 2022-10 used an older 16-column layout
    // (Organisation Name,Effective date,Directorate,Date,ClearNo,DocInvNo,Pay
    // Amt,Inv Amt,Irrecoverable VAT,Supplier Name,Supp ID,VAT Reg No,Expense
    // Area,Expense type,Exp code,BVACOP) where Supplier Name is at index 9,
    // the transaction date is "Date" (index 3, not "Effective date" at index
    // 1), the amount is "Inv Amt" (index 7, not "Pay Amt" at index 6 which is
    // often 0), and the service area is "Directorate" (index 2) -- all
    // different from the static config (2/8/7/1). From 2022-11 onwards
    // (including the May 2023 XLSX, same column order under different header
    // names) the static config already matches. Detect the old layout by the
    // presence of an "inv amt" header and remap columns by name.
    if (str_contains(strtolower($source['council']), 'buckinghamshire')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        if (in_array('inv amt', $hdr, true)) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $hdr, [
                's' => ['supplier name', 'supplier names'],
                'a' => ['inv amt'],
                'd' => ['date'],
                'v' => ['directorate'],
            ]);
        }
    }

    // Durham: December 2024 used a one-off different "Merchant Category" CSV
    // layout (Local authority department which incurred the
    // expenditure,Service Division,Cost Centre,Summary of the Purpose of
    // Expenditure,Expense Type,Expense Code,Detailed Expense Type,Date of
    // Transaction,Transaction Number,Beneficiary,Merchant Category
    // Number,Merchant Category,Gross Amount,VAT,Net Amount) instead of the
    // usual 9-column layout (Service Area,...,Payment Date,Transaction
    // Number,Amount Exc VAT,Supplier Name). All other months (2022-04
    // onwards) use the usual layout and match the static config (8/7/5/0).
    // Detect this one-off layout by the presence of a "beneficiary" header
    // and remap: supplier=Beneficiary, amount=Net Amount (excl VAT, matches
    // "Amount Exc VAT" elsewhere), date=Date of Transaction; service area
    // stays at index 0 ("Local authority department which incurred the
    // expenditure").
    if (str_contains(strtolower($source['council']), 'durham')) {
        if ($rows !== null) {
            $hdr = array_map('strtolower', array_map('trim', $rows[0] ?? []));
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        }
        if (in_array('beneficiary', $hdr, true)) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $hdr, [
                's' => ['beneficiary'],
                'a' => ['net amount'],
                'd' => ['date of transaction'],
            ]);
            $col_v = 0;
        }
    }

    // Lambeth: quarterly "Over £500" workbooks have changed shape over time.
    // 2023-24 Q1 onwards (and the static config) use a 9-col layout with
    // "Subjective Description" at col 7 (col_service=7). 2022-23 Q1-Q3 use a
    // 14-col layout where "Subjective Description" is at col 11 instead.
    // 2022-23 Q4 uses a 7-col layout with no "Subjective Description" column
    // at all. col_supplier/col_amount/col_date (2/4/1) are stable across all
    // three layouts, so only col_service needs to shift. Detect by header
    // name and find "Subjective Description"; if absent, leave col_v at -1.
    if (str_contains(strtolower($source['council']), 'lambeth')) {
        $cand = header_candidate_rows($rows, $content, 1);
        $col_v = -1;
        if ($cand) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[0], ['v' => ['subjective description']]);
        }
    }

    // Norfolk: quarterly files. Most CSV-era quarters (2022-04 to 2023-07)
    // have the header directly on row 0, but Oct-Dec 2023 (CSV) and all
    // XLSX-era quarters (2022-07, 2024-01 onwards) have a "Spending over
    // £500: Qx YYYY" title row on row 0 with the real header on row 1 --
    // and the Directorate/service column shifts from index 3 to index 5
    // between the two layouts. Detect the header row and the directorate
    // column by name; supplier (6)/net amount (13)/payment date (11) are
    // stable across both layouts.
    if (str_contains(strtolower($source['council']), 'norfolk')) {
        for ($hi = 0; $hi <= 1; $hi++) {
            if ($rows !== null) {
                $hdr = array_map('strtolower', array_map('trim', $rows[$hi] ?? []));
            } else {
                $lines = explode("\n", $content);
                $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[$hi] ?? '')));
            }
            if (!array_filter($hdr, fn($h) => str_contains($h, 'supplier'))) continue;
            $skip = $hi + 1;
            foreach ($hdr as $i => $h) {
                // "Supplier (beneficiary) name" / "Supplier Name" -- avoid
                // matching "Supplier registered company/charity number" or
                // "Local supplier(beneficiary) internal reference".
                if (str_contains($h, 'supplier') && str_contains($h, 'name')) $col_s = $i;
                if ($h === 'net amount') $col_a = $i;
                if ($h === 'payment date') $col_d = $i;
                if (str_contains($h, 'directorate')) $col_v = $i;
            }
            break;
        }
    }

    // Leicestershire: most months use a 6-column "Payment Date,Department,
    // Supplier Name,Pay Group,Account Description,Net Amount" layout
    // (col_supplier=2, col_amount=5, col_date=0, col_service=1, matching the
    // static config), but 2023-09 and 2023-10 insert an extra "Transaction
    // Number" column after "Payment Date" (7 cols total), shifting
    // Department/Supplier Name/Account Description/Net Amount to
    // 2/3/5/6 -- under the static config this points col_supplier at
    // "Department" and col_amount at "Account Description" (text, not
    // numeric), so 0 rows match. Detect this layout by the presence of a
    // "transaction number" header and remap columns by name.
    if (str_contains(strtolower($source['council']), 'leicestershire')) {
        $first_line = strtok($content, "\n");
        $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        if (in_array('transaction number', $hdr, true)) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $hdr, [
                'v' => ['department'],
                's' => ['supplier name'],
                'a' => ['net amount'],
                'd' => ['payment date'],
            ]);
        }
    }

    // East Riding of Yorkshire: quarterly CSVs (one file covers 3 calendar
    // months; the per-month date-filter further below buckets rows by
    // paid_date). Apr-Jun 2022 and earlier have 12 columns with no "Supplier
    // ID" column (TransID,Trans Date,Beneficiary Name,Batch ID,Net Amount,
    // Directorate,...), so col_amount=4/col_service=5 -- but the static
    // config (col_amount=5, col_service=6) is tuned for Jul-Sep 2022 onward,
    // where "Supplier ID" is inserted before "Batch ID" (13 cols), shifting
    // both right by one. Detect the absence of "supplier id" in the header
    // and shift back for the early layout. col_date=1/col_supplier=2 are
    // unchanged across both layouts.
    if (str_contains(strtolower($source['council']), 'east riding')) {
        $first_line = strtok($content, "\n");
        $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
        if (!in_array('supplier id', $hdr, true)) {
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $hdr, [
                'a' => ['net amount'],
                'v' => ['directorate'],
            ]);
        }
    }

    // Hammersmith & Fulham: quarterly XLSX (one file covers 3 calendar
    // months; the per-month date-filter further above buckets rows by
    // paid_date). 2022-04 to 2023-06 (Q1 2022-23 to Q1 2023-24) use a 9-col
    // layout (Organisation,Supplier Name,Document Number,Payment Date,Cost
    // Center/Capital Project,Cost Center/Capital Project Description,GL
    // Account,GL Account Description,Amount), so col_amount=8/col_service=5
    // -- the static config (col_amount=6, col_service=4) is tuned for the
    // 2023-07+ 7-col layout, where "Document Number"/"GL Account" (numeric
    // code columns, not description) are dropped, shifting Amount/the
    // description column left. col_supplier=1/col_date=3 are unchanged
    // across both layouts. Detect by header column count rather than name
    // (both eras call the description column "...Description" but at
    // different indices) since the file genuinely has 9 vs 7 columns.
    if (str_contains(strtolower($source['council']), 'hammersmith')) {
        $hdr = $rows !== null ? array_map('strtolower', array_map('trim', $rows[0] ?? [])) : [];
        if (count($hdr) >= 9 && ($hdr[2] ?? '') === 'document number') {
            $col_a = 8;
            $col_v = 5;
        }
    }

    // Devon: three layouts across the 2022-04 to 2026-05 range, all
    // detected by scanning for a "supplier name" header (rows 0-4, to allow
    // for the Access-export preamble rows in the third layout):
    //  - 2022-04 to ~2025-03: 13 cols incl. "Invoice Number"
    //    (...,Transaction Number,Invoice Number,Amount,Supplier Name,...).
    //  - 2025-04 to ~2026-03: "Invoice Number" column removed (12 cols),
    //    shifting Amount/Supplier Name/Expense Area each back by one.
    //  - 2026-04 onwards: re-exported from Access with 3 extra preamble
    //    rows ("query SELECT...", "sort", "columns,...") before the real
    //    header, and every data row prefixed with a literal "INSERTED
    //    DETAIL" column not present in the header row. Name-based column
    //    detection (rather than the previous static indices, which happened
    //    to align Amount/Supplier by coincidence but pointed col_date at
    //    "Name of Body" -- a text field, so paid_date was always null for
    //    this layout) fixes all three.
    if (str_contains(strtolower($source['council']), 'devon')) {
        for ($hi = 0; $hi <= 4; $hi++) {
            if ($rows !== null) {
                $hdr = array_map('strtolower', array_map('trim', $rows[$hi] ?? []));
            } else {
                $lines = explode("\n", $content);
                $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[$hi] ?? '')));
            }
            if (!array_filter($hdr, fn($h) => str_contains($h, 'supplier name'))) continue;
            $skip = $hi + 1;
            // 2026-04 Access-export header row uses "text Date" (the raw Access
            // column-type prefix) rather than a bare "Date", so accept both.
            apply_cols_modes($col_s, $col_a, $col_d, $col_v, $hdr, [
                's' => [['substr', 'supplier name']],
                'a' => [['exact', 'amount']],
                'd' => [['exact', 'date'], ['exact', 'text date']],
                'v' => [['substr', 'expense area']],
            ]);
            break;
        }
    }

    // Rugby District Council: two Agresso export layouts, both of which drift
    // relative to the stale per-source col_* values held in ct_spend_sources
    // (col_supplier=7, col_amount=6, col_date=9 -- one column too far right;
    // that off-by-one made the importer read "apar_id" (a numeric supplier id)
    // as the supplier name, so every standard period matched 0 tech).
    //  - Standard months: a SQL query dump occupies the first ~10-11 rows with
    //    the real header on the "columns,costc_desc,dim_1,account_desc,text
    //    account,tot_amount,apar_name,apar_id,payment_date,voucher_no,..." row
    //    (col0 == "columns"), and every data row prefixed with a literal
    //    "INSERTED DETAIL" cell. Correct indices: apar_name=6, tot_amount=5,
    //    payment_date=8, costc_desc=1.
    //  - Direct-export months (e.g. 2025-05): no SQL preamble, header on row 0
    //    ("Expense Area,Cost Centre,Expense Type,Account Code,Amount,Supplier
    //    Name,SupplierId,Pay Date,..."). Correct indices: Supplier Name=5,
    //    Amount=4, Pay Date=7, Expense Area=0.
    // Detect the header by content (rows 0-12) and map every column by name so
    // neither the SQL-preamble row offset nor any future one-column drift can
    // silently break it again.
    if (str_contains(strtolower($source['council']), 'rugby')) {
        $cand = header_candidate_rows($rows, $content, 13);
        // first row that is either the SQL-preamble layout (apar_name) or the
        // direct Agresso layout (supplier name + expense area); apar_name wins.
        $hi = find_header_row($cand, fn($h) => in_array('apar_name', $h, true)
            || (in_array('supplier name', $h, true) && in_array('expense area', $h, true)));
        if ($hi !== null) {
            $skip = $hi + 1;
            $norm = array_map('strtolower', array_map('trim', $cand[$hi]));
            $c = in_array('apar_name', $norm, true)
                ? map_cols_by_name($cand[$hi], ['s'=>['apar_name'],'a'=>['tot_amount'],'d'=>['payment_date'],'v'=>['costc_desc']])
                : map_cols_by_name($cand[$hi], ['s'=>['supplier name'],'a'=>['amount'],'d'=>['pay date'],'v'=>['expense area']]);
            if (isset($c['s'])) $col_s = $c['s'];
            if (isset($c['a'])) $col_a = $c['a'];
            if (isset($c['d'])) $col_d = $c['d'];
            if (isset($c['v'])) $col_v = $c['v'];
        }
    }

    // Wolverhampton: the static config (col_supplier=1, col_amount=3) was
    // wrong for every month -- col_amount=3 actually points at "Account"
    // (a numeric account code like "4531", not a real amount), so every
    // previously-"successful" import silently stored account codes as
    // amounts. There are also several column-count variants: most months
    // are a plain 9-col CSV (Supplier ID,Supplier ID(T),Amount,Account,
    // Account(T),TransNo/Payment Date in either order,Service(T),Cost
    // Centre(T)); some months add a leading blank column (10 cols, shifting
    // everything right by one); some months are a raw Access-export dump
    // with 3 preamble rows ("query agr_getbrowser...", "sort,...",
    // "columns,...") before the real header, a literal "INSERTED DETAIL"
    // prefix on every data row (where the header row has a blank instead),
    // and an extra stray "subtotal,outline" row right after the header.
    // Detect the header by content (rows 0-4) and map every column by name
    // so the layout/leading-column variations don't matter.
    if (str_contains(strtolower($source['council']), 'wolverhampton')) {
        $cand = header_candidate_rows($rows, $content, 5);
        $hi = find_header_row($cand, fn($h) => in_array('supplier id(t)', $h, true));
        if ($hi !== null) {
            $skip = $hi + 1;
            apply_cols_by_name($col_s, $col_a, $col_d, $col_v, $cand[$hi], [
                's' => ['supplier id(t)'],
                'a' => ['amount'],
                'd' => ['payment date', 'pay date'],
                'v' => ['service(t)'],
            ]);
        }
    }

    // Ealing: static config (col_supplier=14, col_amount=12, col_date=10,
    // col_service=4, skip_rows=1) matches 41 of 44 months, but three months
    // break the fixed layout:
    //  - 2024-04: an extra blank "query,,,,,..." row is inserted before the
    //    usual "columns,," header row, pushing the header from row 0 to row
    //    1 and data from row 1 to row 2 -- skip_rows needs to be 2 just for
    //    this month.
    //  - 2025-09: the leading "columns" label column and the blank column
    //    after it are both missing, so the file has 13 columns instead of
    //    15 and every real column shifts left by 2 (service 4->2, date
    //    10->8, amount 12->10, supplier 14->12).
    //  - 2025-01 (xlsx): a "SQL SELECT ..." query dump occupies the first
    //    ~55 rows of the data sheet, with the real "columns,,Body Name,..."
    //    header buried around row 56 and data starting row 58. Column
    //    positions match the CSV default (service=4, date=10, amount=12,
    //    supplier=14) once the header is found -- only skip_rows changes.
    // Detect all three by scanning for the row whose content actually looks
    // like the real header/data rather than assuming a fixed skip.
    if (str_contains(strtolower($source['council']), 'ealing')) {
        if ($rows !== null) {
            // XLSX (2025-01): the workbook's sheet1.xml is a single-cell
            // "manipulated by Excelerator" placeholder, but the literal text
            // "Excelerator" only appears via a shared-string reference (index
            // into sharedStrings.xml), not as raw text inside sheet1.xml
            // itself -- so parse_xlsx()'s str_contains($sheet_xml,
            // 'Excelerator') fallback never fires and sheet1 (effectively
            // empty) is returned instead of the real data on sheet2. Try
            // sheet1 then sheet2 directly and use whichever actually
            // contains a "Body Name" row within the first 80 rows.
            $try_sheets = [$rows];
            if (count($rows) < 50) {
                $alt = parse_xlsx($content, 2);
                if (count($alt) > count($rows)) $try_sheets[] = $alt;
            }
            foreach ($try_sheets as $try_rows) {
                $found = false;
                for ($hi = 0; $hi <= 80; $hi++) {
                    $hdr = array_map('strtolower', array_map('trim', $try_rows[$hi] ?? []));
                    if (!in_array('body name', $hdr, true)) continue;
                    $rows = $try_rows;
                    $skip = $hi + 1;
                    // Skip past a second, duplicate header row immediately
                    // after (Ealing's xlsx repeats the header on consecutive
                    // rows).
                    $next_hdr = array_map('strtolower', array_map('trim', $try_rows[$hi + 1] ?? []));
                    if (in_array('body name', $next_hdr, true)) $skip = $hi + 2;
                    foreach ($hdr as $i => $h) {
                        if ($h === 'service label') $col_v = $i;
                        if ($h === 'date') $col_d = $i;
                        if ($h === 'net amount') $col_a = $i;
                        if ($h === 'amended supplier name') $col_s = $i;
                    }
                    $found = true;
                    break;
                }
                if ($found) break;
            }
        } else {
            // CSV (2024-04 extra blank row, 2025-09 missing leading 2 cols):
            // scan rows 0-2 for the real header and map columns by name.
            $lines = explode("\n", $content);
            for ($hi = 0; $hi <= 2; $hi++) {
                $hdr = array_map('strtolower', array_map('trim', str_getcsv($lines[$hi] ?? '')));
                if (!in_array('body name', $hdr, true)) continue;
                $skip = $hi + 1;
                foreach ($hdr as $i => $h) {
                    if ($h === 'service label') $col_v = $i;
                    if ($h === 'date') $col_d = $i;
                    if ($h === 'net amount') $col_a = $i;
                    if ($h === 'amended supplier name') $col_s = $i;
                }
                break;
            }
        }
    }

    // Sheffield: xlsm workbooks have several hidden helper sheets
    // (Qtier_Cover, Menu, Criteria) before/around the real data sheet, and
    // the data sheet's *physical* position (sheet1.xml/sheet2.xml/etc, which
    // is what parse_xlsx() reads by number) doesn't match its display tab
    // name or a fixed position -- e.g. April 2022's "Report" tab is actually
    // sheet3.xml, because the workbook lists Qtier_Cover/Menu/Report/Criteria
    // in that tab order but their underlying rId->sheetN.xml mapping is
    // unrelated to display order. Try each physical sheet number and use the
    // first one whose rows contain a "supplier" header.
    if (str_contains(strtolower($source['council']), 'sheffield')) {
        for ($sn = 1; $sn <= 6; $sn++) {
            $try_rows = parse_xlsx($content, $sn);
            if (count($try_rows) < 2) continue;
            $matched = false;
            for ($hi = 0; $hi <= 2; $hi++) {
                $hdr = array_map('strtolower', array_map('trim', $try_rows[$hi] ?? []));
                if (!array_filter($hdr, fn($h) => str_contains($h, 'supplier'))) continue;
                $rows = $try_rows;
                $skip = $hi + 1;
                foreach ($hdr as $i => $h) {
                    if ($h === 'supplier') $col_s = $i;
                    if ($h === 'value' || str_contains($h, 'amount')) $col_a = $i;
                    if (str_contains($h, 'certified date') || $h === 'date') $col_d = $i;
                    if ($h === 'category description') $col_v = $i;
                }
                $matched = true;
                break;
            }
            if ($matched) break;
        }
    }

    // Warwickshire: monthly "Payments to suppliers" files. Two format
    // families: most months are xlsx workbooks where the real data sheet's
    // physical position varies (sheet1/sheet2/sheet4 depending on how many
    // hidden metadata/options sheets precede it -- not predictable from the
    // file name or period), and a handful of months are plain CSV instead
    // of xlsx entirely (e.g. 2022-08 is "Supplier id,Supplier Name,Transno,
    // Account,Description,Amount,Pay Date,Group" as plain text). The static
    // config's col_supplier=0 was always wrong -- it pointed at "Supplier
    // id" (a numeric ID), not "Supplier Name". For xlsx, try each physical
    // sheet 1-6 and use the first with an exact "supplier name" header; for
    // CSV, detect columns by name directly from the first line.
    if (str_contains(strtolower($source['council']), 'warwickshire')) {
        if ($is_xlsx) {
            for ($sn = 1; $sn <= 6; $sn++) {
                $try_rows = parse_xlsx($content, $sn);
                if (count($try_rows) < 2) continue;
                $matched = false;
                for ($hi = 0; $hi <= 5; $hi++) {
                    $hdr = array_map('strtolower', array_map('trim', $try_rows[$hi] ?? []));
                    if (!in_array('supplier name', $hdr, true) && !in_array('supplier', $hdr, true) && !in_array('working', $hdr, true)) continue;
                    $rows = $try_rows;
                    $skip = $hi + 1;
                    foreach ($hdr as $i => $h) {
                        if ($h === 'supplier name' || $h === 'supplier' || $h === 'working') $col_s = $i;
                        if ($h === 'amount') $col_a = $i;
                        if ($h === 'pay date' || $h === 'payment date') $col_d = $i;
                        if ($h === 'description') $col_v = $i;
                    }
                    $matched = true;
                    break;
                }
                if ($matched) break;
            }
        } else {
            $first_line = strtok($content, "\n");
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($first_line ?: '')));
            if (in_array('supplier name', $hdr, true) || in_array('supplier', $hdr, true)) {
                foreach ($hdr as $i => $h) {
                    if ($h === 'supplier name' || $h === 'supplier' || $h === 'working') $col_s = $i;
                    if ($h === 'amount') $col_a = $i;
                    if ($h === 'pay date' || $h === 'payment date') $col_d = $i;
                    if ($h === 'description') $col_v = $i;
                }
            }
        }
    }


    // Vale of Glamorgan Council: three layout eras across the 2022-04..2025-12
    // range, all sharing identical col_supplier=4/col_amount=5/col_date=2/
    // skip_rows=1 but differing in delimiter and col_service:
    //   Layout A (2022-04..2023-03): comma-delimited, col_service=0 (DIRECTORATE).
    //     The header spans two physical lines because "ACCOUNTING DATE" is a
    //     quoted multiline CSV field -- the stray second header line is silently
    //     discarded by the amount>0 check.
    //   Layout C tab (2023-04..2023-12): TAB-delimited, col_service=1. The static
    //     ct_council_config delimiter is comma, so without this block every row
    //     would parse as a single-field row and produce zero matches.
    //   Layout C comma (2024-01 onwards): comma-delimited, col_service=1.
    //     Default ct_council_config values are correct for this era.
    // Detect delimiter from the first non-empty line of content; detect layout era
    // from the presence of "DIRECTORATE" as the first header token.
    if (str_contains(strtolower($source['council']), 'vale of glamorgan')) {
        $first_line_vog = strtok($content, "\n");
        $is_tab_vog = str_contains($first_line_vog, "\t");
        if ($is_tab_vog) {
            // Tab-delimited era (2023-04..2023-12): re-parse content as TSV into $rows
            $rows = [];
            $content_norm = str_replace(["\r\n", "\r"], ["\n", "\n"], $content);
            foreach (explode("\n", $content_norm) as $tsvline) {
                if (trim($tsvline) === '') continue;
                $rows[] = str_getcsv($tsvline, "\t");
            }
            // col_service=1 (Parent Cost Centre Description) -- already the default
        } else {
            // Comma-delimited: check if Layout A (DIRECTORATE header at col 0)
            $hdr0_vog = array_map('strtolower', array_map('trim', str_getcsv($first_line_vog)));
            if (isset($hdr0_vog[0]) && str_starts_with($hdr0_vog[0], 'directorate')) {
                // Layout A: col_service=0 (DIRECTORATE column)
                $col_v = 0;
            }
            // else: Layout C comma (2024-01+), col_service=1 -- already the default
        }
    }

    // Show header/row1 for verification
    if ($rows !== null) {
        $hdr_row = $rows[0] ?? [];
    } else {
        $first_lines = explode("\n", substr($content, 0, 2000));
        $hdr_row = str_getcsv($first_lines[0] ?? '');
    }
    out("  Header: " . implode(' | ', array_map(fn($i,$v) => "{$i}:{$v}", array_keys($hdr_row), $hdr_row)));
    out("  Memory: " . round(memory_get_usage()/1024/1024, 1) . "MB / limit: " . ini_get('memory_limit'));

    $row_count  = 0;
    $tech_count = 0;

    // Process rows — XLSX uses array, CSV processes inline to avoid memory issues
    if ($rows !== null) {
        // XLSX: iterate parsed array
        $data_rows = array_slice($rows, $skip);
        out("  Rows to process: " . count($data_rows));
        foreach ($data_rows as $row) {
            if (count($row) < 3) continue;
            if (count(array_filter($row, fn($v) => $v !== null && trim((string)$v) !== '')) === 0) continue;
            $row_count++;
            process_spend_row($row, $col_s, $col_a, $col_d, $col_v, $col_cat, $cat_filter, $source, $insert, $tech_count);
        }
    } else {
        // CSV: write to temp file and stream with fgetcsv — never holds full array in memory
        $tmp = tempnam(sys_get_temp_dir(), 'mcc_') ?: (__DIR__ . '/tmp_' . uniqid());
        out("  Temp file: {$tmp} (" . strlen($content) . " bytes to write)");
        $written = file_put_contents($tmp, $content);
        out("  Written: " . ($written === false ? 'FAILED' : $written . " bytes"));
        unset($content, $raw);
        $fh = fopen($tmp, 'r');
        if (!$fh) {
            out("  ✗ Could not open temp file", 'err');
            @unlink($tmp);
            continue;
        }
        out("  Temp file opened, streaming rows…");
        $line_num = 0;
        $pdo->beginTransaction();
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') continue;
                $row = str_getcsv($line);
                if ($line_num++ < $skip) continue;
                if (count($row) < 3) continue;
                // Skip rows where all values are null/empty (blank lines parsed by str_getcsv)
                if (count(array_filter($row, fn($v) => $v !== null && trim((string)$v) !== '')) === 0) continue;
                $row_count++;
                process_spend_row($row, $col_s, $col_a, $col_d, $col_v, $col_cat, $cat_filter, $source, $insert, $tech_count);
                if ($row_count % 500 === 0) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                    out("  … {$row_count} rows processed, {$tech_count} matched");
                    ob_flush(); flush();
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            out("  ✗ Error at row {$row_count}: " . $e->getMessage(), 'err');
            out("    File: " . $e->getFile() . " line " . $e->getLine(), 'warn');
            foreach ($e->getTrace() as $i => $frame) {
                out("    #{$i} " . ($frame['file'] ?? '?') . ":" . ($frame['line'] ?? '?') . " " . ($frame['function'] ?? '?'), 'warn');
                if ($i > 4) break;
            }
        }
        fclose($fh);
        @unlink($tmp);
    }
    if (!$SHADOW_MODE) $update_src->execute([':count' => $tech_count, ':id' => $source['id']]);
    out("  ✓ {$row_count} rows, {$tech_count} tech payments stored", $tech_count > 0 ? 'ok' : 'warn');
    $grand_rows += $row_count;
    $grand_tech += $tech_count;
}

// ── Summary ───────────────────────────────────────────────────────────────
// Scope the value/supplier totals to the council(s) touched by this run —
// SUM(amount)/COUNT(DISTINCT supplier_canon) across the whole table barely
// moves after a single-council --force re-import (it's dominated by every
// other council's data), which made it look like deletions/re-imports had
// no effect.
$touched_councils = array_values(array_unique(array_column($sources, 'council')));

// ── Fife XMA cross-period credited-out phantom suppression (permanent, survives --force) ──
// Fife re-publishes big month-end XMA payments penny-identically across two
// separate months' files. Fife CONFIRMED via FCIR:65750 (2026-08-04) that the
// EARLIER (April-June) copy of each was wiped out with a credit note and the
// amount was paid ONCE (16/09/2025, shown in the Jul-Sep file). So the genuine
// payment is the LATER copy; the April-June copy is the credited phantom.
// PRECISE hardcoded list ONLY -- a fuzzy "repeated amount" dedup would wrongly
// bin Fife's genuine recurring licences (Oracle £184,897.18 x5 quarters, MLL
// Telecom quarterly, etc). Marks the phantom invalid (amount intact, dashboard
// filters on status), leaves the genuine copy valid.
//   - £6,771,334.08: bin 2025-05 (credit SCN-003232), keep 2025-07.
//   - £870,848.44:  bin 2025-04 (credit F047286),   keep 2025-06.
//   - £785,000.00:  bin 2025-05 (credit SCN-003257), keep 2025-07.
if (in_array('Fife Council', $touched_councils, true)) {
    $fife_sql = "UPDATE `{$SPEND_TABLE}`
        SET validation_status='invalid',
            validation_notes='Fife XMA credited-out phantom (FCIR:65750 confirmed 2026-08-04): April-June copy wiped with credit note, paid once 16/09/2025'
        WHERE council='Fife Council' AND supplier_raw LIKE '%xma%' AND (
            (ROUND(amount,2)=6771334.08 AND period='2025-05')
         OR (ROUND(amount,2)=870848.44  AND period='2025-04')
         OR (ROUND(amount,2)=785000.00  AND period='2025-05')
        )";
    $fife_n = $pdo->exec($fife_sql);
    out("  Fife XMA credited-phantom suppression: {$fife_n} rows marked invalid", 'ok');

}
$placeholders = implode(',', array_fill(0, count($touched_councils), '?'));
$total_val_stmt = $pdo->prepare("SELECT SUM(amount) FROM `{$SPEND_TABLE}` WHERE amount > 0 AND internal_provider = 0 AND council IN ({$placeholders})");
$total_val_stmt->execute($touched_councils);
$total_val = (float)$total_val_stmt->fetchColumn();

$n_suppliers_stmt = $pdo->prepare("SELECT COUNT(DISTINCT supplier_canon) FROM `{$SPEND_TABLE}` WHERE internal_provider = 0 AND council IN ({$placeholders})");
$n_suppliers_stmt->execute($touched_councils);
$n_suppliers = (int)$n_suppliers_stmt->fetchColumn();

out(' ');
out('═══════════════════════════════════════════', 'ok');
out('Done!', 'ok');
out('  Rows processed   : ' . number_format($grand_rows), 'ok');
out('  Tech payments    : ' . number_format($grand_tech), 'ok');
out("  Unique suppliers : {$n_suppliers}" . (count($touched_councils) === 1 ? " ({$touched_councils[0]})" : " (across " . count($touched_councils) . " councils)"), 'ok');
out('  Total value      : £' . number_format($total_val) . (count($touched_councils) === 1 ? " ({$touched_councils[0]})" : " (across " . count($touched_councils) . " councils)"), 'ok');
out('═══════════════════════════════════════════', 'ok');
out(' ');
out('<a href="/contracts/transparency.php">← View transparency spend dashboard</a>');
out('<a href="/contracts/sources.php">← Back to sources</a>');
