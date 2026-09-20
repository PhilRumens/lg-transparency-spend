<?php // v2026-06-21-1
/**
 * import_ca_transparency.php
 * Fetches CSV/XLSX spend data for combined authorities, stores tech payments in ct_ca_spend.
 * Mirrors import_transparency.php's parsing/matching logic but targets the CA-specific
 * tables (ct_ca_config, ct_ca_spend_sources, ct_ca_spend) instead of the council ones.
 * --ca=Name      — limit to one combined authority
 * --period=YYYY-MM — limit to one period
 * --force        — clear and re-import all active sources
 */
declare(strict_types=1);
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';

// PHP 7.x polyfills
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === "" || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
    }
}

$cli = php_sapi_name() === 'cli';

if ($cli) {
    function out(string $msg, string $cls = 'info'): void {
        echo '[' . date('H:i:s') . '] ' . strip_tags($msg) . "\n";
        flush();
    }
    $opts = getopt('', ['ca:', 'period:', 'force']);
    $_GET['ca']     = $opts['ca']     ?? '';
    $_GET['period'] = $opts['period'] ?? '';
    if (isset($opts['force'])) $_GET['force'] = '1';
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
    echo '<div style="margin-bottom:16px"><a href="/contracts/sources.php?type=ca" style="color:#9cdcfe">← Back to data sources</a></div>';
    flush();
    function out(string $msg, string $cls = 'info'): void {
        echo '<span class="' . $cls . '">[' . date('H:i:s') . '] ' . htmlspecialchars($msg) . '</span>' . "\n";
        ob_flush(); flush();
    }
}

// ── Tech supplier patterns loaded from ct_supplier_patterns table ──────────────

// Some CA source CSVs (seen on West of England's Jul-Sep 2024 file: "AtkinsR\xe9alis
// UK Limited") are saved in Windows-1252/Latin-1, not UTF-8 -- decode_content() only
// transcodes UTF-16 BOMs, so a single accented byte like \xe9 (e-acute) survives
// untouched into the supplier string. strtolower() only touches ASCII, so the byte
// stays as-is and "atkinsrealis" never matches "atkinsr\xe9alis" -- silently losing
// that supplier's spend instead of erroring. Strip the common Latin-1/UTF-8 accented
// byte sequences down to their plain-ASCII letter before matching so this (and any
// future accented supplier name) matches regardless of source encoding.
function strip_accents(string $s): string {
    static $map = [
        "\xC3\xA0"=>'a',"\xC3\xA1"=>'a',"\xC3\xA2"=>'a',"\xC3\xA3"=>'a',"\xC3\xA4"=>'a',"\xC3\xA5"=>'a',
        "\xC3\xA8"=>'e',"\xC3\xA9"=>'e',"\xC3\xAA"=>'e',"\xC3\xAB"=>'e',
        "\xC3\xAC"=>'i',"\xC3\xAD"=>'i',"\xC3\xAE"=>'i',"\xC3\xAF"=>'i',
        "\xC3\xB2"=>'o',"\xC3\xB3"=>'o',"\xC3\xB4"=>'o',"\xC3\xB5"=>'o',"\xC3\xB6"=>'o',
        "\xC3\xB9"=>'u',"\xC3\xBA"=>'u',"\xC3\xBB"=>'u',"\xC3\xBC"=>'u',
        "\xC3\xA7"=>'c',"\xC3\xB1"=>'n',
        "\xE0"=>'a',"\xE1"=>'a',"\xE2"=>'a',"\xE3"=>'a',"\xE4"=>'a',"\xE5"=>'a',
        "\xE8"=>'e',"\xE9"=>'e',"\xEA"=>'e',"\xEB"=>'e',
        "\xEC"=>'i',"\xED"=>'i',"\xEE"=>'i',"\xEF"=>'i',
        "\xF2"=>'o',"\xF3"=>'o',"\xF4"=>'o',"\xF5"=>'o',"\xF6"=>'o',
        "\xF9"=>'u',"\xFA"=>'u',"\xFB"=>'u',"\xFC"=>'u',
        "\xE7"=>'c',"\xF1"=>'n',
    ];
    return strtr($s, $map);
}

function match_supplier(string $raw): ?string {
    $lower = strip_accents(strtolower(trim($raw)));
    foreach (['county council','borough council','city council','district council','metropolitan borough','combined authority'] as $skip) {
        if (str_contains($lower, $skip)) return null;
    }
    if (str_contains($lower, 'capita')) {
        foreach (['pension solutions', 'pensions solutions', 'property', 'real estate', 'p and i ltd', 'translation', 'hartshead', 'resourcing', 'workforce management', 'watchdog', 'capita gas', 'gas safe register', 'tv licen'] as $non_it) {
            if (str_contains($lower, $non_it)) return null;
        }
    }
    if (in_array($lower, ['heywood ltd', 'heywood limited', 'aquila heywood ltd', 'aquila heywood limited', 'aquila heywood'], true)) {
        return 'Heywood';
    }
    static $substring = null, $word_boundary = null;
    if ($substring === null) {
        global $pdo;
        $rows = $pdo->query("SELECT pattern, canonical_name, match_type FROM ct_supplier_patterns ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $substring = $word_boundary = [];
        foreach ($rows as $r) {
            if ($r['match_type'] === 'word_boundary') $word_boundary[$r['pattern']] = $r['canonical_name'];
            else $substring[$r['pattern']] = $r['canonical_name'];
        }
    }
    foreach ($word_boundary as $pattern => $canon) {
        if (preg_match('/\b' . $pattern . '\b/', $lower)) return $canon;
    }
    foreach ($substring as $pattern => $canon) {
        if (str_contains($lower, $pattern)) return $canon ?: null;
    }
    return null;
}

function sanitise_str($s): ?string {
    if ($s === null) return null;
    $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\s]+/u', ' ', (string)$s);
    $str = ltrim(rtrim((string)$str));
    return $str === '' ? null : $str;
}

