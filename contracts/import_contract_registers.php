<?php
declare(strict_types=1);
set_time_limit(0);
if (!function_exists("str_contains")) { function str_contains(string $h, string $n): bool { return $n === "" || strpos($h, $n) !== false; } }
if (!function_exists("str_starts_with")) { function str_starts_with(string $h, string $p): bool { return strncmp($h, $p, strlen($p)) === 0; } }
if (!function_exists("str_ends_with")) { function str_ends_with(string $h, string $s): bool { return $s === "" || substr($h, -strlen($s)) === $s; } }

require_once __DIR__ . '/config.php';

$cli = php_sapi_name() === 'cli';
if (!$cli) { header('HTTP/1.1 403 Forbidden'); exit; }

$opts = getopt('', ['council:', 'force']);
$filter_council = $opts['council'] ?? '';
$force = isset($opts['force']);

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// ── Load supplier patterns for tech matching ──────────────────────────────
function match_supplier_cr(string $raw): ?string {
    static $substring = null, $word_boundary = null;
    if ($substring === null) {
        global $pdo;
        $rows = $pdo->query("SELECT pattern, canonical_name, match_type, confirmed_invalid FROM ct_supplier_patterns ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $substring = $word_boundary = [];
        foreach ($rows as $r) {
            if ($r['confirmed_invalid']) continue; // national FP ruling (e.g. Constellia, Magnit) - never a tech match
            if ($r['match_type'] === 'word_boundary') $word_boundary[$r['pattern']] = $r['canonical_name'];
            else $substring[$r['pattern']] = $r['canonical_name'];
        }
    }
    $lower = strtolower(trim($raw));
    if ($lower === '' || $lower === 'redacted' || str_contains($lower, 'redacted')) return null;
    foreach ($word_boundary as $pattern => $canon) {
        if (preg_match('/\b' . $pattern . '\b/', $lower)) return $canon;
    }
    foreach ($substring as $pattern => $canon) {
        if (str_contains($lower, $pattern)) return $canon ?: null;
    }
    return null;
}

// ── Fetch URL ─────────────────────────────────────────────────────────────
function fetch_url_cr(string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => str_replace(' ', '%20', $url),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_COOKIEFILE     => '',
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($raw && $code === 200 && strlen($raw) >= 50) ? $raw : null;
}

// ── In-tend portal scraper ─────────────────────────────────────────────────
// Fetches a contracts register from an In-tend portal page by scraping the HTML.
// Returns a synthetic CSV with: Supplier,Title,Value,StartDate,EndDate,Dept,Ref,Tender
function fetch_intend_cr(string $page_url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $page_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_COOKIEFILE     => '',
        CURLOPT_COOKIEJAR      => '',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$html || $code !== 200) return null;

    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $matches);
    if (empty($matches[1])) return null;

    $rows = [['Supplier','Title','Value','StartDate','EndDate','Department','Reference','TenderProcess']];
    foreach ($matches[1] as $tr) {
        preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $tr, $tds);
        $cells = array_values(array_filter(
            array_map(fn($td) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($td), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
            $tds[1]),
            fn($c) => $c !== ''
        ));

        if (count($cells) < 3) continue;
        $joined = implode(' ', $cells);
        if (!preg_match('/\d{1,2}\s+\w+\s+\d{4}/', $joined)) continue;
        if (!preg_match('/£\d|Value £/', $joined)) continue;

        $ref = '';
        if (preg_match('/^C\d+$/', $cells[0] ?? '')) $ref = array_shift($cells);

        $title    = $cells[0] ?? '';
        $supplier = preg_replace('/\s*-\s*Do\s+[Nn]ot\s+[Aa]ttach.*/u', '', $cells[1] ?? '');
        $end_date = $cells[2] ?? '';
        $value    = '';
        if (preg_match('/£([\d,]+)/', $cells[3] ?? '', $vm)) $value = str_replace(',', '', $vm[1]);

        $rows[] = [trim($supplier), $title, $value, '', $end_date, '', $ref, ''];
    }

    if (count($rows) <= 1) return null;

    $out = '';
    foreach ($rows as $row) {
        $out .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $row)) . "\n";
    }
    return $out;
}


