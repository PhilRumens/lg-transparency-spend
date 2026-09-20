<?php
/**
 * reclassify_contract_entries.php
 *
 * Standalone re-classification pass over ct_contract_register_entries.
 * Applies the current ct_supplier_patterns (excluding confirmed_invalid=1) to
 * recompute is_tech and supplier_canon for every row, matching the exact logic
 * used in import_contract_registers.php's match_supplier_cr() function.
 *
 * Usage:
 *   php8.1 reclassify_contract_entries.php [--dry-run]
 */

$dry_run = in_array('--dry-run', $argv ?? []);
echo ($dry_run ? "[DRY RUN] " : "") . "reclassify_contract_entries.php starting\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

require_once __DIR__ . '/../config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=architect;charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── Load patterns (active only: confirmed_invalid != 1) ────────────────────
$pattern_rows = $pdo->query(
    "SELECT pattern, canonical_name, match_type
     FROM ct_supplier_patterns
     WHERE confirmed_invalid != 1
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$substring_patterns   = [];
$word_boundary_patterns = [];
foreach ($pattern_rows as $r) {
    if ($r['match_type'] === 'word_boundary') {
        $word_boundary_patterns[$r['pattern']] = $r['canonical_name'];
    } else {
        $substring_patterns[$r['pattern']] = $r['canonical_name'];
    }
}
echo "Loaded " . count($pattern_rows) . " active patterns ("
    . count($word_boundary_patterns) . " word_boundary, "
    . count($substring_patterns) . " substring)\n\n";

// ── Matching function — mirrors match_supplier_cr() exactly ───────────────
function match_supplier(string $raw, array $word_boundary_patterns, array $substring_patterns): ?string {
    $lower = strtolower(trim($raw));
    if ($lower === '' || $lower === 'redacted' || str_contains($lower, 'redacted')) return null;
    foreach ($word_boundary_patterns as $pattern => $canon) {
        if (preg_match('/\b' . preg_quote($pattern, '/') . '\b/', $lower)) return $canon;
    }
    foreach ($substring_patterns as $pattern => $canon) {
        if (str_contains($lower, $pattern)) return $canon ?: null;
    }
    return null;
}

// ── Title-based negative filter — mirrors import_contract_registers.php ───
$non_tech_keywords = [
    'leisure centre', 'sport & leisure', 'sport and leisure',
    'property valuation', 'property and land', 'employers agent',
    'apprenticeship training', 'social care training',
    'land referencing', 'active travel', 'highway maintenance',
    'cleaning equipment', 'cleaning services', 'waste collection',
    'grounds maintenance', 'grass cutting', 'catering',
    'construction', 'building works', 'refurbishment',
    'car park', 'parking management', 'school rebuild',
    'school meals', 'school transport', 'passenger transport',
    'housing repairs', 'responsive repairs', 'planned maintenance',
    'care homes', 'residential care', 'nursing care',
    'homecare', 'domiciliary care', 'foster',
    'legal services', 'insurance', 'audit services',
    'recruitment', 'agency staff', 'temporary staff',
    'printing services', 'mail room',
    'security guard', 'security services',
    'background check', 'bulk print', 'print and post',
];

function title_is_non_tech(string $title, array $non_tech_keywords): bool {
    $lower = strtolower($title);
    foreach ($non_tech_keywords as $kw) {
        if (str_contains($lower, $kw)) return true;
    }
    return false;
}

// ── Stats ──────────────────────────────────────────────────────────────────
$total_checked  = 0;
$changed_to_1   = 0;
$changed_to_0   = 0;
$unchanged      = 0;
$canon_updated  = 0;

// ── Batch processing ──────────────────────────────────────────────────────
$batch_size = 1000;
$offset     = 0;

$select_stmt = $pdo->prepare(
    "SELECT id, supplier, supplier_canon, contract_title, is_tech
     FROM ct_contract_register_entries
     ORDER BY id
     LIMIT :limit OFFSET :offset"
);

if (!$dry_run) {
    $update_stmt = $pdo->prepare(
        "UPDATE ct_contract_register_entries
         SET is_tech = :is_tech, supplier_canon = :canon
         WHERE id = :id"
    );
}

echo "Processing in batches of $batch_size...\n";

while (true) {
    $select_stmt->bindValue(':limit',  $batch_size, PDO::PARAM_INT);
    $select_stmt->bindValue(':offset', $offset,     PDO::PARAM_INT);
    $select_stmt->execute();
    $rows = $select_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) break;

    foreach ($rows as $row) {
        $total_checked++;

        $canon    = match_supplier($row['supplier'], $word_boundary_patterns, $substring_patterns);
        $new_tech = ($canon !== null) ? 1 : 0;

        // Apply title-based negative filter if supplier matched and title present
        if ($new_tech === 1 && !empty($row['contract_title'])) {
            if (title_is_non_tech($row['contract_title'], $non_tech_keywords)) {
                $new_tech = 0;
                $canon    = null;
            }
        }

        // Determine new canon: if matched, use canonical name; else keep raw supplier
        // (matches import logic: $canon ?? $supplier_raw)
        $new_canon = $canon ?? $row['supplier'];
        // Truncate to column width
        $new_canon = mb_strimwidth($new_canon, 0, 300);

        $tech_changed  = ((int)$row['is_tech'] !== $new_tech);
        $canon_changed = ($row['supplier_canon'] !== $new_canon);

        if ($tech_changed || $canon_changed) {
            if ($tech_changed) {
                if ($new_tech === 1) {
                    $changed_to_1++;
                } else {
                    $changed_to_0++;
                }
            } else {
                // canon changed only
                $canon_updated++;
            }

            if (!$dry_run) {
                $update_stmt->execute([
                    ':is_tech' => $new_tech,
                    ':canon'   => $new_canon,
                    ':id'      => $row['id'],
                ]);
            }
        } else {
            $unchanged++;
        }
    }

    $offset += $batch_size;
    if ($offset % 10000 === 0) {
        echo "  ... processed $offset rows so far\n";
    }
}

echo "\n=== Results ===\n";
echo "Total rows checked:      $total_checked\n";
echo "Changed to is_tech=1:    $changed_to_1\n";
echo "Changed to is_tech=0:    $changed_to_0\n";
echo "Canon-only updates:      $canon_updated\n";
echo "Unchanged:               $unchanged\n";
echo "Total rows affected:     " . ($changed_to_1 + $changed_to_0 + $canon_updated) . "\n";
if ($dry_run) {
    echo "\n[DRY RUN] No changes committed to database.\n";
} else {
    echo "\nChanges committed to database.\n";
}
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