function fetch_url(string $url): ?string {
    $url = str_replace([' ', "\r", "\n"], ['%20', '', ''], $url);
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => ['Accept: */*', 'Accept-Language: en-GB,en;q=0.9'],
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_COOKIEFILE     => '',
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return ($raw && strlen($raw) >= 100) ? $raw : null;
    }
    $ctx = stream_context_create(['http' => [
        'timeout'       => 120,
        'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return ($raw && strlen($raw) >= 100) ? $raw : null;
}

function decode_content(string &$raw): string {
    if (substr($raw, 0, 2) === 'PK') return $raw;
    if (substr($raw, 0, 2) === "\xFF\xFE") {
        $result = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        unset($raw);
        return $result;
    }
    if (substr($raw, 0, 2) === "\xFE\xFF") {
        $result = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        unset($raw);
        return $result;
    }
    if (substr_count(substr($raw, 0, 200), "\0") > 5) {
        $result = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        unset($raw);
        return $result;
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);

    // Some CA source CSVs (seen on West of England's Oct-Dec 2022 file) use
    // bare CR line endings (old Mac-style) instead of CRLF/LF. fgets() in the
    // CSV-streaming branch below only recognises \n, so a CR-only file reads
    // as a single giant "line" that fails the column-count check on every
    // row -- silently producing 0 rows parsed instead of an error. Detect by
    // comparing standalone-CR count to LF count and normalise CRLF first so
    // we don't double-convert it, then any remaining bare CR to LF.
    $cr = substr_count($raw, "\r");
    $lf = substr_count($raw, "\n");
    if ($cr > 0 && $lf < $cr / 2) {
        $raw = str_replace("\r\n", "\n", $raw);
        $raw = str_replace("\r", "\n", $raw);
    }

    // Some CA source CSVs (seen on West of England's Jul-Sep 2024 file,
    // supplier "AtkinsR\xe9alis UK Limited") are saved in Windows-1252/Latin-1
    // rather than UTF-8. A lone \xe9 byte is not valid standalone UTF-8, so
    // sanitise_str()'s preg_replace(..., '/u') -- which requires valid UTF-8
    // input -- silently returns null for the *entire* supplier string, and
    // process_spend_row() then drops the row as if the supplier cell were
    // empty (no error, no log, just a quietly missing row). Detect invalid
    // UTF-8 and transcode the whole file from Windows-1252 once up front so
    // every downstream consumer (sanitise_str, match_supplier, etc.) sees
    // well-formed UTF-8 regardless of source encoding.
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        if ($converted !== false && $converted !== '') $raw = $converted;
    }
    return $raw;
}

// WMCA's monthly workbooks are normally just one sheet ("Disclosure"), but
// at least one period (Jan 2025) has two extra leading sheets ("_control",
// "_options") ahead of it, which silently shifted sheet1.xml to the wrong
// sheet (26 rows of control metadata instead of ~1,000 rows of real data).
// Resolve the sheet number by its declared name instead of trusting position 1.
function resolve_xlsx_sheet_by_name(string $raw, string $name): ?int {
    foreach ([sys_get_temp_dir(), '/tmp', __DIR__] as $dir) {
        $tmp = $dir . '/xlsx_ca_lookup_' . uniqid() . '.xlsx';
        if (@file_put_contents($tmp, $raw) !== false) break;
        $tmp = null;
    }
    if (!$tmp || !class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); return null; }
    $wb_xml   = $zip->getFromName('xl/workbook.xml');
    $rels_xml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $zip->close();
    @unlink($tmp);
    if (!$wb_xml || !$rels_xml) return null;

    preg_match('/<sheet[^>]*name="' . preg_quote($name, '/') . '"[^>]*r:id="([^"]+)"/', $wb_xml, $m);
    if (!$m) return null;
    $rid = $m[1];
    preg_match('/<Relationship[^>]*Id="' . preg_quote($rid, '/') . '"[^>]*Target="worksheets\/sheet(\d+)\.xml"/', $rels_xml, $m2);
    return $m2 ? (int)$m2[1] : null;
}

// East Midlands Combined County Authority publishes its quarterly "Items of
// Expenditure" reports as PDF only (a Crystal Reports/SSRS-style export, one
// fixed 9-column table: Body Name,Body,Service Area Categorisation,
// Responsible Unit,Expense Type,Posting Date,Amount,Name of Supplier,
// Transaction number). mutool's plain-text extraction emits exactly one cell
// value per line, in row-major reading order, with no per-row line breaks --
// confirmed across both a 2-page and a 23-page sample (576 and 11,475 data
// lines respectively, both cleanly divisible by 9 after stripping blanks/
// page-footers). The header row appears exactly once at the very top
// regardless of page count -- it is NOT repeated per page. Each physical
// page boundary leaves behind a blank line + a literal "# CONTROLLED"
// footer line + a form-feed (\f) character, which must be stripped before
// chunking, or the column alignment drifts after the first page break.
function pdf_to_text(string $raw): ?string {
    $dir = is_writable(sys_get_temp_dir()) ? sys_get_temp_dir() : __DIR__;
    $in  = $dir . '/pdf_ca_in_' . uniqid() . '.pdf';
    $out = $dir . '/pdf_ca_out_' . uniqid() . '.txt';
    if (@file_put_contents($in, $raw) === false) return null;
    $cmd = 'mutool draw -F text -o ' . escapeshellarg($out) . ' ' . escapeshellarg($in) . ' 2>/dev/null';
    exec($cmd, $_, $exit_code);
    $text = ($exit_code === 0 && file_exists($out)) ? file_get_contents($out) : null;
    @unlink($in);
    @unlink($out);
    return $text ?: null;
}

function parse_pdf_table(string $raw, int $num_cols): array {
    $text = pdf_to_text($raw);
    if ($text === null) { out("  PDF: mutool extraction failed", 'err'); return []; }

    $lines = explode("\n", str_replace("\f", "\n", $text));
    $cells = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === '# CONTROLLED') continue;
        $cells[] = $line;
    }
    if (count($cells) <= $num_cols) return [];

    // The header row is expected to contain exactly $num_cols cells, but some
    // PDFs (e.g. East Midlands CCA Q3/Q4 FY24-25, Q1/Q2 FY25-26) split a
    // multi-word header label across two mutool lines (e.g. "Transaction" /
    // "number"), producing $num_cols+1 or more header cells.  Find where data
    // actually starts by looking for the first cell beyond position $num_cols-1
    // that looks like real data: a date (d/m/Y, d/m/y, n/j/Y, or M/D/Y-ish
    // patterns), a currency amount, or an 8000/1000-style body code.  Anything
    // before that first data cell is considered part of the header, regardless
    // of count.
    $data_start = $num_cols; // default: assume header is exactly $num_cols cells
    $scan_limit = min($num_cols + 5, count($cells) - 1);
    for ($i = $num_cols - 1; $i <= $scan_limit; $i++) {
        $c = $cells[$i];
        // Date-like: digits/digits/digits
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $c)) { $data_start = $i; break; }
        // Amount-like: starts with £ or is purely numeric (body code like 1000/8000)
        if (preg_match('/^[£\-]/', $c) || preg_match('/^\d{4}$/', $c)) { $data_start = $i; break; }
        // CA name fragment
        if (str_contains($c, 'Combined County Authority') || str_contains($c, 'Combined Authority')) { $data_start = $i; break; }
    }

    $header = array_slice($cells, 0, $data_start);
    $data   = array_slice($cells, $data_start);
    $rows   = [$header];
    foreach (array_chunk($data, $num_cols) as $chunk) {
        if (count($chunk) === $num_cols) $rows[] = $chunk;
    }
    return $rows;
}

