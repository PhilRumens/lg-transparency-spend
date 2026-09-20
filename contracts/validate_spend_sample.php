<?php
declare(strict_types=1);
set_time_limit(0);

require_once __DIR__ . '/config.php';

$cli = php_sapi_name() === 'cli';
if (!$cli) { header('HTTP/1.1 403 Forbidden'); exit; }

$opts = getopt('', ['council:', 'sample:']);
$filter_council = trim($opts['council'] ?? '');
$sample_size    = max(1, (int)($opts['sample'] ?? 10));

define('INVALID_LOG', getenv('VALIDATE_LOG') ?: (sys_get_temp_dir() . '/lgts_validation_invalid.log'));
define('MAX_SOURCE_ROWS', 15000); // cap per file to avoid CPU overload on large CSVs

if (!$filter_council) {
    echo "Usage: php validate_spend_sample.php --council='Council Name' [--sample=10]\n";
    exit(1);
}

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// Load keyword rules and supplier rulings from DB
$kw_rows = $pdo->query("SELECT keyword, ruling FROM ct_service_keywords ORDER BY LENGTH(keyword) DESC")->fetchAll(PDO::FETCH_ASSOC);
$non_it_keywords = array_column(array_filter($kw_rows, fn($r) => $r['ruling'] === 'invalid'), 'keyword');
$it_keywords     = array_column(array_filter($kw_rows, fn($r) => $r['ruling'] === 'valid'),   'keyword');

// Load confirmed-valid and confirmed-invalid supplier patterns
$sup_rows = $pdo->query("SELECT pattern, confirmed_valid, confirmed_invalid FROM ct_supplier_patterns WHERE confirmed_valid=1 OR confirmed_invalid=1")->fetchAll(PDO::FETCH_ASSOC);
$confirmed_valid_patterns   = array_column(array_filter($sup_rows, fn($r) => $r['confirmed_valid']),   'pattern');
$confirmed_invalid_patterns = array_column(array_filter($sup_rows, fn($r) => $r['confirmed_invalid']), 'pattern');

function has_it_keyword(string $text): bool {
    global $it_keywords;
    $lower = strtolower($text);
    foreach ($it_keywords as $kw) {
        if (strpos($lower, $kw) !== false) return true;
    }
    return false;
}

function has_non_it_keyword(string $text): bool {
    global $non_it_keywords;
    $lower = strtolower($text);
    foreach ($non_it_keywords as $kw) {
        if (strpos($lower, $kw) !== false) return true;
    }
    return false;
}

function log_invalid(array $row, string $notes): void {
    $line = date('Y-m-d H:i:s') . ' | ' . $row['council']
        . ' | id=' . $row['id']
        . ' | ' . $row['supplier_raw']
        . ' | £' . number_format((float)$row['amount'])
        . ' | ' . $row['period']
        . ' | ' . $notes . "\n";
    file_put_contents(INVALID_LOG, $line, FILE_APPEND);
}

function fetch_url_val(string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => str_replace(' ', '%20', $url),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Referer: ' . $url],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($raw && $code === 200 && strlen($raw) >= 50) ? $raw : null;
}

function parse_xlsx_val(string $raw): array {
    $tmp = tempnam(sys_get_temp_dir(), 'val_xlsx_');
    file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { unlink($tmp); return []; }
    $shared = [];
    $xml = @$zip->getFromName('xl/sharedStrings.xml');
    if ($xml) {
        preg_match_all('/<si>.*?<\/si>/s', $xml, $m);
        foreach ($m[0] as $si) {
            preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
            $shared[] = html_entity_decode(implode('', $tm[1]), ENT_XML1, 'UTF-8');
        }
    }
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close(); unlink($tmp);
    if (!$sheet_xml) return [];
    $rows = [];
    preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet_xml, $rm);
    foreach ($rm[1] as $row_xml) {
        $cells = [];
        preg_match_all('/<c ([^>]*)>(.*?)<\/c>/s', $row_xml, $cm);
        for ($i = 0; $i < count($cm[0]); $i++) {
            $attrs = $cm[1][$i]; $inner = $cm[2][$i];
            preg_match('/r="([A-Z]+)/', $attrs, $ref);
            $col = 0;
            if (isset($ref[1])) {
                foreach (str_split($ref[1]) as $ch) $col = $col * 26 + ord($ch) - 64;
                $col--;
            }
            $val = '';
            if (preg_match('/<v>(.*?)<\/v>/', $inner, $vm)) {
                if (preg_match('/t="s"/', $attrs)) {
                    $val = $shared[(int)$vm[1]] ?? '';
                } else {
                    $val = $vm[1];
                }
            }
            while (count($cells) < $col) $cells[] = '';
            $cells[$col] = html_entity_decode($val, ENT_XML1, 'UTF-8');
        }
        $rows[] = $cells;
    }
    return $rows;
}