// ── In-tend API fetcher ────────────────────────────────────────────────────
// Fetches contracts from an In-tend portal via the JSON Services API
// (used by councils whose Contracts/Current page uses JS templating).
// $base_url should be the portal root, e.g. https://in-tendhost.co.uk/gedling/aspx
// Returns synthetic CSV with: Supplier,Title,Value,StartDate,EndDate,Dept,Ref,Tender
function fetch_intend_api(string $base_url): ?string {
    $base_url = rtrim($base_url, '/');
    $service_url = $base_url . '/Services/Contracts.svc/GetContract';
    $all = [];
    $page = 1;
    do {
        $params = http_build_query([
            'iContractID'      => 0,
            'iPage'            => $page,
            'iPageSize'        => 100,
            'strSearch'        => '',
            'strMode'          => 'Showall',
            'strOrderby'       => 'Name',
            'strOrderDirection'=> 'ASC',
            'iCustomerFilter'  => 0,
        ]);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $service_url . '?' . $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/javascript, */*',
                'X-Requested-With: XMLHttpRequest',
                'Referer: ' . $base_url . '/Contracts/Current',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$body || $code !== 200) return null;
        $data = json_decode($body, true);
        if (!isset($data['Data']) || !is_array($data['Data'])) return null;
        $all = array_merge($all, $data['Data']);
        $total_pages = (int)($data['PageCount'] ?? 1);
        $page++;
    } while ($page <= $total_pages);

    if (empty($all)) return null;

    // Convert /Date(ms+offset)/ to Y-m-d
    $parse_ms_date = function(?string $d): string {
        if (!$d) return '';
        if (preg_match('/\/Date\((-?\d+)[+\-]\d+\)\//', $d, $m)) {
            $ts = (int)($m[1] / 1000);
            if ($ts <= 0 || $ts > 2000000000) return '';
            return date('Y-m-d', $ts);
        }
        return '';
    };

    $rows = [['Supplier','Title','Value','StartDate','EndDate','Department','Reference','TenderProcess']];
    foreach ($all as $c) {
        $supplier = trim($c['TendererName'] ?? '');
        if ($supplier === '') continue;
        $title  = trim($c['Name'] ?? '');
        $value  = isset($c['Value']) && $c['Value'] > 0 ? (string)(int)$c['Value'] : '';
        $start  = $parse_ms_date($c['StartDate'] ?? null);
        $end    = $parse_ms_date($c['EndDate'] ?? null);
        $dept   = trim($c['Category'] ?? '');
        $ref    = trim($c['Reference'] ?? $c['ProjectReference'] ?? '');
        $tender = trim($c['Type'] ?? '');
        $rows[] = [$supplier, $title, $value, $start, $end, $dept, $ref, $tender];
    }

    if (count($rows) <= 1) return null;

    $out = '';
    foreach ($rows as $row) {
        $out .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $row)) . "\n";
    }
    return $out;
}


// ── Minimal XLSX parser (reused from import_transparency.php logic) ───────
function parse_xlsx_cr(string $raw, int $sheet_num = 1): array {
    $tmp = tempnam(sys_get_temp_dir(), 'crxlsx_');
    file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { unlink($tmp); return []; }

    $shared = [];
    $xml = @$zip->getFromName('xl/sharedStrings.xml');
    if ($xml) {
        preg_match_all('/<si>.*?<\/si>/s', $xml, $m);
        foreach ($m[0] as $si) {
            preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
            $shared[] = implode('', $tm[1]);
        }
    }

    $sheet_xml = $zip->getFromName('xl/worksheets/sheet' . $sheet_num . '.xml');
    $zip->close();
    unlink($tmp);
    if (!$sheet_xml) return [];

    $rows = [];
    preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet_xml, $rm);
    foreach ($rm[1] as $row_xml) {
        $cells = [];
        $row_xml_clean = preg_replace("#<c [^>]*/>#", "", $row_xml);
        preg_match_all('/<c ([^>]*)>(.*?)<\/c>/s', $row_xml_clean, $cm);
        for ($i = 0; $i < count($cm[0]); $i++) {
            $attrs = $cm[1][$i];
            $inner = $cm[2][$i];
            preg_match('/r="([A-Z]+)/', $attrs, $ref);
            $col = 0;
            if (isset($ref[1])) {
                foreach (str_split($ref[1]) as $ch) $col = $col * 26 + ord($ch) - 64;
                $col--;
            }
            $val = '';
            if (preg_match('/<v>(.*?)<\/v>/', $inner, $vm)) {
                $val = $vm[1];
                if (str_contains($attrs, 't="s"') && isset($shared[(int)$val])) {
                    $val = html_entity_decode($shared[(int)$val], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
            $cells[$col] = $val;
        }
        $rows[] = $cells;
    }
    return $rows;
}

// ── Parse date ────────────────────────────────────────────────────────────
function parse_date_cr(string $raw): ?string {
    $raw = trim($raw);
    // Strip ordinal suffixes (1st, 2nd, 3rd, 4th etc.)
    $raw = preg_replace("/\b(\d+)(st|nd|rd|th)\b/i", "$1", $raw);
    $raw = preg_replace("/  +/", " ", $raw);
    if ($raw === '') return null;
    if (is_numeric($raw) && (int)$raw > 30000 && (int)$raw < 60000) {
        $d = \DateTime::createFromFormat('Y-m-d', '1899-12-30');
        $d->modify('+' . (int)$raw . ' days');
        return $d->format('Y-m-d');
    }
    foreach (['d/m/Y','d-m-Y','d.m.Y','Y-m-d\TH:i:s','Y-m-d H:i:s','Y-m-d','d/m/y','j/n/Y','d-M-y','d-M-Y','d M Y','d F Y','j F Y'] as $fmt) {
        $d = \DateTime::createFromFormat($fmt, $raw);
        if ($d && (int)$d->format('Y') >= 1900 && (int)$d->format('Y') <= 2100) return $d->format('Y-m-d');
    }
    return null;
}

// ── Parse amount ──────────────────────────────────────────────────────────
function parse_amount_cr(string $raw): ?float {

    $raw = trim($raw);
    // Strip ordinal suffixes (1st, 2nd, 3rd, 4th etc.)
    $raw = preg_replace("/\b(\d+)(st|nd|rd|th)\b/i", "$1", $raw);
    $raw = preg_replace("/  +/", " ", $raw);
    // Strip parenthetical annotations (date ranges, per-annum notes, etc.)
    $raw = preg_replace('/\(.*?\)/', '', $raw);
    // Strip common textual suffixes
    $raw = preg_replace('/\b(p\.?a\.?|per\s*annum|per\s*year)\b.*/i', '', $raw);
    $raw = str_replace(['£', '¬£', "\xC2\xA3"], '', $raw);
    // A value cell may contain PROSE around the figure, or even two figures
    // (e.g. Eastleigh's "£1,722,000 (including SDLT) + £900,000 works cost").
    // Grab the FIRST monetary token rather than concatenating every digit in
    // the string — the old str_replace(',') + strip-non-digits approach turned
    // that cell into 1,722,000,900,000 (£1.7 trillion).
    if (preg_match('/-?\d{1,3}(?:,\d{3})+(?:\.\d+)?|-?\d+(?:\.\d+)?/', $raw, $m)) {
        $num = str_replace(',', '', $m[0]);
    } else {
        return null;
    }
    if ($num === '' || $num === '-') return null;
    $val = (float)$num;
    if ($val > 9999999999999) return null; // cap at ~10 trillion
    return $val;
}

// ── Main ──────────────────────────────────────────────────────────────────
echo "[" . date('H:i:s') . "] Import contract registers\n";

$where = $filter_council ? "WHERE council = " . $pdo->quote($filter_council) . " AND active = 1" : "WHERE active = 1";
$registers = $pdo->query("SELECT * FROM ct_contract_registers $where ORDER BY council")->fetchAll(PDO::FETCH_ASSOC);

if (!$registers) {
    echo "No active contract registers found" . ($filter_council ? " for '$filter_council'" : "") . ".\n";
    exit;
}

$insert = $pdo->prepare("INSERT INTO ct_contract_register_entries
    (council, supplier, supplier_canon, contract_title, contract_ref, department, value_amount, start_date, end_date, classification, tender_process, is_tech, source_id, fetched_at)
    VALUES (:council, :supplier, :canon, :title, :ref, :dept, :value, :start, :end, :class, :tender, :tech, :src, NOW())");

$total_imported = 0;
$total_tech = 0;

foreach ($registers as $reg) {
    $council = $reg['council'];
    echo "\n[" . date('H:i:s') . "] $council\n";

    if (($reg['retrieval_method'] ?? '') === 'local') {
        if (!file_exists($reg['url'])) {
            echo "  \u2717 Local file not found: {$reg['url']}\n";
            continue;
        }
        $raw = file_get_contents($reg['url']);
        $reg['format'] = 'csv';
        echo "  Loaded local file: " . number_format(strlen($raw)) . " bytes\n";
    } else if (($reg['retrieval_method'] ?? '') === 'intend') {
        $raw = fetch_intend_cr($reg['url']);
        if (!$raw) {
            echo "  ✗ Failed to scrape In-tend page: {$reg['url']}\n";
            continue;
        }
        $reg['format'] = 'csv';
        echo "  Scraped In-tend: " . number_format(strlen($raw)) . " bytes\n";
    } else if (($reg['retrieval_method'] ?? '') === 'intend_api') {
        $raw = fetch_intend_api($reg['url']);
        if (!$raw) {
            echo "  ✗ Failed to fetch In-tend API: {$reg['url']}\n";
            continue;
        }
        $reg['format'] = 'csv';
        echo "  Fetched In-tend API: " . number_format(strlen($raw)) . " bytes\n";
    } else {
        $raw = fetch_url_cr($reg['url']);
        if (!$raw) {
            echo "  ✗ Failed to fetch: {$reg['url']}\n";
            continue;
        }
        echo "  Fetched " . number_format(strlen($raw)) . " bytes\n";
    }

    // Decode encoding
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    if ($reg['encoding'] === 'win1252' && !mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    // Parse
    $rows = [];
    if ($reg['format'] === 'xlsx' || substr($raw, 0, 2) === 'PK') {
        $sheet_num = 1; if (preg_match("/\#sheet=(\d+)/", $reg['url'], $sm)) $sheet_num = (int)$sm[1]; $rows = parse_xlsx_cr($raw, $sheet_num);
    } else {
        // Parse via a stream so quoted fields containing newlines (e.g. a
        // "Manager Responsible" cell holding two names on separate lines, as in
        // Wyre Borough's register) are kept as ONE record. The old approach of
        // exploding on "\n" first and str_getcsv-ing each line shattered such
        // records — shifting every column right and producing garbage values
        // (Wyre's £19,805 Civica LMS contract came out as £14,052,029).
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, str_replace("\r\n", "\n", $raw));
        rewind($fh);
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            // fgetcsv yields [null] for a blank line; skip fully-empty records.
            if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;
            $rows[] = $r;
        }
        fclose($fh);
    }

    $skip = (int)$reg['skip_rows'];
    $col_s = (int)$reg['col_supplier'];
    $col_t = (int)$reg['col_title'];
    $col_v = (int)$reg['col_value'];
    $col_start = (int)$reg['col_start'];
    $col_end = (int)$reg['col_end'];
    $col_d = (int)$reg['col_dept'];
    $col_r = (int)$reg['col_ref'];
    $col_tp = (int)$reg['col_tender_process'];

    // ── Council column filter (for joint registers shared between councils) ──────────────────
    // notes may contain: council_col=N;council_values=Value1|Value2
    // Only rows where col N matches one of the pipe-separated values are imported.
    $council_col_filter = -1;
    $council_col_values = [];
    if (preg_match('/council_col=(\d+)/', $reg['notes'] ?? '', $ccm)) {
        $council_col_filter = (int)$ccm[1];
        if (preg_match('/council_values=([^;]+)/', $reg['notes'] ?? '', $cvm)) {
            $council_col_values = array_map('trim', explode('|', $cvm[1]));
        }
        echo "  Council column filter: col $council_col_filter in [" . implode(', ', $council_col_values) . "]\n";
    }

    // ── Supplier join map (for councils where col_supplier=-1, e.g. North Northamptonshire) ──
    // The notes field contains "Separate suppliers CSV at media/NNNNN" — fetch and build a
    // Contract Id => Supplier Name lookup if col_s is -1.
    $supplier_join = [];
    if ($col_s === -1 && preg_match('/media\/(\d+)/', $reg['notes'] ?? '', $nm)) {
        $sup_url = preg_replace('/\/media\/\d+\/.*$/', '/media/' . $nm[1] . '/download', $reg['url']);
        $sup_raw = fetch_url_cr($sup_url);
        if ($sup_raw) {
            if (substr($sup_raw, 0, 3) === "\xEF\xBB\xBF") $sup_raw = substr($sup_raw, 3);
            $sup_lines = explode("\n", str_replace("\r\n", "\n", $sup_raw));
            // Header: Contract Id,Title,Supplier Id,Supplier Name,...
            foreach ($sup_lines as $si => $sl) {
                if ($si === 0) continue; // skip header
                $sl = trim($sl);
                if ($sl === '') continue;
                $sc = str_getcsv($sl);
                $cid = trim($sc[0] ?? '');
                $sname = trim($sc[3] ?? '');
                if ($cid !== '' && $sname !== '') {
                    // One contract may have multiple suppliers; concatenate with ' / '
                    $supplier_join[$cid] = isset($supplier_join[$cid])
                        ? $supplier_join[$cid] . ' / ' . $sname
                        : $sname;
                }
            }
            echo "  Supplier join map: " . count($supplier_join) . " contracts\n";
        } else {
            echo "  ⚠ Could not fetch supplier join file: $sup_url\n";
        }
    }

    // Clear existing entries for this council
    $pdo->prepare("DELETE FROM ct_contract_register_entries WHERE council = ?")->execute([$council]);

    $count = 0;
    $tech_count = 0;

    for ($i = $skip; $i < count($rows); $i++) {
        $row = $rows[$i];
        // Apply council column filter if set
        if ($council_col_filter >= 0 && !empty($council_col_values)) {
            $row_council = trim((string)($row[$council_col_filter] ?? ''));
            if (!in_array($row_council, $council_col_values, true)) continue;
        }
        if ($col_s === -1) {
            // Supplier from join map keyed by Contract Id (col 0 by convention)
            $contract_id = trim((string)($row[0] ?? ''));
            $supplier_raw = $supplier_join[$contract_id] ?? '';
        } else {
            $supplier_raw = trim((string)($row[$col_s] ?? ''));
        }
        if ($supplier_raw === '' || strtolower($supplier_raw) === 'supplier' || strtolower($supplier_raw) === 'current supplier') continue;

        // ProContract format: "Company name: X | Registration number: Y | ..."
        // Extract just the company name(s). Multi-supplier rows have multiple entries.
        if (str_starts_with($supplier_raw, 'Company name:') || str_contains($supplier_raw, "\nCompany name:")) {
            preg_match_all('/Company name:\s*([^|\n]+)/', $supplier_raw, $cn_matches);
            if (!empty($cn_matches[1])) {
                $supplier_raw = trim(implode(' / ', array_map('trim', $cn_matches[1])));
            }
        }
        // Skip ProContract "Data exceeds cell limits" placeholder
        if (str_contains(strtolower($supplier_raw), 'data exceeds cell limits')) continue;

        $title = $col_t >= 0 ? trim((string)($row[$col_t] ?? '')) : null;
        $value = $col_v >= 0 ? parse_amount_cr((string)($row[$col_v] ?? '')) : null;
        // Halton Borough Council: register has two value columns — awarded_value
        // (col 7, the mapped col_value) and estimated_value (col 6). Awarded is
        // often blank or a literal "£0.00" placeholder, so fall back to the
        // estimated value when awarded is missing or zero. Leaves genuinely
        // valueless rows (both columns empty at source) as NULL.
        if (str_contains(strtolower((string)$council), 'halton') && ($value === null || $value == 0.0)) {
            $est = parse_amount_cr((string)($row[6] ?? ''));
            if ($est !== null && $est > 0) $value = $est;
        }
        $start = $col_start >= 0 ? parse_date_cr((string)($row[$col_start] ?? '')) : null;
        $end = $col_end >= 0 ? parse_date_cr((string)($row[$col_end] ?? '')) : null;
        $dept = $col_d >= 0 ? trim((string)($row[$col_d] ?? '')) : null;
        $ref = $col_r >= 0 ? trim((string)($row[$col_r] ?? '')) : null;
        $tender = $col_tp >= 0 ? trim((string)($row[$col_tp] ?? '')) : null;

        $canon = match_supplier_cr($supplier_raw);
        $is_tech = $canon !== null ? 1 : 0;

        // Title-based negative filter: multi-sector suppliers (Serco, Capita, etc.)
        // get flagged as tech by name alone, but the contract title reveals non-IT work.
        if ($is_tech && $title) {
            $title_lower = strtolower($title);
            $non_tech_keywords = [
                'leisure centre', 'sport & leisure', 'sport and leisure',
                'leisure and active wellbeing', 'active wellbeing', 'leisure management',
                'property valuation', 'property and land', 'employers agent',
                'apprenticeship training', 'social care training',
                'land referencing', 'active travel', 'highway maintenance',
                'cleaning equipment', 'cleaning services', 'waste collection',
                'grounds maintenance', 'grass cutting', 'catering',
                'construction', 'building works', 'refurbishment',
                'car park', 'parking management', 'school rebuild',
                'school meals', 'school transport', 'passenger transport', 'client transport',
                'home to school', 'vibration monitoring', 'parking meter', 'food waste',
                'housing repairs', 'responsive repairs', 'planned maintenance',
                'care homes', 'residential care', 'nursing care',
                'homecare', 'domiciliary care', 'foster',
                'legal services', 'insurance', 'audit services',
                'recruitment', 'agency staff', 'temporary staff',
                'printing services', 'mail room',
                'security guard', 'security services',
                'background check', 'bulk print', 'print and post',
            ];
            // Some negative keywords describe a physical service (repairs,
            // maintenance) that is ALSO the subject of a software/IT system
            // managing that service. When the title carries a strong software
            // signal, these "system-vs-service" keywords must not zero a
            // genuine software supplier (e.g. Aareon "Housing Repairs and
            // Maintenance System", ROCC "housing repairs - uniclass", NEC
            // "Housing repairs data and application solutions", TotalMobile
            // "Asset Lifecycle Management"). Physical repair contractors
            // (Mears, Wates, Morgan Sindall) have no software signal and stay
            // excluded. The supplier still had to match a positive pattern to
            // reach this point.
            $ambiguous_system_kw = [
                'housing repairs', 'responsive repairs', 'planned maintenance',
                'highway maintenance',
            ];
            $software_signal = [
                'system', 'software', 'application', 'saas', 'platform',
                'module', 'licence', 'license', 'cloud', 'hosting', 'azure',
                'ict ', ' ict', 'i.t.', 'digital', 'database', 'mobile solution',
                'asset lifecycle', 'asset management system', 'case management',
                'uniclass', 'data and application', 'crm',
            ];
            $has_software_signal = false;
            foreach ($software_signal as $sig) {
                if (str_contains($title_lower, $sig)) { $has_software_signal = true; break; }
            }
            foreach ($non_tech_keywords as $kw) {
                if (str_contains($title_lower, $kw)) {
                    if ($has_software_signal && in_array($kw, $ambiguous_system_kw, true)) {
                        continue; // software system for this service, not the service itself
                    }
                    $is_tech = 0;
                    break;
                }
            }
        }

        // ── Engineering / infrastructure consultancy suppliers ─────────────────
        // Firms like AtkinsRéalis (ex-SNC-Lavalin) match a tech pattern by name,
        // but the overwhelming majority of their council work is civil/structural
        // engineering, highways design, surveys, quantity surveying, feasibility
        // and project management — not IT. Their titles rarely trip the keyword
        // filter above ("Services", "Concept Design", "…Academy"), so a positive
        // rule is needed: for these suppliers keep is_tech ONLY when the title
        // carries an explicit software signal (a named system, software, platform
        // or licence). Bare "application" is deliberately EXCLUDED because in
        // council contracts it almost always means a planning-permission
        // application, not an app (e.g. "planning application assessment").
        if ($is_tech && $title) {
            $consultancy_canons = [
                'AtkinsRealis',
            ];
            if (in_array($canon, $consultancy_canons, true)) {
                $tl = strtolower($title);
                $consultancy_sw_signal = [
                    'software', 'saturn', 'system', 'platform',
                    'saas', 'licen', 'database', 'digital',
                ];
                $has_sw = false;
                foreach ($consultancy_sw_signal as $sig) {
                    if (str_contains($tl, $sig)) { $has_sw = true; break; }
                }
                if (!$has_sw) $is_tech = 0;
            }
        }

        $insert->execute([
            ':council' => $council,
            ':supplier' => mb_strimwidth($supplier_raw, 0, 300),
            ':canon' => mb_strimwidth($canon ?? $supplier_raw, 0, 300),
            ':title' => $title ? mb_strimwidth($title, 0, 500) : null,
            ':ref' => $ref ? mb_strimwidth($ref, 0, 100) : null,
            ':dept' => $dept ? mb_strimwidth($dept, 0, 200) : null,
            ':value' => $value,
            ':start' => $start,
            ':end' => $end,
            ':class' => null,
            ':tender' => $tender ? mb_strimwidth($tender, 0, 100) : null,
            ':tech' => $is_tech,
            ':src' => $reg['id'],
        ]);
        $count++;
        if ($is_tech) $tech_count++;
    }

    // Update last_fetched and last_count
    $pdo->prepare("UPDATE ct_contract_registers SET last_fetched = NOW(), last_count = ? WHERE id = ?")
        ->execute([$count, $reg['id']]);

    echo "  ✓ $count contracts imported ($tech_count tech)\n";
    $total_imported += $count;
    $total_tech += $tech_count;
}

echo "\n[" . date('H:i:s') . "] Done! $total_imported contracts, $total_tech tech matches.\n";

// Refresh council summary cache
$pdo->exec("INSERT INTO ct_cr_council_summary (council, total, tech, tech_value, from_date, to_date)
    SELECT council, COUNT(*), SUM(is_tech), SUM(CASE WHEN is_tech=1 THEN value_amount END), MIN(start_date), MAX(end_date)
    FROM ct_contract_register_entries GROUP BY council
    ON DUPLICATE KEY UPDATE total=VALUES(total), tech=VALUES(tech), tech_value=VALUES(tech_value), from_date=VALUES(from_date), to_date=VALUES(to_date)");
echo "[" . date('H:i:s') . "] Council summary refreshed.\n";