// Greater Lincolnshire CCA publishes a Crystal Reports PDF (via "IBReport"
// export) whose mutool text extraction is NOT a fixed-column table -- the
// hierarchy grouping cells (Executive/Director/Service Heads labels) are
// sometimes concatenated into a single line by mutool rather than appearing
// as three separate cells, making fixed-chunk parse impossible. Instead,
// scan for transaction date cells (dd/mm/yyyy) to locate each row's anchor
// and reconstruct: date, period code, amount, optional numeric supplier ID,
// supplier name. The 11-cell header (Executive(T), Director(T),
// Service Heads(T), Cost centre, Cost centre(T), Account(T),
// Transaction Date, Period, Amount, Supplier ID, Supplier ID(T)) is emitted
// once at the top and never repeated across pages. Returns rows in the same
// [header_row, data_row, ...] format as parse_pdf_table().
function parse_pdf_table_glca(string $raw): array {
    $text = pdf_to_text($raw);
    if ($text === null) { out("  PDF: mutool extraction failed", 'err'); return []; }

    $lines = explode("\n", str_replace("\f", "\n", $text));
    $cells = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === '# CONTROLLED') continue;
        $cells[] = $line;
    }
    if (count($cells) < 12) return [];

    // Header is the first 11 cells (confirmed stable across all GL CCA files)
    $header = array_slice($cells, 0, 11);
    $data   = array_slice($cells, 11);

    // Scan data cells for date anchors (dd/mm/yyyy) and extract each row
    $rows = [$header];
    $i = 0;
    $n = count($data);
    while ($i < $n) {
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $data[$i])) {
            $date   = $data[$i];
            $period = $data[$i + 1] ?? '';
            $amount = $data[$i + 2] ?? '';
            // Next cell: numeric = supplier ID (skip it), otherwise = supplier name
            $next = $data[$i + 3] ?? '';
            if (is_numeric(str_replace([',', ' '], '', $next)) && $next !== '') {
                $supplier_name = $data[$i + 4] ?? '';
                $i += 5;
            } else {
                $supplier_name = $next;
                $i += 4;
            }
            // Synthesise a row in column order matching ct_ca_config:
            // col 5=service(Account(T)), col 10=date, col 11=period, col 13=amount, col 15=supplier
            // Use a sparse array; process_spend_row accesses by index directly.
            $row = array_fill(0, 16, '');
            $row[10] = $date;
            $row[11] = $period;
            $row[13] = $amount;
            $row[15] = $supplier_name;
            $rows[] = $row;
        } else {
            $i++;
        }
    }
    return $rows;
}

// York and North Yorkshire Combined Authority: account transactions report
// published in two PDF eras before the CA switched to CSV/XLSX in 2025-04.
//
// ERA B (2024-04 to 2025-03): 9-column layout:
//   Qtr | Date | Source | Description | InvNum | Ref | Debit | Credit | "Gross Account"
//   mutool emits 4 preamble cells (report title, org name, period header, filter header)
//   before the 9 header cells. Each data row also has 9 cells, BUT:
//     - Some "15 May 2024 Payable Invoice" rows collapse Date+Source into cell 1 → 8 cells
//     - Some "Spend Money" rows omit InvNum/Ref → 7 cells
//     - Some rows gain a footnote cell → 10 cells
//   Use the Qtr marker (Q1/Q2/Q3/Q4) as a reliable row-start anchor.
//
// ERA A (2024-02/03 combined): 7-column layout, NO Qtr column:
//   Source | Description | InvNum | Ref | Debit | Credit | "Gross Account"
//   Dates appear as freestanding "D Month YYYY" group-header cells, with a
//   "Total D Month YYYY" + 3 amount cells summarising each group. The date
//   must be captured from the group header and applied to each row in that
//   group.
//
// Both eras share the same ct_ca_config column mapping (col_supplier=3 =
// Description, col_amount=8 = Gross, col_date=1 = Date, col_service=-1).
// Synthesised rows are padded to at least 9 elements and placed at the
// correct column indices so process_spend_row() can use them unchanged.
// Returns [header_row, data_row, ...] like parse_pdf_table().
function parse_pdf_table_ynyca(string $raw): array {
    $text = pdf_to_text($raw);
    if ($text === null) { out("  PDF: mutool extraction failed", 'err'); return []; }

    $lines = explode("\n", str_replace("\f", "\n", $text));
    $cells = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === '# CONTROLLED' || $line === '# OFFICIAL') continue;
        // Drop the long "Source contains ..." footnote cells
        if (str_starts_with($line, 'Source contains ')) continue;
        if (str_starts_with($line, 'Payable Overpayment') || str_starts_with($line, 'Payable Prepayment')
            || str_starts_with($line, 'Receivable ') || str_starts_with($line, 'Receive Money')
            || str_starts_with($line, 'Credit Note Refund')) continue;
        $cells[] = $line;
    }
    if (count($cells) < 10) return [];

    // Detect era by presence of 'Qtr' in first 15 cells (Era B) vs absence (Era A)
    $is_era_b = false;
    foreach (array_slice($cells, 0, 15) as $c) {
        if ($c === 'Qtr') { $is_era_b = true; break; }
    }

    // Synthetic header row at the same column positions used by ct_ca_config:
    // col 1=Date, col 3=Description (supplier+detail), col 8=Gross
    $header = array_fill(0, 11, '');
    $header[1] = 'Date';
    $header[3] = 'Description';
    $header[8] = 'Gross';
    $rows = [$header];

    if ($is_era_b) {
        // Skip 4 preamble + 9 header = 13 cells, then parse data using Q1-Q4 anchors
        $data = array_slice($cells, 13);
        $qtrs = ['Q1' => true, 'Q2' => true, 'Q3' => true, 'Q4' => true];
        $current = [];
        foreach ($data as $c) {
            if (isset($qtrs[$c]) && count($current) >= 7) {
                // Flush completed row
                $rows[] = parse_pdf_table_ynyca_b_row($current);
                $current = [$c];
            } elseif (isset($qtrs[$c]) && count($current) === 0) {
                $current = [$c];
            } else {
                // Drop "Total" summary lines (Total, amount, amount, amount)
                if ($c === 'Total' && count($current) >= 7) {
                    $rows[] = parse_pdf_table_ynyca_b_row($current);
                    $current = [];
                    continue;
                }
                if ($current !== []) $current[] = $c;
            }
        }
        if (count($current) >= 7) $rows[] = parse_pdf_table_ynyca_b_row($current);
    } else {
        // Era A: no Qtr column; date appears as a freestanding group header
        // Data starts after preamble(4) + header(7) = 11 cells
        $data = array_slice($cells, 11);
        $date_re = '/^\d{1,2} [A-Z][a-z]+ \d{4}$/';
        $current_date = '';
        $i = 0;
        $n = count($data);
        while ($i < $n) {
            $c = $data[$i];
            // Date group header: "5 Feb 2024" etc.
            if (preg_match($date_re, $c) && !str_starts_with($c, 'Total ')) {
                $current_date = $c;
                $i++;
                continue;
            }
            // "Total D M Y" followed by 3 amount cells – skip all 4
            if (str_starts_with($c, 'Total ') && preg_match($date_re, substr($c, 6))) {
                $i += 4; continue;
            }
            // Grand total "Total" at end – stop
            if ($c === 'Total') break;
            // Source type cell (Payable Invoice / Spend Money) starts a 7-cell row:
            // [Source, Desc, InvNum, Ref, Debit, Credit, "Gross Account"]
            if ($c === 'Payable Invoice' || $c === 'Spend Money') {
                $chunk = array_slice($data, $i, 7);
                if (count($chunk) >= 6) {
                    $row = array_fill(0, 11, '');
                    $row[1] = $current_date;   // Date from group header
                    $row[3] = $chunk[1] ?? ''; // Description
                    $row[8] = $chunk[6] ?? ''; // "Gross Account" (amount+account merged)
                    $rows[] = $row;
                    $i += 7;
                    continue;
                }
            }
            $i++;
        }
    }
    return $rows;
}