function parse_csv_val(string $raw, string $encoding = 'utf8'): array {
    if ($encoding === 'utf16le') {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    } elseif ($encoding === 'win1252') {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $raw = ltrim($raw, "\xEF\xBB\xBF");
    $rows = [];
    foreach (str_getcsv($raw, "\n") as $line) {
        if (trim($line) === '') continue;
        $rows[] = str_getcsv($line);
    }
    return $rows;
}

function fuzzy_match_supplier(string $raw, string $source_val): bool {
    $r = strtolower(trim($raw));
    $s = strtolower(trim($source_val));
    if ($r === '' || $s === '') return false;
    if ($r === $s) return true;
    if (strpos($s, $r) !== false || strpos($r, $s) !== false) return true;
    if (strlen($r) >= 6 && strpos($s, substr($r, 0, 6)) === 0) return true;
    return false;
}

// Load sample rows to validate
$rows = $pdo->prepare("
    SELECT t.id, t.council, t.supplier_raw, t.supplier_canon, t.service, t.amount, t.paid_date, t.period,
           s.url AS source_url, s.format, s.encoding, s.col_supplier, s.col_amount, s.col_date, s.skip_rows
    FROM ct_transparency_spend t
    JOIN ct_spend_sources s ON s.id = t.source_id
    WHERE t.council = ?
    AND t.source_id IS NOT NULL
    AND t.validation_status IS NULL
    AND t.supplier_canon IS NOT NULL
    ORDER BY RAND()
    LIMIT " . (int)$sample_size . "
");
$rows->execute([$filter_council]);
$samples = $rows->fetchAll(PDO::FETCH_ASSOC);

if (empty($samples)) {
    echo "No unvalidated rows with source_id found for: $filter_council\n";
    exit(0);
}

echo "Validating " . count($samples) . " rows for $filter_council\n\n";

$update = $pdo->prepare("
    UPDATE ct_transparency_spend
    SET validation_status=?, validation_notes=?, validated_at=NOW()
    WHERE id=?
");

// Cache downloaded source files per URL: ['headers' => [...], 'rows' => [...]]
$file_cache = [];

foreach ($samples as $row) {
    $url = $row['source_url'];
    $fmt = $row['format'];
    $enc = $row['encoding'] ?? 'utf8';

    echo sprintf("  [%d] %s / £%s / %s\n",
        $row['id'], substr($row['supplier_raw'], 0, 40),
        number_format((float)$row['amount']), $row['period']);

    // Fetch + parse source file (cached)
    if (!isset($file_cache[$url])) {
        $raw = fetch_url_val($url);
        if (!$raw) {
            echo "    -> SKIP: could not fetch source URL\n";
            $file_cache[$url] = ['headers' => [], 'rows' => []];
            continue;
        }
        $skip = max(0, (int)($row['skip_rows'] ?? 1));
        $all_rows = ($fmt === 'xlsx') ? parse_xlsx_val($raw) : parse_csv_val($raw, $enc);
        // First non-empty row before skip is the header
        $headers = ($skip > 0 && !empty($all_rows[0])) ? $all_rows[0] : [];
        $data_rows = array_slice($all_rows, $skip);
        if (count($data_rows) > MAX_SOURCE_ROWS) {
            $data_rows = array_slice($data_rows, 0, MAX_SOURCE_ROWS);
            echo "    -> Source: " . MAX_SOURCE_ROWS . " rows (capped)\n";
        } else {
            echo "    -> Source: " . count($data_rows) . " rows parsed\n";
        }
        $file_cache[$url] = ['headers' => $headers, 'rows' => $data_rows];
    }
    $source_rows = $file_cache[$url]['rows'];
    $source_headers = $file_cache[$url]['headers'];

    if (empty($source_rows)) {
        echo "    -> SKIP: empty parse result\n";
        continue;
    }

    // Try to find matching row: supplier + amount match
    $col_sup = (int)$row['col_supplier'];
    $col_amt = (int)$row['col_amount'];
    $target_amount = round((float)$row['amount'], 2);
    $target_supplier = $row['supplier_raw'];

    $found_row = null;
    foreach ($source_rows as $sr) {
        $src_supplier = trim($sr[$col_sup] ?? '');
        $src_amount_raw = preg_replace('/[^0-9.\-]/', '', $sr[$col_amt] ?? '');
        $src_amount = round((float)$src_amount_raw, 2);
        if (abs($src_amount - $target_amount) < 0.02 && fuzzy_match_supplier($target_supplier, $src_supplier)) {
            $found_row = $sr;
            break;
        }
    }

    // Fallback: scan all columns for amount match (handles auto-detected layouts
    // where col_supplier/col_amount defaults don't match actual positions)
    if (!$found_row && $target_amount > 0) {
        foreach ($source_rows as $sr) {
            $ncols = count($sr);
            for ($ci = 0; $ci < $ncols; $ci++) {
                $src_amount_raw = preg_replace('/[^0-9.\-]/', '', $sr[$ci] ?? '');
                $src_amount = round((float)$src_amount_raw, 2);
                if (abs($src_amount - $target_amount) < 0.02 && $src_amount > 0) {
                    // Found amount match — now check all string cols for supplier
                    for ($cj = 0; $cj < $ncols; $cj++) {
                        if (fuzzy_match_supplier($target_supplier, trim($sr[$cj] ?? ''))) {
                            $found_row = $sr;
                            break 3;
                        }
                    }
                }
            }
        }
        if ($found_row) {
            echo "    -> (fallback column scan)\n";
        }
    }

    if (!$found_row) {
        echo "    -> NOT FOUND in source (skipping)\n";
        continue;
    }

    // Collect all non-empty extra fields from the source row, labelled with headers where available
    $parts = [];
    foreach ($found_row as $ci => $cell) {
        $cell = trim((string)$cell);
        if ($cell === '' || $cell === '0' || $cell === '0.00') continue;
        $hdr = isset($source_headers[$ci]) ? trim($source_headers[$ci]) : '';
        $parts[] = ($hdr !== '') ? "{$hdr}: {$cell}" : $cell;
    }
    $extras_str = implode(' | ', $parts);

    // Check confirmed supplier rulings first (DB-driven, overrides keyword logic)
    $canon_lower = strtolower($row['supplier_canon'] ?? '');
    $supplier_confirmed_valid   = false;
    $supplier_confirmed_invalid = false;
    foreach ($confirmed_valid_patterns as $pat) {
        if (strpos($canon_lower, strtolower($pat)) !== false) { $supplier_confirmed_valid = true; break; }
    }
    foreach ($confirmed_invalid_patterns as $pat) {
        if (strpos($canon_lower, strtolower($pat)) !== false) { $supplier_confirmed_invalid = true; break; }
    }

    if ($supplier_confirmed_valid) {
        $status = 'valid';
    } elseif ($supplier_confirmed_invalid) {
        $status = 'invalid';
    } else {
        $has_it     = has_it_keyword($extras_str);
        $has_non_it = has_non_it_keyword($extras_str);
        if ($has_it)                    { $status = 'valid'; }
        elseif ($has_non_it && !$has_it){ $status = 'invalid'; }
        else                            { $status = 'uncertain'; }
    }
    $notes = 'Source: ' . $extras_str;

    echo "    -> " . strtoupper($status) . ": $notes\n";
    $update->execute([$status, $notes, $row['id']]);
    if ($status === 'invalid') {
        log_invalid($row, $notes);
    }
}

$counts = $pdo->prepare("
    SELECT validation_status, COUNT(*) as cnt
    FROM ct_transparency_spend
    WHERE council = ? AND validation_status IS NOT NULL
    GROUP BY validation_status
");
$counts->execute([$filter_council]);
echo "\n--- $filter_council validation totals ---\n";
foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['validation_status']}: {$c['cnt']}\n";
}