// Normalise a single Era B cell-block into a synthetic row at ct_ca_config positions:
// col 1=Date, col 3=Description, col 8=Gross
function parse_pdf_table_ynyca_b_row(array $c): array {
    $row = array_fill(0, 11, '');
    // Possible layouts (len 7, 8, 9, 10+):
    //   9: [Qtr, Date, Source, Desc, InvNum, Ref, Debit, Credit, GrossAcct]
    //   8: [Qtr, "Date Source" merged, Desc, InvNum, Ref, Debit, Credit, GrossAcct]
    //   7: [Qtr, Date, Source, Desc, Debit, Credit, GrossAcct]  (no InvNum/Ref)
    //  10: [Qtr, Date, Source, Desc, InvNum, Ref, Debit, Credit, GrossAcct, FootnoteCell]
    $len = count($c);
    if ($len >= 9) {
        // Standard 9-cell (or 10+ with trailing footnote)
        $row[1] = $c[1]; // Date
        $row[3] = $c[3]; // Description
        $row[8] = $c[8]; // "Gross Account" or just Gross
    } elseif ($len === 8) {
        // Date merged with Source type in cell 1 (e.g. "15 May 2024 Payable Invoice")
        // Separate them: anything before the last word-group that is a source type
        $merged = $c[1];
        if (preg_match('/^(\d{1,2} [A-Z][a-z]+ \d{4})\s*(Payable Invoice|Spend Money)$/', $merged, $m)) {
            $row[1] = $m[1];
        } else {
            $row[1] = $merged; // fallback, process_spend_row will parse the date portion
        }
        $row[3] = $c[2]; // Description (shifted left by 1)
        $row[8] = $c[7]; // "Gross Account"
    } elseif ($len === 7) {
        // Spend Money row without InvNum/Ref: [Qtr, Date, Source, Desc, Debit, Credit, GrossAcct]
        $row[1] = $c[1];
        $row[3] = $c[3];
        $row[8] = $c[6];
    }
    return $row;
}

function parse_xlsx(string $raw, int $sheet_num = 1): array {
    foreach ([sys_get_temp_dir(), '/tmp', __DIR__] as $dir) {
        $tmp = $dir . '/xlsx_ca_' . uniqid() . '.xlsx';
        if (@file_put_contents($tmp, $raw) !== false) break;
        $tmp = null;
    }
    if (!$tmp) { out("  XLSX: could not write temp file", 'err'); return []; }
    if (!class_exists('ZipArchive')) { @unlink($tmp); out("  XLSX: ZipArchive not available", 'err'); return []; }

    $zip = new ZipArchive();
    $code = $zip->open($tmp);
    if ($code !== true) {
        @unlink($tmp);
        out("  XLSX: ZipArchive failed to open (code: {$code}, size: " . strlen($raw) . " bytes)", 'err');
        return [];
    }

    $shared = [];
    $ss_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss_xml) {
        $ss_xml = preg_replace('/<rPr>.*?<\/rPr>/s', '', $ss_xml);
        preg_match_all('/<si>(.*?)<\/si>/s', $ss_xml, $si_m);
        foreach ($si_m[1] as $si) {
            preg_match_all('/<t(?:\s[^>]*)?>([^<]*)<\/t>/s', $si, $tm);
            $shared[] = implode('', $tm[1]);
        }
    }

    $sheet_xml = $zip->getFromName("xl/worksheets/sheet{$sheet_num}.xml");
    $zip->close();
    @unlink($tmp);
    if (!$sheet_xml) return [];

    $rows = [];
    preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet_xml, $row_matches);
    foreach ($row_matches[1] as $row_xml) {
        $cells = [];
        preg_match_all('/<c ([^>]+)>(.*?)<\/c>/s', $row_xml, $cell_matches, PREG_SET_ORDER);
        foreach ($cell_matches as $cm) {
            preg_match('/r="([A-Z]+)/', $cm[1], $ref_m);
            $col_ref = $ref_m[1] ?? '';
            $col_idx = 0;
            foreach (str_split($col_ref) as $ch) $col_idx = $col_idx * 26 + (ord($ch) - 64);
            $col_idx--;

            preg_match('/t="([^"]+)"/', $cm[1], $tm);
            $type = $tm[1] ?? '';
            preg_match('/<v>([^<]*)<\/v>/', $cm[2], $val_match);
            $val = $val_match[1] ?? '';
            if ($type === 's') $val = html_entity_decode($shared[(int)$val] ?? '', ENT_XML1, 'UTF-8');
            elseif ($type === 'str' || $type === 'inlineStr') {
                preg_match('/<t>([^<]*)<\/t>/', $cm[2], $t_match);
                $val = html_entity_decode($t_match[1] ?? '', ENT_XML1, 'UTF-8');
            } else {
                $val = html_entity_decode($val, ENT_XML1, 'UTF-8');
            }
            while (count($cells) <= $col_idx) $cells[] = '';
            $cells[$col_idx] = $val;
        }
        $rows[] = $cells;
    }
    return $rows;
}

// ── Row processor ─────────────────────────────────────────────────────────
function process_spend_row(array $row, int $col_s, int $col_a, int $col_d, int $col_v,
    int $col_cat, array $cat_filter, array $source, bool $needs_period_filter,
    PDOStatement $insert, int &$tech_count, int $gla_company_col = -1): void
{
    $row = array_map(fn($v) => (string)($v ?? ''), $row);

    // Greater London Authority: the 2025-26 layout report combines GLA
    // itself with GLA Land & Property Ltd in one file -- scope strictly to
    // the core GLA entity via the "Company name" column so the property
    // subsidiary's spend isn't counted as GLA spend. $gla_company_col is -1
    // for the older eras whose files have no per-row company identifier at
    // all (can't entity-scope those, just accept the small risk of
    // subsidiary contamination there). This is entity scoping, separate
    // from the col_category/cat_filter mechanism below (which gates
    // tech-relevance, not legal entity) -- GLA relies on ordinary
    // match_supplier() keyword matching for tech-relevance, like most
    // councils.
    if ($gla_company_col >= 0
        && !str_contains(strtolower(trim((string)($row[$gla_company_col] ?? ''))), 'greater london authority')) {
        return;
    }

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

    if ($col_cat >= 0 && !empty($cat_filter)) {
        $canon = match_supplier($supplier_raw) ?? $supplier_raw;
    } else {
        $canon = match_supplier($supplier_raw);
        if (!$canon) return;
    }

    $raw_amt    = preg_replace('/[^0-9.,\-]/', '', (string)($row[$col_a] ?? '0'));
    $amount_str = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw_amt));
    // Handle trailing minus (e.g. "29163.81-" → negative, as used in SYMCA XLSX)
    if (substr($amount_str, -1) === '-') {
        $amount = -(float)substr($amount_str, 0, -1);
    } else {
        $amount = (float)$amount_str;
    }
    if ($amount <= 0) return;

    $date = null;
    $date_raw = preg_replace('/^\p{Z}+|\p{Z}+$/u', '', (string)($row[$col_d] ?? ''));
    if ($date_raw) {
        if (is_numeric($date_raw) && (float)$date_raw > 40000 && (float)$date_raw < 60000) {
            $date = date('Y-m-d', ((int)floor((float)$date_raw) - 25569) * 86400);
        } else {
            foreach (['Y-m-d','Ymd','d/m/y','d/m/Y','d.m.Y','j/n/Y','n/j/Y','d-m-Y','Y/m/d','d-M-y','d-M-Y','j-M-y','j-M-Y','j M y','j M Y','j F Y','d/m/Y H:i','d/m/Y H:i:s','Y-m-d\TH:i:s.v','Y-m-d\TH:i:s'] as $dfmt) {
                $d = DateTime::createFromFormat($dfmt, trim($date_raw));
                // Reject overflow results (e.g. "10/13/2025" parsed as d/m/Y
                // rolls month 13 into Jan of the following year and sets a
                // warning; only accept a parse result with no warnings/errors).
                if ($d) {
                    $errs = DateTime::getLastErrors();
                    if (empty($errs['warnings']) && empty($errs['errors'])) {
                        $date = $d->format('Y-m-d'); break;
                    }
                }
            }
        }
    }

    if ($date !== null && $date < '2022-01-01') return;

    // Quarterly cumulative sources (one file shared across 3 monthly source
    // rows, e.g. GMCA) need per-row date filtering so each month's rows land
    // in the right period instead of being 3x-duplicated across the quarter
    // (same pattern as Halton/Norfolk/City of York in import_transparency.php).
    if ($needs_period_filter) {
        if ($date === null) $date = $source['period'] . '-01';
        // Some PDFs (e.g. East Midlands CCA Q3 FY25-26) use M/D/YYYY dates
        // (e.g. "10/1/2025") while other quarters from the same CA use
        // D/M/YYYY.  When both day and month are <= 12 the standard parse
        // formats are ambiguous and d/m/Y wins in the loop above, producing
        // an incorrect date (Jan 10 instead of Oct 1).  Detect this by
        // checking whether swapping day and month would land in the expected
        // period, and if so apply the swap -- this is safe only when the
        // original date does NOT already match the expected period.
        if (substr($date, 0, 7) !== $source['period']) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m2)) {
                $swapped = $m2[1] . '-' . $m2[3] . '-' . $m2[2];
                if (substr($swapped, 0, 7) === $source['period']) {
                    $date = $swapped;
                }
            }
        }
        if (substr($date, 0, 7) !== $source['period']) return;
    }

    $service = $col_v >= 0 ? mb_strimwidth(sanitise_str(array_key_exists($col_v, $row) ? (string)$row[$col_v] : null) ?? '', 0, 200, '…') : null;

    if (in_array($canon, ['Serco', 'AtkinsRealis', 'Version 1', 'Capita', 'Delt Shared Services'], true)) {
        // West of England: col_service (index 2, "Service Area") holds the
        // *organisational unit* that spent the money (e.g. "Capital Delivery
        // (Capital)", "Transport Operations") -- it never contains an
        // IT-relevance keyword, so every AtkinsRealis/Serco/etc row was
        // silently failing this gate regardless of what the work actually
        // was (e.g. a real "Website Design & Development" line item). The
        // actual work description for this CA lives in column 1, "Summary
        // of Purpose" -- check that too for this CA so genuinely IT-flavoured
        // rows aren't dropped just because the wrong column was being read.
        $svc_lower = strtolower($service ?? '');
        if (str_contains(strtolower($source['ca_name']), 'west of england') && array_key_exists(1, $row)) {
            $svc_lower .= ' ' . strtolower((string)$row[1]);
        }
        $is_it = (bool)preg_match('/\b(it|ict)\b/', $svc_lower)
            || str_contains($svc_lower, 'software')
            || str_contains($svc_lower, 'technology')
            || str_contains($svc_lower, 'digital')
            || str_contains($svc_lower, 'computer')
            || str_contains($svc_lower, 'information system')
            || str_contains($svc_lower, 'erp')
            || str_contains($svc_lower, 'business systems')
            || str_contains($svc_lower, 'website')
            || str_contains($svc_lower, 'web development');
        if (!$is_it) return;
    }

    try {
        $insert->execute([
            ':ca_name'        => $source['ca_name'],
            ':supplier_raw'   => mb_strimwidth($supplier_raw, 0, 300, '…'),
            ':supplier_canon' => $canon,
            ':service'        => $service ?: null,
            ':amount'         => round($amount, 2),
            ':paid_date'      => $date,
            ':period'         => $needs_period_filter ? substr($date, 0, 7) : $source['period'],
            ':source_id'      => $source['id'],
        ]);
        $tech_count++;
    } catch (Throwable $e) { /* skip duplicates */ }
}

// ── Database setup ────────────────────────────────────────────────────────
$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

$force        = isset($_GET['force']);
$filter_ca    = $_GET['ca']     ?? '';
$filter_period = $_GET['period'] ?? '';
if (!empty($filter_ca) || !empty($filter_period)) {
    out("Filters: ca='" . htmlspecialchars($filter_ca) . "' period='" . htmlspecialchars($filter_period) . "'");
}

$where_clauses = $force ? 'WHERE s.active = 1' : "
    LEFT JOIN (SELECT ca_name, period FROM ct_ca_spend GROUP BY ca_name, period) imp
        ON imp.ca_name = s.ca_name AND imp.period = s.period
    WHERE s.active = 1 AND imp.period IS NULL";

$sql = "SELECT s.* FROM ct_ca_spend_sources s {$where_clauses}"
     . ($filter_ca     ? " AND s.ca_name = " . $pdo->quote($filter_ca)     : "")
     . ($filter_period ? " AND s.period  = " . $pdo->quote($filter_period) : "")
     . " ORDER BY s.ca_name, s.period ASC";

try {
    $sources = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    out("SQL error: " . $e->getMessage(), 'err');
    exit;
}

if (empty($sources)) {
    if (!$force && $filter_ca) {
        $any = $pdo->query("SELECT COUNT(*) FROM ct_ca_spend_sources WHERE active=1 AND ca_name=" . $pdo->quote($filter_ca))->fetchColumn();
        if ($any > 0) {
            out("All {$any} active source(s) for " . htmlspecialchars($filter_ca) . " are already imported — nothing new to fetch.", 'ok');
            exit;
        }
    }
    out("No active sources found.", 'warn');
    out('Go to <a href="/contracts/sources.php?type=ca">sources.php</a> to add sources.');
    exit;
}

out("Found " . count($sources) . " active CA sources to process" . ($force ? " (force re-import)" : " (incremental)"));

if ($force) {
    $to_clear = array_unique(array_column($sources, 'ca_name'));
    foreach ($to_clear as $c) $pdo->prepare("DELETE FROM ct_ca_spend WHERE ca_name=?")->execute([$c]);
    out("Cleared existing data for: " . implode(', ', $to_clear));
}

$insert = $pdo->prepare("INSERT INTO ct_ca_spend
    (ca_name,supplier_raw,supplier_canon,service,amount,paid_date,period,source_id,fetched_at)
    VALUES (:ca_name,:supplier_raw,:supplier_canon,:service,:amount,:paid_date,:period,:source_id,NOW())");

$update_src = $pdo->prepare("UPDATE ct_ca_spend_sources SET last_fetched=NOW(), last_count=:count WHERE id=:id");

$dupe_url_stmt = $pdo->prepare("SELECT COUNT(*) FROM ct_ca_spend_sources WHERE ca_name=? AND url=?");

$grand_rows = 0;
$grand_tech = 0;
$current_ca = '';

foreach ($sources as $source) {
    if ($source['ca_name'] !== $current_ca) {
        $current_ca = $source['ca_name'];
        out("\n── {$current_ca} ──", 'warn');
    }

    // PDF sources need a per-CA known column count before parse_pdf_table()
    // can chunk mutool's flat cell-list output back into rows -- only add a
    // CA here once its layout has been confirmed stable (see East Midlands'
    // ct_ca_config.notes). Unconfigured PDF CAs are skipped, same as before.
    // Greater Lincolnshire uses parse_pdf_table_glca() instead (variable-
    // structure rows; see that function's comment for layout details).
    $known_pdf_cols = [
        'east midlands' => 9,
    ];
    $is_pdf = strtolower($source['format']) === 'pdf';
    $pdf_num_cols = null;
    $is_glca_pdf   = $is_pdf && str_contains(strtolower($source['ca_name']), 'greater lincolnshire');
    $is_ynyca_pdf  = $is_pdf && str_contains(strtolower($source['ca_name']), 'york and north yorkshire');
    if ($is_pdf && !$is_glca_pdf && !$is_ynyca_pdf) {
        foreach ($known_pdf_cols as $needle => $cols) {
            if (str_contains(strtolower($source['ca_name']), $needle)) { $pdf_num_cols = $cols; break; }
        }
        if ($pdf_num_cols === null) {
            out("  {$source['period']}: skipped (PDF, no parser configured yet for this CA)", 'warn');
            continue;
        }
    }

    out("  {$source['period']}: fetching " . basename(parse_url($source['url'], PHP_URL_PATH)) . "…");

    $raw = fetch_url($source['url']);
    if ($raw === null) {
        out("  ✗ Failed to fetch — check URL", 'err');
        continue;
    }

    if ($is_glca_pdf) {
        $rows = parse_pdf_table_glca($raw);
        unset($raw);
        if (count($rows) < 2) {
            out("  ✗ No rows parsed from GL CCA PDF (got " . count($rows) . ")", 'err');
            continue;
        }
        $content = '';
        $is_xlsx = false;
    } elseif ($is_ynyca_pdf) {
        $rows = parse_pdf_table_ynyca($raw);
        unset($raw);
        if (count($rows) < 2) {
            out("  ✗ No rows parsed from YNYCA PDF (got " . count($rows) . ")", 'err');
            continue;
        }
        $content = '';
        $is_xlsx = false;
    } elseif ($is_pdf) {
        $rows = parse_pdf_table($raw, $pdf_num_cols);
        unset($raw);
        if (count($rows) < 2) {
            out("  ✗ No rows parsed from PDF (got " . count($rows) . ")", 'err');
            continue;
        }
        $content = '';
        $is_xlsx = false;
    } else {
    $content = decode_content($raw);
    $is_xlsx = substr($content, 0, 2) === 'PK' || strtolower($source['format']) === 'xlsx';

    if ($is_xlsx) {
        $sheet_num = 1;
        if (str_contains(strtolower($source['ca_name']), 'west midlands')) {
            $sheet_num = resolve_xlsx_sheet_by_name($content, 'Disclosure') ?? 1;
        }
        // West of England: the Q4 23/24 (Jan-Mar 2024) workbook has a hidden
        // "Transparency Data" raw-export sheet at position 1 (22 cols, supplier
        // column holds a numeric customer ID, not a name -- match_supplier()
        // never matches anything there) ahead of the real 6-col "Transaparency
        // Report" sheet (sic -- that's the actual sheet name in the file).
        // Other West of England xlsx periods are single-sheet and unaffected;
        // resolve_xlsx_sheet_by_name() falls back to 1 when that sheet name
        // isn't present, so this is safe across all their other periods too.
        if (str_contains(strtolower($source['ca_name']), 'west of england')) {
            $sheet_num = resolve_xlsx_sheet_by_name($content, 'Transaparency Report') ?? 1;
        }
        // Greater Lincolnshire CCA: the YTD XLSX contains three sheets --
        // "Parameters" (config metadata), "IBReport 4875" (raw export with
        // ~72% blank supplier cells), and "Suggested publishing " (note the
        // trailing space in the sheet name -- curated rows with complete
        // supplier names). Always use the curated sheet, falling back to
        // sheet 1 only if the expected name is absent (e.g. a future file
        // with a revised sheet name).
        if (str_contains(strtolower($source['ca_name']), 'greater lincolnshire')) {
            $sheet_num = resolve_xlsx_sheet_by_name($content, 'Suggested publishing ') ?? 1;
        }
        $rows = parse_xlsx($content, $sheet_num);
        if (count($rows) < 2) {
            out("  ✗ No rows parsed (got " . count($rows) . ")", 'err');
            unset($raw);
            continue;
        }
    } else {
        if (strlen($content) < 100) {
            out("  ✗ Content too short (" . strlen($content) . " bytes)", 'err');
            continue;
        }
        $rows = null;
    }
    } // end PDF/else(decode+detect) branch

    $cfg_stmt = $pdo->prepare("SELECT * FROM ct_ca_config WHERE ca_name=?");
    $cfg_stmt->execute([$source['ca_name']]);
    $cfg = $cfg_stmt->fetch() ?: [];

    $col_s   = (int)($cfg['col_supplier']  ?? 0);
    $col_a   = (int)($cfg['col_amount']    ?? 1);
    $col_d   = (int)($cfg['col_date']      ?? 2);
    $col_v   = (int)($cfg['col_service']   ?? -1);
    $col_cat = (int)($cfg['col_category']  ?? -1);
    $cat_filter = array_filter(array_map('trim', explode(',', $cfg['category_filter'] ?? '')));
    $skip    = (int)($cfg['skip_rows']     ?? 1);

    // Greater London Authority: column positions shift across the backfill
    // window (a "Company No"/"Company name" pair was added part-way through,
    // and a couple of periods were published as XLSX with an extra leading
    // blank column versus the equivalent CSV) -- detect every column by
    // header name instead of trusting any fixed position, using the header
    // row right before data starts (index $skip-1). $rows is already a
    // parsed array for XLSX periods (via the $is_xlsx branch above) despite
    // being registered as format='csv', so peek at that directly rather than
    // re-parsing $content as CSV text (binary garbage for XLSX).
    $gla_company_col = -1;
    if ($source['ca_name'] === 'Greater London Authority') {
        $hdr = [];
        if ($rows !== null) {
            // parse_xlsx()'s row numbering doesn't line up 1:1 with the CSV
            // text-line numbering $skip is tuned for (off by one on at least
            // one period seen), so search nearby rows for the one that
            // actually contains a recognisable header cell instead of
            // trusting $skip-1 exactly.
            for ($i = max(0, $skip - 3); $i <= $skip + 1; $i++) {
                $candidate = array_map('strtolower', array_map('trim', array_map('strval', $rows[$i] ?? [])));
                if (in_array('vendor name', $candidate, true)) { $hdr = $candidate; $skip = $i + 1; break; }
            }
        } else {
            $hdr_line = explode("\n", (string)($content ?? ''))[$skip - 1] ?? '';
            $hdr = array_map('strtolower', array_map('trim', str_getcsv($hdr_line)));
        }
        $find = fn(string $name) => array_search($name, $hdr, true);
        if (($i = $find('vendor name')) !== false) $col_s = $i;
        if (($i = $find('amount')) !== false) $col_a = $i;
        if (($i = $find('clearing date')) !== false) $col_d = $i;
        if (($i = $find('service expenditure analysis')) !== false) $col_v = $i;
        if (($i = $find('company name')) !== false) $gla_company_col = $i;
        // No company-identifying column at all in the oldest era's files --
        // can't exclude GLA Land & Property Ltd's rows there, so
        // $gla_company_col stays -1 (no entity scoping) for those.
    }

    // West Yorkshire Combined Authority: the static ct_ca_config columns
    // (supplier=4, amount=10, date=3 "Posting Date", skip=6) only match the
    // Oct-2023-onwards layout. Two earlier eras use a 7-column layout with no
    // separate Posting/Invoice Date split (just one "Date" column) and the
    // header row position itself differs (row 13 for Apr22-Jun23, row 6 for
    // the Jul-Sep23 transitional file) -- detect by header text and remap.
    if (str_contains(strtolower($source['ca_name']), 'west yorkshire')) {
        $hdr = [];
        $hdr_row_idx = -1;
        if ($rows !== null) {
            for ($i = 0; $i <= 14; $i++) {
                $candidate = array_map('strtolower', array_map('trim', array_map('strval', $rows[$i] ?? [])));
                if (in_array('beneficiary', $candidate, true)) { $hdr = $candidate; $hdr_row_idx = $i; break; }
            }
        }
        if ($hdr_row_idx >= 0) {
            $find = fn(string $name) => array_search($name, $hdr, true);
            $skip = $hdr_row_idx + 1;
            if (($i = $find('beneficiary')) !== false) $col_s = $i;
            if (($i = $find('date')) !== false) $col_d = $i;
            if (($i = $find('directorate')) !== false) $col_v = $i;
            elseif (($i = $find('department')) !== false) $col_v = $i;
            if (($i = $find('value £')) !== false) $col_a = $i;
            elseif (($i = $find('amount (exclusive)')) !== false) $col_a = $i;
        }
        // Else: no "beneficiary" header found at all in the scanned window --
        // this is the Oct-2023+ "Posting Date"/"Creditor Account" layout
        // already covered by the static ct_ca_config columns, leave as-is.
    }

    // Liverpool City Region Combined Authority (Merseytravel "Spendpro"
    // files): the Jan-2023 and Feb-2023 periods only were published with a
    // 5-column layout missing the "Cost Centre Code(T)" description column
    // that every other period has, which shifts "VAT Excl. Amount" from
    // index 5 (the static ct_ca_config value) to index 4 -- every row's
    // amount read as out-of-range/empty and got silently filtered by the
    // amount<=0 check, producing a false "0 tech payments" rather than a
    // genuine zero-spend month. Detect by header text and remap.
    if (str_contains(strtolower($source['ca_name']), 'liverpool city region')) {
        $hdr0 = $rows !== null
            ? array_map('strtolower', array_map('trim', array_map('strval', $rows[0] ?? [])))
            : [];
        if (in_array('vat excl. amount', $hdr0, true) && !in_array('cost centre code(t)', $hdr0, true)) {
            $i = array_search('vat excl. amount', $hdr0, true);
            if ($i !== false) $col_a = $i;
        }
    }

    // Detect quarterly-cumulative sources generically: if this ca_name+url
    // pair is registered against more than one period, the same file is
    // shared across several months and needs per-row date filtering.
    $dupe_url_stmt->execute([$source['ca_name'], $source['url']]);
    $needs_period_filter = (int)$dupe_url_stmt->fetchColumn() > 1;

    out("  Memory: " . round(memory_get_usage()/1024/1024, 1) . "MB / limit: " . ini_get('memory_limit'));

    $row_count  = 0;
    $tech_count = 0;

    if ($rows !== null) {
        $data_rows = array_slice($rows, $skip);
        foreach ($data_rows as $row) {
            if (count($row) < 3) continue;
            if (count(array_filter($row, fn($v) => $v !== null && trim((string)$v) !== '')) === 0) continue;
            $row_count++;
            process_spend_row($row, $col_s, $col_a, $col_d, $col_v, $col_cat, $cat_filter, $source, $needs_period_filter, $insert, $tech_count, $gla_company_col);
        }
    } else {
        $tmp = tempnam(sys_get_temp_dir(), 'mcc_ca_') ?: (__DIR__ . '/tmp_ca_' . uniqid());
        file_put_contents($tmp, $content);
        unset($content, $raw);
        $fh = fopen($tmp, 'r');
        if (!$fh) {
            out("  ✗ Could not open temp file", 'err');
            @unlink($tmp);
            continue;
        }
        $line_num = 0;
        $pdo->beginTransaction();
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') continue;
                $row = str_getcsv($line);
                if ($line_num++ < $skip) continue;
                if (count($row) < 3) continue;
                if (count(array_filter($row, fn($v) => $v !== null && trim((string)$v) !== '')) === 0) continue;
                $row_count++;
                process_spend_row($row, $col_s, $col_a, $col_d, $col_v, $col_cat, $cat_filter, $source, $needs_period_filter, $insert, $tech_count, $gla_company_col);
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
        }
        fclose($fh);
        @unlink($tmp);
    }
    $update_src->execute([':count' => $tech_count, ':id' => $source['id']]);
    out("  ✓ {$row_count} rows, {$tech_count} tech payments stored", $tech_count > 0 ? 'ok' : 'warn');
    $grand_rows += $row_count;
    $grand_tech += $tech_count;
}

// ── Summary ───────────────────────────────────────────────────────────────
$touched_cas = array_values(array_unique(array_column($sources, 'ca_name')));
$placeholders = implode(',', array_fill(0, count($touched_cas), '?'));
$total_val_stmt = $pdo->prepare("SELECT SUM(amount) FROM ct_ca_spend WHERE amount > 0 AND ca_name IN ({$placeholders})");
$total_val_stmt->execute($touched_cas);
$total_val = (float)$total_val_stmt->fetchColumn();

$n_suppliers_stmt = $pdo->prepare("SELECT COUNT(DISTINCT supplier_canon) FROM ct_ca_spend WHERE ca_name IN ({$placeholders})");
$n_suppliers_stmt->execute($touched_cas);
$n_suppliers = (int)$n_suppliers_stmt->fetchColumn();

out(' ');
out('═══════════════════════════════════════════', 'ok');
out('Done!', 'ok');
out('  Rows processed   : ' . number_format($grand_rows), 'ok');
out('  Tech payments    : ' . number_format($grand_tech), 'ok');
out("  Unique suppliers : {$n_suppliers}" . (count($touched_cas) === 1 ? " ({$touched_cas[0]})" : " (across " . count($touched_cas) . " CAs)"), 'ok');
out('  Total value      : £' . number_format($total_val) . (count($touched_cas) === 1 ? " ({$touched_cas[0]})" : " (across " . count($touched_cas) . " CAs)"), 'ok');
out('═══════════════════════════════════════════', 'ok');
out(' ');
out('<a href="/contracts/transparency.php?section=sa_overview">← View CA overview</a>');
