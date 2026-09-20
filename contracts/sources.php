<?php
require_once __DIR__ . '/config.php';
require_login();
require_once __DIR__ . '/_layout.php';

$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

// ── Ensure tables exist ───────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS ct_spend_sources (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        council       VARCHAR(100)  NOT NULL,
        url           VARCHAR(500)  NOT NULL,
        period        VARCHAR(7)    NOT NULL,
        format        ENUM('csv','xlsx','post_csv') NOT NULL DEFAULT 'csv',
        active        TINYINT NOT NULL DEFAULT 1,
        last_fetched  DATETIME NULL,
        last_count    INT NULL,
        notes         VARCHAR(300),
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_council_period (council(80), period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS ct_council_config (
        council       VARCHAR(100) NOT NULL PRIMARY KEY,
        encoding      ENUM('utf8','utf16le','utf16be','win1252') NOT NULL DEFAULT 'utf8',
        col_supplier  TINYINT NOT NULL DEFAULT 0,
        col_amount    TINYINT NOT NULL DEFAULT 1,
        col_date      TINYINT NOT NULL DEFAULT 2,
        col_service   TINYINT NOT NULL DEFAULT -1,
        skip_rows       TINYINT NOT NULL DEFAULT 1,
        col_category    TINYINT NOT NULL DEFAULT -1,
        category_filter VARCHAR(200) NOT NULL DEFAULT '',
        notes           VARCHAR(300),
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
try { $pdo->exec("ALTER TABLE ct_council_config ADD COLUMN col_category TINYINT NOT NULL DEFAULT -1 AFTER skip_rows"); } catch(Throwable $e) {}
try { $pdo->exec("ALTER TABLE ct_council_config ADD COLUMN category_filter VARCHAR(200) NOT NULL DEFAULT '' AFTER col_category"); } catch(Throwable $e) {}
try { $pdo->exec("ALTER TABLE ct_council_config ADD COLUMN cloudflare_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER notes"); } catch(Throwable $e) {}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS ct_ca_config (
        ca_name       VARCHAR(100) NOT NULL PRIMARY KEY,
        ca_type       VARCHAR(50)  NOT NULL DEFAULT '',
        region        VARCHAR(50)  NOT NULL DEFAULT '',
        encoding      ENUM('utf8','utf16le','utf16be','win1252') NOT NULL DEFAULT 'utf8',
        col_supplier  TINYINT NOT NULL DEFAULT 0,
        col_amount    TINYINT NOT NULL DEFAULT 1,
        col_date      TINYINT NOT NULL DEFAULT 2,
        col_service   TINYINT NOT NULL DEFAULT -1,
        skip_rows     TINYINT NOT NULL DEFAULT 1,
        col_category    TINYINT NOT NULL DEFAULT -1,
        category_filter VARCHAR(200) NOT NULL DEFAULT '',
        notes           VARCHAR(300),
        listing_url     VARCHAR(500),
        cloudflare_blocked TINYINT(1) NOT NULL DEFAULT 0,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS ct_transparency_spend (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        council        VARCHAR(100)  NOT NULL,
        supplier_raw   VARCHAR(300)  NOT NULL,
        supplier_canon VARCHAR(200)  NOT NULL,
        service        VARCHAR(200),
        amount         DECIMAL(14,2) NOT NULL,
        paid_date      DATE,
        period         VARCHAR(7)    NOT NULL,
        source_id      INT UNSIGNED  NULL,
        fetched_at     DATETIME      NOT NULL,
        INDEX idx_council  (council(80)),
        INDEX idx_supplier (supplier_canon(100)),
        INDEX idx_period   (period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Seed default council configs if missing
$pdo->exec("
    INSERT IGNORE INTO ct_council_config (council,encoding,col_supplier,col_amount,col_date,col_service,skip_rows,notes)
    VALUES
    ('West Sussex County Council','utf8',7,8,5,1,1,'Col 7=supplier, 8=total, 5=date, 1=service'),
    ('West Berkshire Council','utf8',5,4,3,0,1,'Col 5=supplier, 4=net amount, 3=date (Excel serial), 0=service')
");

$edit_id     = (int)($_GET['edit']  ?? 0);
$clone_id    = (int)($_GET['clone'] ?? 0);
$edit_council = $_GET['edit_council'] ?? '';

// Council links use the ONS lad_code where one exists rather than the
// free-text council name. Resolve it back to the name for every other
// comparison in this file. Councils with no lad_code (the 21 two-tier
// county councils, which span multiple LADs) keep using the name directly.
function resolve_council_id(PDO $pdo, string $value): string {
    if ($value === '' || !preg_match('/^[A-Z]\d{8}$/', $value)) return $value;
    $lookup = $pdo->prepare('SELECT council FROM ct_council_config WHERE lad_code = ?');
    $lookup->execute([$value]);
    return $lookup->fetchColumn() ?: $value;
}
function council_url_id(string $name, ?string $lad_code): string {
    return $lad_code !== null && $lad_code !== '' ? $lad_code : urlencode($name);
}
$edit_council = resolve_council_id($pdo, $edit_council);

$type = $_GET['type'] ?? 'councils';
if (!in_array($type, ['councils', 'ca', 'devolved'], true)) $type = 'councils';
// Devolved national governments (Scottish/Welsh/NI Government) are stored as
// ordinary councils (ct_council_config council_type='Strategic Authority',
// region one of the three devolved nations) but are not local councils —
// broken out into their own tab/stat rather than counted as "Councils".
$devolved_nations = ['Scotland', 'Wales', 'Northern Ireland'];
// Adur and Worthing Councils Joint has no valid lad_code and its spend data
// already splits 50/50 into the separate Adur District and Worthing councils,
// so counting it as its own council would double it up in the headline stat.
$council_count_exclusions = ["Adur and Worthing Councils Joint"];
function is_devolved_council(?array $cfg, array $devolved_nations): bool {
    return $cfg !== null
        && ($cfg['council_type'] ?? '') === 'Strategic Authority'
        && in_array($cfg['region'] ?? '', $devolved_nations, true);
}

// ── Handle POST ───────────────────────────────────────────────────────────
$msg = '';
$msg_type = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_source') {
        try {
            $pdo->prepare("
                INSERT INTO ct_spend_sources (council,url,period,format,active)
                VALUES (:council,:url,:period,:format,1)
                ON DUPLICATE KEY UPDATE url=:url2, format=:format2, active=1
            ")->execute([
                ':council' => trim($_POST['council']),
                ':url'     => trim($_POST['url']),
                ':period'  => trim($_POST['period']),
                ':format'  => $_POST['format'] ?? 'csv',
                ':url2'    => trim($_POST['url']),
                ':format2' => $_POST['format'] ?? 'csv',
            ]);
            $msg = "Source added for " . htmlspecialchars(trim($_POST['council'])) . " " . htmlspecialchars(trim($_POST['period']));
        } catch (Throwable $e) {
            $msg = "Error: " . $e->getMessage(); $msg_type = 'err';
        }
    }

    if ($action === 'edit_source') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("
                UPDATE ct_spend_sources SET council=:council, url=:url, period=:period, format=:format, notes=:notes WHERE id=:id
            ")->execute([
                ':council' => trim($_POST['council']),
                ':url'     => trim($_POST['url']),
                ':period'  => trim($_POST['period']),
                ':format'  => $_POST['format'] ?? 'csv',
                ':notes'   => trim($_POST['notes'] ?? ''),
                ':id'      => $id,
            ]);
            $msg = "Source updated";
        } catch (Throwable $e) {
            $msg = "Error: " . $e->getMessage(); $msg_type = 'err';
        }
    }

    if ($action === 'save_council_config') {
        try {
            $pdo->prepare("
                INSERT INTO ct_council_config (council,encoding,col_supplier,col_amount,col_date,col_service,skip_rows,col_category,category_filter,notes,cloudflare_blocked)
                VALUES (:council,:encoding,:cs,:ca,:cd,:cv,:sr,:cc,:cf,:notes,:cfb)
                ON DUPLICATE KEY UPDATE
                    encoding=:encoding2, col_supplier=:cs2, col_amount=:ca2,
                    col_date=:cd2, col_service=:cv2, skip_rows=:sr2,
                    col_category=:cc2, category_filter=:cf2, notes=:notes2, cloudflare_blocked=:cfb2
            ")->execute([
                ':council'  => trim($_POST['council']),
                ':encoding' => $_POST['encoding'] ?? 'utf8',
                ':cs'       => (int)($_POST['col_supplier']  ?? 0),
                ':ca'       => (int)($_POST['col_amount']    ?? 1),
                ':cd'       => (int)($_POST['col_date']      ?? 2),
                ':cv'       => (int)($_POST['col_service']   ?? -1),
                ':sr'       => (int)($_POST['skip_rows']     ?? 1),
                ':cc'       => (int)($_POST['col_category']  ?? -1),
                ':cf'       => trim($_POST['category_filter'] ?? ''),
                ':notes'    => trim($_POST['notes'] ?? ''),
                ':cfb'      => isset($_POST['cloudflare_blocked']) ? 1 : 0,
                ':encoding2'=> $_POST['encoding'] ?? 'utf8',
                ':cs2'      => (int)($_POST['col_supplier']  ?? 0),
                ':ca2'      => (int)($_POST['col_amount']    ?? 1),
                ':cd2'      => (int)($_POST['col_date']      ?? 2),
                ':cv2'      => (int)($_POST['col_service']   ?? -1),
                ':sr2'      => (int)($_POST['skip_rows']     ?? 1),
                ':cc2'      => (int)($_POST['col_category']  ?? -1),
                ':cf2'      => trim($_POST['category_filter'] ?? ''),
                ':notes2'   => trim($_POST['notes'] ?? ''),
                ':cfb2'     => isset($_POST['cloudflare_blocked']) ? 1 : 0,
            ]);
            $msg = "Column config saved for " . htmlspecialchars(trim($_POST['council']));
        } catch (Throwable $e) {
            $msg = "Error: " . $e->getMessage(); $msg_type = 'err';
        }
    }

    if ($action === 'clear_council') {
        $council_name = $_POST['council_name'] ?? '';
        if ($council_name) {
            $pdo->prepare("DELETE FROM ct_transparency_spend WHERE council = ?")->execute([$council_name]);
            $deleted = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
            $msg = "Cleared " . number_format($deleted) . " rows for " . htmlspecialchars($council_name);
        }
    }

    if ($action === 'bulk_toggle') {
        $ids    = array_map('intval', $_POST['ids'] ?? []);
        $enable = ($_POST['bulk_action'] ?? '') === 'enable' ? 1 : 0;
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE ct_spend_sources SET active={$enable} WHERE id IN ({$placeholders})")
                ->execute($ids);
            $msg = ($enable ? 'Enabled' : 'Disabled') . ' ' . count($ids) . ' source(s)';
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE ct_spend_sources SET active = 1 - active WHERE id=?")->execute([$id]);
        $msg = "Source updated";
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM ct_spend_sources WHERE id=?")->execute([$id]);
        $msg = "Source deleted";
    }

    if ($action === 'rebuild_summaries') {
        // Rebuild summary tables using bulk INSERT...SELECT (avoids PHP timeout on row-by-row inserts)
        $pdo->beginTransaction();
        try {
            // Council summary
            $pdo->exec("DELETE FROM ct_council_summary");
            $pdo->exec("
                INSERT INTO ct_council_summary (lad_code, total_spend, payment_count, updated_at)
                SELECT lad_code, SUM(amount), COUNT(*), NOW()
                FROM ct_transparency_spend
                WHERE lad_code IS NOT NULL AND lad_code != '' AND internal_provider = 0 AND (validation_status IS NULL OR validation_status != 'invalid')
                GROUP BY lad_code
            ");
            $council_count = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();

            // Supplier summary
            $pdo->exec("DELETE FROM ct_supplier_summary");
            $pdo->exec("
                INSERT INTO ct_supplier_summary (canonical_name, council_count, payment_count, total_spend, contract_count, contract_value, updated_at)
                SELECT t.supplier_canon, COUNT(DISTINCT t.council), COUNT(*), SUM(t.amount),
                       COALESCE(r.contract_count,0), COALESCE(r.contract_value,0), NOW()
                FROM ct_transparency_spend t
                LEFT JOIN (
                    SELECT supplier_canon, COUNT(*) as contract_count, SUM(value_amount) as contract_value
                    FROM ct_contract_register_entries WHERE is_tech=1 GROUP BY supplier_canon
                ) r ON r.supplier_canon = t.supplier_canon
                WHERE t.supplier_canon IS NOT NULL AND t.supplier_canon != '' AND t.internal_provider = 0 AND (t.validation_status IS NULL OR t.validation_status != 'invalid')
                GROUP BY t.supplier_canon, r.contract_count, r.contract_value
            ");
            $supplier_count = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();

            // Supplier FY summary
            $pdo->exec("DELETE FROM ct_supplier_summary_fy");
            $pdo->exec("
                INSERT INTO ct_supplier_summary_fy (canonical_name, fy_year, council_count, payment_count, total_spend, updated_at)
                SELECT supplier_canon,
                       CASE WHEN MONTH(paid_date) >= 4 THEN YEAR(paid_date) ELSE YEAR(paid_date)-1 END,
                       COUNT(DISTINCT council), COUNT(*), SUM(amount), NOW()
                FROM ct_transparency_spend
                WHERE supplier_canon IS NOT NULL AND supplier_canon != '' AND paid_date IS NOT NULL AND internal_provider = 0 AND (validation_status IS NULL OR validation_status != 'invalid')
                GROUP BY supplier_canon, CASE WHEN MONTH(paid_date) >= 4 THEN YEAR(paid_date) ELSE YEAR(paid_date)-1 END
            ");
            $fy_count = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();

            // Council FY summary
            $pdo->exec("DELETE FROM ct_council_summary_fy");
            $pdo->exec("
                INSERT INTO ct_council_summary_fy (lad_code, fy_year, total_spend, payment_count, supplier_count)
                SELECT lad_code,
                       CASE WHEN CAST(SUBSTRING(period,6,2) AS UNSIGNED) >= 4
                            THEN CAST(SUBSTRING(period,1,4) AS UNSIGNED)
                            ELSE CAST(SUBSTRING(period,1,4) AS UNSIGNED) - 1
                       END AS fy,
                       SUM(amount), COUNT(*), COUNT(DISTINCT supplier_canon)
                FROM ct_transparency_spend
                WHERE internal_provider = 0
                  AND lad_code IS NOT NULL AND lad_code != ''
                  AND (validation_status IS NULL OR validation_status != 'invalid')
                GROUP BY lad_code, fy
            ");
            $council_fy_count = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();

            // Nation+FY summary for supplier.php fast lookups
            $pdo->exec("DELETE FROM ct_supplier_summary_nation_fy");
            $pdo->exec("
                INSERT INTO ct_supplier_summary_nation_fy (canonical_name, fy_year, lad_prefix, council_count, payment_count, total_spend, updated_at)
                SELECT ts.supplier_canon,
                       CASE WHEN CAST(SUBSTRING(ts.period,6,2) AS UNSIGNED) >= 4
                            THEN CAST(SUBSTRING(ts.period,1,4) AS UNSIGNED)
                            ELSE CAST(SUBSTRING(ts.period,1,4) AS UNSIGNED) - 1
                       END AS fy,
                       LEFT(ts.lad_code, 1) AS lad_prefix,
                       COUNT(DISTINCT ts.council), COUNT(*), SUM(ts.amount), NOW()
                FROM ct_transparency_spend ts
                LEFT JOIN ct_council_config cc ON cc.lad_code = ts.lad_code
                WHERE ts.supplier_canon IS NOT NULL AND ts.supplier_canon != ''
                  AND ts.lad_code IS NOT NULL AND ts.lad_code != ''
                  AND ts.internal_provider = 0
                  AND (ts.validation_status IS NULL OR ts.validation_status != 'invalid')
                  AND NOT (cc.council_type = 'Strategic Authority' AND cc.region IN ('Scotland','Wales','Northern Ireland'))
                GROUP BY ts.supplier_canon, fy, lad_prefix
            ");
            // NOTE: devolved-nation Strategic Authorities (Scottish/Welsh/NI
            // Government) are excluded from the nation rollup above so the
            // Scotland/Wales/NI region views match supplier.php's per-council
            // breakdown (which applies the same exclusion). England SAs (GLA,
            // combined authorities) are intentionally kept. Added 2026-07-10
            // after Scottish Govt (£886M) was inflating the Scotland totals
            // (e.g. BT showed £42M Scotland, £41.9M of it Scottish Govt).
            $pdo->exec("
                INSERT INTO ct_supplier_summary_nation_fy (canonical_name, fy_year, lad_prefix, council_count, payment_count, total_spend, updated_at)
                SELECT ts.supplier_canon,
                       CASE WHEN CAST(SUBSTRING(ts.period,6,2) AS UNSIGNED) >= 4
                            THEN CAST(SUBSTRING(ts.period,1,4) AS UNSIGNED)
                            ELSE CAST(SUBSTRING(ts.period,1,4) AS UNSIGNED) - 1
                       END AS fy,
                       '' AS lad_prefix,
                       COUNT(DISTINCT ts.council), COUNT(*), SUM(ts.amount), NOW()
                FROM ct_transparency_spend ts
                LEFT JOIN ct_council_config cc ON cc.lad_code = ts.lad_code
                WHERE ts.supplier_canon IS NOT NULL AND ts.supplier_canon != ''
                  AND ts.internal_provider = 0
                  AND (ts.validation_status IS NULL OR ts.validation_status != 'invalid')
                  AND NOT (cc.council_type = 'Strategic Authority' AND cc.region IN ('Scotland','Wales','Northern Ireland'))
                GROUP BY ts.supplier_canon, fy
                ON DUPLICATE KEY UPDATE council_count=VALUES(council_count), payment_count=VALUES(payment_count), total_spend=VALUES(total_spend), updated_at=VALUES(updated_at)
            ");
            $nation_fy_count = $pdo->query("SELECT COUNT(*) FROM ct_supplier_summary_nation_fy")->fetchColumn();

            // ---- Joint / shared-service 50/50 attribution (Adur & Worthing) --
            // Mirror of scripts/rebuild_summaries.php. Joint spend has an empty
            // lad_code so it is excluded from the lad-keyed INSERTs above; add
            // each target council's share additively (reads only the tiny joint
            // slice). ct_supplier_summary(_fy) and the UK-wide ('') nation row
            // already include joint at full value. See JOINT_SPLITS.
            $jf = "internal_provider = 0 AND (validation_status IS NULL OR validation_status != 'invalid')";
            $fyExpr = "CASE WHEN CAST(SUBSTRING(period,6,2) AS UNSIGNED) >= 4
                            THEN CAST(SUBSTRING(period,1,4) AS UNSIGNED)
                            ELSE CAST(SUBSTRING(period,1,4) AS UNSIGNED) - 1 END";
            foreach (JOINT_SPLITS as $jointName => $targets) {
                foreach ($targets as $lad => $share) {
                    $prefix = substr($lad, 0, 1);
                    $pdo->prepare("
                        INSERT INTO ct_council_summary (lad_code, total_spend, payment_count, updated_at)
                        SELECT ?, SUM(amount)*?, ROUND(COUNT(*)*?), NOW()
                        FROM ct_transparency_spend WHERE council = ? AND $jf
                        ON DUPLICATE KEY UPDATE
                            total_spend   = total_spend   + VALUES(total_spend),
                            payment_count = payment_count + VALUES(payment_count),
                            updated_at    = VALUES(updated_at)
                    ")->execute([$lad, $share, $share, $jointName]);
                    $pdo->prepare("
                        INSERT INTO ct_council_summary_fy (lad_code, fy_year, total_spend, payment_count, supplier_count)
                        SELECT ?, $fyExpr AS fy, SUM(amount)*?, ROUND(COUNT(*)*?), COUNT(DISTINCT supplier_canon)
                        FROM ct_transparency_spend WHERE council = ? AND $jf
                        GROUP BY fy
                        ON DUPLICATE KEY UPDATE
                            total_spend    = total_spend    + VALUES(total_spend),
                            payment_count  = payment_count  + VALUES(payment_count),
                            supplier_count = supplier_count + VALUES(supplier_count)
                    ")->execute([$lad, $share, $share, $jointName]);
                    $pdo->prepare("
                        INSERT INTO ct_supplier_summary_nation_fy (canonical_name, fy_year, lad_prefix, council_count, payment_count, total_spend, updated_at)
                        SELECT supplier_canon, $fyExpr AS fy, ?, COUNT(DISTINCT council), ROUND(COUNT(*)*?), SUM(amount)*?, NOW()
                        FROM ct_transparency_spend
                        WHERE council = ? AND supplier_canon IS NOT NULL AND supplier_canon != '' AND $jf
                        GROUP BY supplier_canon, fy
                        ON DUPLICATE KEY UPDATE
                            payment_count = payment_count + VALUES(payment_count),
                            total_spend   = total_spend   + VALUES(total_spend),
                            updated_at    = VALUES(updated_at)
                    ")->execute([$prefix, $share, $share, $jointName]);
                }
            }

            $pdo->commit();
            $msg = "Summaries rebuilt: $council_count councils, $supplier_count suppliers, $fy_count supplier-FY rows, $council_fy_count council-FY rows, $nation_fy_count nation-FY rows";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $msg = "Summary rebuild failed: " . $e->getMessage();
        }
    }

    if ($action === 'ca_add_source') {
        try {
            $pdo->prepare("
                INSERT INTO ct_ca_spend_sources (ca_name,url,period,format,active)
                VALUES (:ca,:url,:period,:format,1)
                ON DUPLICATE KEY UPDATE url=:url2, format=:format2, active=1
            ")->execute([
                ':ca'      => trim($_POST['ca_name']),
                ':url'     => trim($_POST['url']),
                ':period'  => trim($_POST['period']),
                ':format'  => $_POST['format'] ?? 'csv',
                ':url2'    => trim($_POST['url']),
                ':format2' => $_POST['format'] ?? 'csv',
            ]);
            $msg = "Source added for " . htmlspecialchars(trim($_POST['ca_name'])) . " " . htmlspecialchars(trim($_POST['period']));
        } catch (Throwable $e) {
            $msg = "Error: " . $e->getMessage(); $msg_type = 'err';
        }
    }

    if ($action === 'ca_edit_source') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("
                UPDATE ct_ca_spend_sources SET ca_name=:ca, url=:url, period=:period, format=:format, notes=:notes WHERE id=:id
            ")->execute([
                ':ca'      => trim($_POST['ca_name']),
                ':url'     => trim($_POST['url']),
                ':period'  => trim($_POST['period']),
                ':format'  => $_POST['format'] ?? 'csv',
                ':notes'   => trim($_POST['notes'] ?? ''),
                ':id'      => $id,
            ]);
            $msg = "Source updated";
        } catch (Throwable $e) {
            $msg = "Error: " . $e->getMessage(); $msg_type = 'err';
        }
    }

    if ($action === 'ca_save_config') {
        try {
            $pdo->prepare("
                INSERT INTO ct_ca_config (ca_name,ca_type,region,encoding,col_supplier,col_amount,col_date,col_service,skip_rows,col_category,category_filter,notes,listing_url,cloudflare_blocked)
                VALUES (:ca,:catype,:region,:encoding,:cs,:camt,:cd,:cv,:sr,:cc,:cf,:notes,:listing,:cfb)
                ON DUPLICATE KEY UPDATE
                    ca_type=:catype2, region=:region2, encoding=:encoding2, col_supplier=:cs2, col_amount=:camt2,
                    col_date=:cd2, col_service=:cv2, skip_rows=:sr2,
                    col_category=:cc2, category_filter=:cf2, notes=:notes2, listing_url=:listing2, cloudflare_blocked=:cfb2
            ")->execute([
                ':ca'        => trim($_POST['ca_name']),
                ':catype'    => trim($_POST['ca_type'] ?? ''),
                ':region'    => trim($_POST['region'] ?? ''),
                ':encoding'  => $_POST['encoding'] ?? 'utf8',
                ':cs'        => (int)($_POST['col_supplier']  ?? 0),
                ':camt'      => (int)($_POST['col_amount']    ?? 1),
                ':cd'        => (int)($_POST['col_date']      ?? 2),
                ':cv'        => (int)($_POST['col_service']   ?? -1),
                ':sr'        => (int)($_POST['skip_rows']     ?? 1),
                ':cc'        => (int)($_POST['col_category']  ?? -1),
                ':cf'        => trim($_POST['category_filter'] ?? ''),
                ':notes'     => trim($_POST['notes'] ?? ''),
                ':listing'   => trim($_POST['listing_url'] ?? ''),
                ':cfb'       => isset($_POST['cloudflare_blocked']) ? 1 : 0,
                ':catype2'   => trim($_POST['ca_type'] ?? ''),
                ':region2'   => trim($_POST['region'] ?? ''),
                ':encoding2' => $_POST['encoding'] ?? 'utf8',
                ':cs2'       => (int)($_POST['col_supplier']  ?? 0),
                ':camt2'     => (int)($_POST['col_amount']    ?? 1),
                ':cd2'       => (int)($_POST['col_date']      ?? 2),
                ':cv2'       => (int)($_POST['col_service']   ?? -1),
                ':sr2'       => (int)($_POST['skip_rows']     ?? 1),
                ':cc2'       => (int)($_POST['col_category']  ?? -1),
                ':cf2'       => trim($_POST['category_filter'] ?? ''),
                ':notes2'    => trim($_POST['notes'] ?? ''),
                ':listing2'  => trim($_POST['listing_url'] ?? ''),
                ':cfb2'      => isset($_POST['cloudflare_blocked']) ? 1 : 0,
            ]);
            $msg = "Config saved for " . htmlspecialchars(trim($_POST['ca_name']));
        } catch (Throwable $e) {
            $msg = "Error: " . $e->getMessage(); $msg_type = 'err';
        }
    }

    if ($action === 'ca_clear') {
        $ca_name_post = $_POST['ca_name'] ?? '';
        if ($ca_name_post) {
            $pdo->prepare("DELETE FROM ct_ca_spend WHERE ca_name = ?")->execute([$ca_name_post]);
            $deleted = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
            $msg = "Cleared " . number_format($deleted) . " rows for " . htmlspecialchars($ca_name_post);
        }
    }

    if ($action === 'ca_bulk_toggle') {
        $ids    = array_map('intval', $_POST['ids'] ?? []);
        $enable = ($_POST['bulk_action'] ?? '') === 'enable' ? 1 : 0;
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE ct_ca_spend_sources SET active={$enable} WHERE id IN ({$placeholders})")
                ->execute($ids);
            $msg = ($enable ? 'Enabled' : 'Disabled') . ' ' . count($ids) . ' source(s)';
        }
    }

    if ($action === 'ca_toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE ct_ca_spend_sources SET active = 1 - active WHERE id=?")->execute([$id]);
        $msg = "Source updated";
    }

    if ($action === 'ca_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM ct_ca_spend_sources WHERE id=?")->execute([$id]);
        $msg = "Source deleted";
    }
}

// ── Load data ─────────────────────────────────────────────────────────────
$sources = $pdo->query("
    SELECT s.*, COALESCE(s.last_count, 0) AS imported_count
    FROM ct_spend_sources s
    ORDER BY s.council, s.period DESC
")->fetchAll();

$council_configs = $pdo->query("SELECT * FROM ct_council_config ORDER BY council")->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

$council_spend_totals = $pdo->query("
    SELECT council, SUM(amount) AS total
    FROM ct_transparency_spend
    USE INDEX (idx_council_period)
    WHERE internal_provider = 0
    GROUP BY council
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Actual span of imported payment dates per council — earliest/latest paid_date
// on real (non-internal) spend rows. Keyed by council name to match the loop.
// Councils with no dated spend rows (register-only) simply won't appear here,
// so the display side falls back to printing nothing.
$council_date_ranges = [];
foreach ($pdo->query("
    SELECT council, MIN(paid_date) AS min_date, MAX(paid_date) AS max_date
    FROM ct_transparency_spend
    WHERE internal_provider = 0 AND paid_date IS NOT NULL
    GROUP BY council
")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $council_date_ranges[$r['council']] = [$r['min_date'], $r['max_date']];
}

// Per-council set of periods that ACTUALLY hold spend rows — ground truth for
// coverage. A quarterly council registers one source row per month but marks
// only 1 of every 3 active (dedup satellites); the other months still hold data.
// So coverage must be judged on real data presence, NOT the active flag.
$council_data_periods = [];
foreach ($pdo->query("
    SELECT council, period
    FROM ct_transparency_spend
    GROUP BY council, period
")->fetchAll(PDO::FETCH_NUM) as $r) {
    $council_data_periods[$r[0]][$r[1]] = true;
}

$by_council = [];
foreach ($sources as $s) {
    $by_council[$s['council']][] = $s;
}

// ── Coverage: "total" = distinct registered periods for the council;
// "have" = how many of those actually hold spend rows in ct_transparency_spend.
// This judges coverage by REAL DATA PRESENCE, not the active flag — quarterly
// councils intentionally mark 2-of-3 months inactive (dedup satellites) yet
// those months still hold data, so an active-flag count badly understates them.
// A council is incomplete only when a registered period has NO data (a genuine
// gap: bad/failed import, wrong-year upload, or period never sourced).
//
// COMPLETENESS_CUTOFF (2026-08-07): only periods up to the close of FY2025-26
// count toward completeness. Recent months naturally lag council publication —
// flagging April 2026 onward as "incomplete" penalises councils for months
// that genuinely aren't out yet, not for a real gap. Bump this forward once
// FY2026-27 data is generally expected to exist.
$completeness_cutoff = '2026-03';
$council_completeness = [];
foreach ($by_council as $cname => $csources) {
    $data_periods = $council_data_periods[$cname] ?? [];
    $reg_periods  = array_values(array_unique(array_filter(
        array_map(fn($s) => $s['period'], $csources),
        fn($p) => $p <= $completeness_cutoff
    )));
    $total = count($reg_periods);
    // A period that was successfully fetched and processed but genuinely
    // returned zero tech-matching rows is a CONFIRMED absence, not a gap —
    // distinct from a period that was never successfully imported
    // (last_count IS NULL) or whose import failed. Signal: last_count=0
    // AND last_fetched IS NOT NULL on at least one source row for that period.
    $checked_empty_periods = [];
    foreach ($csources as $s) {
        if ($s['last_count'] !== null && (int)$s['last_count'] === 0 && !empty($s['last_fetched'])) {
            $checked_empty_periods[$s['period']] = true;
        }
    }
    $missing_periods = array_values(array_filter(
        $reg_periods,
        fn($p) => empty($data_periods[$p]) && empty($checked_empty_periods[$p])
    ));
    sort($missing_periods);
    $have = $total - count($missing_periods);
    $is_internal = str_starts_with($council_configs[$cname]['notes'] ?? '', 'INTERNAL');
    $council_completeness[$cname] = [
        'have'     => $have,
        'total'    => $total,
        'missing'  => $missing_periods,
        'complete' => count($missing_periods) === 0 || $is_internal,
        'internal' => $is_internal,
    ];
}
$incomplete_count = count(array_filter($council_completeness, fn($c) => !$c['complete']));

$incomplete_only = isset($_GET['incomplete']) && $_GET['incomplete'] === '1';
$council_search  = trim($_GET['search'] ?? '');

$councils_per_page = 20;
$council_names = array_values(array_filter(
    array_keys($by_council),
    fn($c) => is_devolved_council($council_configs[$c] ?? null, $devolved_nations) === ($type === 'devolved')
));
if ($incomplete_only) {
    $council_names = array_values(array_filter(
        $council_names,
        fn($c) => !$council_completeness[$c]['complete']
    ));
}
if ($council_search !== '') {
    $needle = mb_strtolower($council_search);
    $council_names = array_values(array_filter(
        $council_names,
        fn($c) => str_contains(mb_strtolower($c), $needle)
    ));
}
$total_council_pages = max(1, (int)ceil(count($council_names) / $councils_per_page));
$council_page = max(1, min($total_council_pages, (int)($_GET['page'] ?? 1)));
$page_council_names = array_slice($council_names, ($council_page - 1) * $councils_per_page, $councils_per_page);

// Detail view for a single council (replaces the old accordion expansion)
$council_param = resolve_council_id($pdo, trim($_GET['council'] ?? ''));
$showing_detail = $council_param !== '' && isset($by_council[$council_param]);

// Get clone source if requested
$clone_source = null;
if ($clone_id) {
    $clone_source = $pdo->prepare("SELECT * FROM ct_spend_sources WHERE id=?")->execute([$clone_id])
        ? $pdo->prepare("SELECT * FROM ct_spend_sources WHERE id=?")->execute([$clone_id]) && false
        : null;
    $stmt = $pdo->prepare("SELECT * FROM ct_spend_sources WHERE id=?");
    $stmt->execute([$clone_id]);
    $clone_source = $stmt->fetch();
}

// ── Combined authority load data ────────────────────────────────────────────
$ca_sources = $pdo->query("
    SELECT s.*, COALESCE(s.last_count, 0) AS imported_count
    FROM ct_ca_spend_sources s
    ORDER BY s.ca_name, s.period DESC
")->fetchAll();

$ca_configs = $pdo->query("SELECT * FROM ct_ca_config ORDER BY ca_name")->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

$by_ca = [];
foreach ($ca_sources as $s) {
    $by_ca[$s['ca_name']][] = $s;
}

$ca_completeness = [];
foreach ($by_ca as $caname => $casources) {
    $have  = count(array_filter($casources, fn($s) => $s['active']));
    $total = count($casources);
    $missing_periods = array_values(array_map(
        fn($s) => $s['period'],
        array_filter($casources, fn($s) => !$s['active'])
    ));
    $ca_completeness[$caname] = [
        'have'     => $have,
        'total'    => $total,
        'missing'  => $missing_periods,
        'complete' => $have === $total,
    ];
}
$ca_incomplete_count = count(array_filter($ca_completeness, fn($c) => !$c['complete']));
$ca_incomplete_only  = isset($_GET['ca_incomplete']) && $_GET['ca_incomplete'] === '1';

$ca_names = array_keys($by_ca);
if ($ca_incomplete_only) {
    $ca_names = array_values(array_filter($ca_names, fn($c) => !$ca_completeness[$c]['complete']));
}

$ca_param = trim($_GET['ca'] ?? '');
$showing_ca_detail = $ca_param !== '' && isset($by_ca[$ca_param]);

$ca_edit_id  = (int)($_GET['ca_edit']  ?? 0);
$ca_clone_id = (int)($_GET['ca_clone'] ?? 0);
$edit_ca     = $_GET['edit_ca'] ?? '';

$ca_clone_source = null;
if ($ca_clone_id) {
    $stmt = $pdo->prepare("SELECT * FROM ct_ca_spend_sources WHERE id=?");
    $stmt->execute([$ca_clone_id]);
    $ca_clone_source = $stmt->fetch();
}

layout_head('Spend data sources');
?>

<style>
.source-row-inactive { opacity:0.5; }
.badge { display:inline-block; padding:1px 7px; border-radius:3px; font-size:0.75rem; font-weight:700; }
.badge--active   { background:#cce2d8; color:#005a30; }
.badge--inactive { background:#f3f2f1; color:#505a5f; }
.badge--cf       { background:#fdd; color:#942514; }
.edit-row td { background:#f3f2f1; padding:16px; }
.config-row td { background:#e8f1fb; padding:16px; }
.ct-stats-grid { display:grid; grid-template-columns: 1fr 1fr 0.8fr 0.8fr 0.8fr 1.3fr; gap:16px; }
@media (max-width:900px) { .ct-stats-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:400px) { .ct-stats-grid { grid-template-columns:minmax(0,1fr); } }
.tech-nav { display:flex; gap:0; border-bottom:2px solid #b1b4b6; flex-wrap:wrap; }
.tech-nav a { padding:10px 20px; text-decoration:none; color:#0b0c0c; font-size:1rem; font-weight:400; border-bottom:4px solid transparent; margin-bottom:-2px; }
.tech-nav a:hover { border-bottom-color:#b1b4b6; }
.tech-nav a.active { font-weight:700; border-bottom-color:#1d70b8; color:#1d70b8; }
.src-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:20px; }
.src-toolbar form { display:contents; }
</style>

<?php if ($msg): ?>
<div class="govuk-notification-banner govuk-notification-banner--<?= $msg_type==='ok'?'success':'important' ?>" role="alert">
  <div class="govuk-notification-banner__content"><p class="govuk-body"><?= htmlspecialchars($msg) ?></p></div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="govuk-!-margin-bottom-6 ct-stats-grid">
  <div class="ct-stat-card"><div class="ct-stat-card__number"><?= count($sources) ?></div><div class="ct-stat-card__label">Data sources</div></div>
  <div class="ct-stat-card"><div class="ct-stat-card__number"><?= count(array_filter($sources,fn($s)=>$s['active'])) ?></div><div class="ct-stat-card__label">Active</div></div>
  <div class="ct-stat-card"><div class="ct-stat-card__number"><?= count(array_filter(array_keys($by_council), fn($c) => !is_devolved_council($council_configs[$c] ?? null, $devolved_nations) && !in_array($c, $council_count_exclusions, true))) ?></div><div class="ct-stat-card__label">Councils</div></div>
  <div class="ct-stat-card"><div class="ct-stat-card__number"><?= (int)$pdo->query("SELECT COUNT(*) FROM ct_ca_config")->fetchColumn() ?></div><div class="ct-stat-card__label">Strategic authorities</div></div>
  <div class="ct-stat-card"><div class="ct-stat-card__number"><?= count(array_filter(array_keys($by_council), fn($c) => is_devolved_council($council_configs[$c] ?? null, $devolved_nations))) ?></div><div class="ct-stat-card__label">Devolved governments</div></div>
  <div class="ct-stat-card"><div class="ct-stat-card__number"><?= number_format($pdo->query("SELECT COUNT(*) FROM ct_transparency_spend")->fetchColumn()) ?></div><div class="ct-stat-card__label">Payments imported</div></div>
</div>

<!-- Toolbar -->
<div class="src-toolbar">
  <a href="/contracts/transparency.php" class="govuk-button govuk-!-margin-bottom-0">View spend data</a>
  <a href="/contracts/import_transparency.php" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0">Run import</a>
  <?php if ($is_admin): ?>
  <form method="post">
    <input type="hidden" name="action" value="rebuild_summaries">
    <button type="submit" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0"
      onclick="return confirm('Rebuild council + supplier summary tables from live spend data? This may take 30–60 seconds.')">
      Refresh summaries
    </button>
  </form>
  <?php endif; ?>
  <?php if (($type === 'councils' || $type === 'devolved') && !$showing_detail): ?>
  <form method="get" action="">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
    <?php if ($incomplete_only): ?><input type="hidden" name="incomplete" value="1"><?php endif; ?>
    <input class="govuk-input" style="width:240px" type="text" name="search"
           value="<?= htmlspecialchars($council_search) ?>"
           placeholder="Filter by council…" autocomplete="off">
    <button type="submit" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0">Search</button>
    <?php if ($council_search !== ''): ?>
      <a href="?type=<?= htmlspecialchars($type) ?><?= $incomplete_only ? '&incomplete=1' : '' ?>" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0">✕</a>
    <?php endif; ?>
  </form>
  <?php if ($incomplete_only): ?>
    <a href="?type=<?= htmlspecialchars($type) ?><?= $council_search !== '' ? '&search=' . urlencode($council_search) : '' ?>" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0" style="font-size:0.875rem">✓ Incomplete only (<?= $incomplete_count ?>) — clear</a>
  <?php else: ?>
    <a href="?type=<?= htmlspecialchars($type) ?>&incomplete=1<?= $council_search !== '' ? '&search=' . urlencode($council_search) : '' ?>" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0" style="font-size:0.875rem">Show incomplete only (<?= $incomplete_count ?>)</a>
  <?php endif; ?>
  <?php endif; ?>
</div>

<nav class="tech-nav" style="margin-bottom:24px">
  <a href="?type=councils" class="<?= $type==='councils' ? 'active' : '' ?>">Councils</a>
  <a href="?type=ca"       class="<?= $type==='ca'       ? 'active' : '' ?>">Strategic authorities</a>
  <a href="?type=devolved" class="<?= $type==='devolved' ? 'active' : '' ?>">Devolved governments</a>
</nav>

<?php if ($type === 'councils' || $type === 'devolved'): ?>

<?php if (empty($sources)): ?>
<div class="govuk-inset-text"><p class="govuk-body">No sources yet. Click "Load default sources" or add one below.</p></div>
<?php else: ?>

<!-- Sources by council -->
<?php if ($showing_detail): ?>
<?php
  $council = $council_param;
  $council_sources = $by_council[$council];
  $cfg = $council_configs[$council] ?? null;
  $council_lad = $cfg['lad_code'] ?? null;
  $c_imported = array_sum(array_column($council_sources, 'imported_count'));
  $comp       = $council_completeness[$council];
  $c_active   = count(array_filter($council_sources, fn($s) => $s['active']));
  $c_total    = count($council_sources);
?>
<p class="govuk-!-margin-bottom-4">
  <a href="?type=<?= htmlspecialchars($type) ?>&page=<?= $council_page ?>" class="govuk-back-link">Back to all <?= $type === 'devolved' ? 'devolved governments' : 'councils' ?></a>
</p>

<div style="border:1px solid #b1b4b6;margin-bottom:8px;border-radius:3px">
  <div style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#f3f2f1">
    <h2 class="govuk-heading-m govuk-!-margin-bottom-0"><?= htmlspecialchars($council) ?></h2>
    <?php if (!empty($cfg['cloudflare_blocked'])): ?>
    <span class="badge badge--cf" title="Source site is Cloudflare-blocked — re-imports/backfills need a wayback-archive workaround">☁ Cloudflare-blocked</span>
    <?php endif; ?>
    <div style="display:flex;align-items:center;gap:12px">
      <span class="govuk-body-s" style="color:#505a5f">
        <?= $comp['have'] ?>/<?= $comp['total'] ?> periods covered<?php if (!$comp['complete'] && !empty($comp['missing'])): ?> <span title="Missing data: <?= htmlspecialchars(implode(', ', $comp['missing']), ENT_QUOTES) ?>" style="color:#594d00">⚠</span><?php endif; ?>
        <?php if ($c_imported > 0): ?>&nbsp;· <?= number_format($c_imported) ?> payments imported<?php endif; ?>
      </span>
      <?php if ($is_admin): ?>
      <a href="/contracts/import_transparency.php?council=<?= urlencode($council) ?>&force=1"
         class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0"
         style="font-size:0.75rem;padding:2px 8px"
         onclick="return confirm('Force re-import all data for <?= htmlspecialchars($council, ENT_QUOTES) ?>? This will clear and re-fetch all periods.')">Import</a>
      <?php endif; ?>
      <?php if ($is_admin && $c_imported > 0): ?>
      <form method="post" action="/contracts/sources.php?type=<?= htmlspecialchars($type) ?>&council=<?= council_url_id($council, $council_lad) ?>" style="display:inline;margin:0"
            onsubmit="return confirm('Clear all imported data for <?= htmlspecialchars($council, ENT_QUOTES) ?>? This cannot be undone.')">
        <input type="hidden" name="action" value="clear_council">
        <input type="hidden" name="council_name" value="<?= htmlspecialchars($council) ?>">
        <button type="submit" class="govuk-button govuk-!-margin-bottom-0"
                style="font-size:0.75rem;padding:2px 8px;background:#d4351c;border-color:#d4351c"
                onmouseover="this.style.background='#aa2a15'"
                onmouseout="this.style.background='#d4351c'">Clear data</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div style="padding:16px">
  <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:8px">
    <span></span>
    <?php if ($is_admin): ?>
    <a href="?type=<?= htmlspecialchars($type) ?>&council=<?= council_url_id($council, $council_lad) ?>&edit_council=<?= council_url_id($council, $council_lad) ?>#config-<?= council_url_id($council, $council_lad) ?>" class="govuk-link" style="font-size:0.875rem">
      <?= $cfg ? 'Edit column config' : 'Set column config' ?>
    </a>
    <?php endif; ?>
  </div>

  <?php if ($cfg): ?>
  <div style="background:#e8f1fb;padding:8px 12px;margin-bottom:8px;font-size:0.8rem;color:#1d70b8;border-radius:3px">
    Encoding: <?= htmlspecialchars($cfg['encoding']) ?> ·
    Supplier col: <?= $cfg['col_supplier'] ?> ·
    Amount col: <?= $cfg['col_amount'] ?> ·
    Date col: <?= $cfg['col_date'] ?> ·
    Service col: <?= $cfg['col_service'] ?> ·
    Skip rows: <?= $cfg['skip_rows'] ?><?php if ((int)($cfg['col_category']??-1) >= 0): ?> · Category col: <?= $cfg['col_category'] ?> filter: <em><?= htmlspecialchars($cfg['category_filter']??'') ?></em><?php endif; ?>
    <?php if ($cfg['notes']): ?> · <em><?= htmlspecialchars($cfg['notes']) ?></em><?php endif; ?>
  </div>
  <?php else: ?>
  <div style="background:#fff3cd;padding:8px 12px;margin-bottom:8px;font-size:0.8rem;border-radius:3px">
    ⚠ No column config set for this council — import will use defaults. <a href="?type=<?= htmlspecialchars($type) ?>&council=<?= council_url_id($council, $council_lad) ?>&edit_council=<?= council_url_id($council, $council_lad) ?>#config-<?= council_url_id($council, $council_lad) ?>">Set config →</a>
  </div>
  <?php endif; ?>

  <!-- Column config edit form -->
  <?php if ($edit_council === $council): ?>
  <div id="config-<?= council_url_id($council, $council_lad) ?>" style="background:#e8f1fb;padding:16px;margin-bottom:12px;border:1px solid #1d70b8">
    <h3 class="govuk-heading-s">Column config for <?= htmlspecialchars($council) ?></h3>
    <form method="post" action="/contracts/sources.php?type=<?= htmlspecialchars($type) ?>&council=<?= council_url_id($council, $council_lad) ?>">
      <input type="hidden" name="action" value="save_council_config">
      <input type="hidden" name="council" value="<?= htmlspecialchars($council) ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem">Encoding</label>
          <select class="govuk-select" name="encoding" style="width:100%">
            <?php foreach (['utf8','utf16le','utf16be','win1252'] as $enc): ?>
              <option value="<?= $enc ?>"<?= ($cfg['encoding']??'utf8')===$enc?' selected':'' ?>><?= $enc ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem">Skip header rows</label>
          <input class="govuk-input" type="number" name="skip_rows" value="<?= (int)($cfg['skip_rows']??1) ?>" min="0" max="10">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:12px">
        <?php foreach ([
          ['col_supplier','Supplier col',$cfg['col_supplier']??0],
          ['col_amount',  'Amount col',  $cfg['col_amount']??1],
          ['col_date',    'Date col',    $cfg['col_date']??2],
          ['col_service', 'Service col (-1=none)', $cfg['col_service']??-1],
        ] as [$fname,$flabel,$fval]): ?>
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem"><?= $flabel ?></label>
          <input class="govuk-input" type="number" name="<?= $fname ?>" value="<?= (int)$fval ?>">
        </div>
        <?php endforeach; ?>
      </div>
      <div style="display:grid;grid-template-columns:1fr 3fr;gap:12px;margin-bottom:12px">
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem">Category col (-1=no filter)</label>
          <input class="govuk-input" type="number" name="col_category" value="<?= (int)($cfg['col_category']??-1) ?>">
        </div>
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem">Category filter values (comma-separated, case-insensitive contains match)</label>
          <input class="govuk-input" type="text" name="category_filter" value="<?= htmlspecialchars($cfg['category_filter']??'') ?>" placeholder="e.g. IT,Information Technology,Digital">
        </div>
      </div>
      <div class="govuk-form-group govuk-!-margin-bottom-3">
        <label class="govuk-label" style="font-size:0.875rem">Notes</label>
        <input class="govuk-input" type="text" name="notes" value="<?= htmlspecialchars($cfg['notes']??'') ?>">
      </div>
      <div class="govuk-form-group govuk-!-margin-bottom-3">
        <label class="govuk-checkboxes__label" style="font-size:0.875rem">
          <input type="checkbox" name="cloudflare_blocked" value="1"<?= !empty($cfg['cloudflare_blocked']) ? ' checked' : '' ?>>
          ☁ Source site is Cloudflare-blocked (re-imports need a wayback-archive workaround)
        </label>
      </div>
      <button class="govuk-button govuk-!-margin-bottom-0" type="submit">Save config</button>
      <a href="?type=<?= htmlspecialchars($type) ?>&council=<?= council_url_id($council, $council_lad) ?>" class="govuk-link" style="margin-left:12px">Cancel</a>
    </form>
  </div>
  <?php endif; ?>

  <!-- Sources table -->
  <?php if ($is_admin): ?>
  <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;font-size:0.875rem">
    <button type="button"
            class="govuk-button govuk-button--warning govuk-!-margin-bottom-0"
            style="font-size:0.8rem;padding:4px 10px"
            onclick="bulkToggle('<?= htmlspecialchars(council_url_id($council, $council_lad), ENT_QUOTES) ?>', 'disable')">Disable selected</button>
    <button type="button"
            class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0"
            style="font-size:0.8rem;padding:4px 10px"
            onclick="bulkToggle('<?= htmlspecialchars(council_url_id($council, $council_lad), ENT_QUOTES) ?>', 'enable')">Enable selected</button>
    <a href="javascript:void(0)" onclick="selectAll('<?= htmlspecialchars(council_url_id($council, $council_lad), ENT_QUOTES) ?>')" class="govuk-link" style="margin-left:4px">Select all</a>
    <a href="javascript:void(0)" onclick="selectNone('<?= htmlspecialchars(council_url_id($council, $council_lad), ENT_QUOTES) ?>')" class="govuk-link">None</a>
    <button type="button"
            class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0"
            style="font-size:0.8rem;padding:4px 10px;margin-left:8px"
            onclick="importSelected('<?= htmlspecialchars(council_url_id($council, $council_lad), ENT_QUOTES) ?>', '<?= htmlspecialchars($council, ENT_QUOTES) ?>')">Import selected</button>
  </div>
  <?php endif; ?>
  <table class="govuk-table" style="font-size:0.875rem;margin-bottom:0">
    <thead class="govuk-table__head">
      <tr class="govuk-table__row">
        <?php if ($is_admin): ?><th class="govuk-table__header" style="width:32px"></th><?php endif; ?>
        <th class="govuk-table__header">Period</th>
        <th class="govuk-table__header">URL</th>
        <th class="govuk-table__header">Format</th>
        <th class="govuk-table__header govuk-table__header--numeric">Imported</th>
        <th class="govuk-table__header">Last fetched</th>
        <th class="govuk-table__header">Status</th>
        <?php if ($is_admin): ?><th class="govuk-table__header">Actions</th><?php endif; ?>
      </tr>
    </thead>
    <tbody class="govuk-table__body">
      <?php foreach ($council_sources as $s): ?>
      <tr class="govuk-table__row <?= $s['active']?'':'source-row-inactive' ?>">
        <?php if ($is_admin): ?>
        <td class="govuk-table__cell">
          <input type="checkbox" name="ids[]" value="<?= $s['id'] ?>" data-council="<?= htmlspecialchars(council_url_id($council, $council_lad)) ?>" data-period="<?= htmlspecialchars($s['period']) ?>">
        </td>
        <?php endif; ?>
        <td class="govuk-table__cell" style="white-space:nowrap"><strong><?= htmlspecialchars($s['period']) ?></strong></td>
        <td class="govuk-table__cell" style="max-width:300px">
          <a href="<?= htmlspecialchars($s['url']) ?>" class="govuk-link" target="_blank" style="font-size:0.8rem;word-break:break-all">
            <?= htmlspecialchars(mb_strimwidth($s['url'],0,60,'…')) ?>
          </a>
          <?php if ($s['notes']): ?><div style="font-size:0.75rem;color:#6f777b"><?= htmlspecialchars($s['notes']) ?></div><?php endif; ?>
        </td>
        <td class="govuk-table__cell"><?= strtoupper(htmlspecialchars($s['format'])) ?></td>
        <td class="govuk-table__cell govuk-table__cell--numeric"><?= $s['imported_count']>0?number_format($s['imported_count']):'—' ?></td>
        <td class="govuk-table__cell" style="white-space:nowrap"><?= $s['last_fetched']?htmlspecialchars(substr($s['last_fetched'],0,10)):'—' ?></td>
        <td class="govuk-table__cell">
          <span class="badge badge--<?= $s['active']?'active':'inactive' ?>"><?= $s['active']?'Active':'Inactive' ?></span>
        </td>
        <td class="govuk-table__cell" style="white-space:nowrap;font-size:0.875rem">
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button style="border:none;background:none;cursor:pointer;font-size:0.875rem;padding:0;color:#1d70b8"><?= $s['active']?'Disable':'Enable' ?></button>
          </form>
          &nbsp;·&nbsp;<a href="?type=<?= htmlspecialchars($type) ?>&council=<?= council_url_id($council, $council_lad) ?>&edit=<?= $s['id'] ?>" class="govuk-link">Edit</a>
          &nbsp;·&nbsp;<a href="?type=<?= htmlspecialchars($type) ?>&clone=<?= $s['id'] ?>#add" class="govuk-link">Clone</a>
          &nbsp;·&nbsp;
          <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button style="border:none;background:none;cursor:pointer;font-size:0.875rem;padding:0;color:#d4351c">Delete</button>
          </form>
        </td>
      </tr>
      <?php if ($edit_id===(int)$s['id']): ?>
      <tr class="edit-row">
        <td colspan="7">
          <form method="post" action="/contracts/sources.php?type=<?= htmlspecialchars($type) ?>&council=<?= council_url_id($council, $council_lad) ?>">
            <input type="hidden" name="action" value="edit_source">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
              <div class="govuk-form-group govuk-!-margin-bottom-0">
                <label class="govuk-label" style="font-size:0.875rem">Council</label>
                <input class="govuk-input" type="text" name="council" value="<?= htmlspecialchars($s['council']) ?>" required>
              </div>
              <div class="govuk-form-group govuk-!-margin-bottom-0">
                <label class="govuk-label" style="font-size:0.875rem">Period (YYYY-MM)</label>
                <input class="govuk-input" type="text" name="period" value="<?= htmlspecialchars($s['period']) ?>" required>
              </div>
              <div class="govuk-form-group govuk-!-margin-bottom-0">
                <label class="govuk-label" style="font-size:0.875rem">Format</label>
                <select class="govuk-select" name="format" style="width:100%">
                  <option value="csv"<?= $s['format']==='csv'?' selected':'' ?>>CSV</option>
                  <option value="xlsx"<?= $s['format']==='xlsx'?' selected':'' ?>>XLSX</option>
                </select>
              </div>
            </div>
            <div class="govuk-form-group govuk-!-margin-bottom-3">
              <label class="govuk-label" style="font-size:0.875rem">URL</label>
              <input class="govuk-input" type="url" name="url" value="<?= htmlspecialchars($s['url']) ?>" required>
            </div>
            <div class="govuk-form-group govuk-!-margin-bottom-3">
              <label class="govuk-label" style="font-size:0.875rem">Notes</label>
              <input class="govuk-input" type="text" name="notes" value="<?= htmlspecialchars($s['notes']??'') ?>">
            </div>
            <button class="govuk-button govuk-!-margin-bottom-0" type="submit">Save</button>
            <a href="?type=<?= htmlspecialchars($type) ?>&council=<?= council_url_id($council, $council_lad) ?>" class="govuk-link" style="margin-left:12px">Cancel</a>
          </form>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php else: ?>
<!-- Council list (paginated) -->
<?php if ($council_search !== ''): ?>
<p class="govuk-body-s govuk-!-margin-bottom-2" style="color:#505a5f">
  <?= count($council_names) ?> council<?= count($council_names) != 1 ? 's' : '' ?> match "<?= htmlspecialchars($council_search) ?>".
</p>
<?php endif; ?>
<div id="council-list" style="margin-top:16px">
<?php foreach ($page_council_names as $council): ?>
<?php
  $council_sources = $by_council[$council];
  $cfg = $council_configs[$council] ?? null;
  $c_imported = array_sum(array_column($council_sources, 'imported_count'));
  $c_active   = count(array_filter($council_sources, fn($s) => $s['active']));
  $c_total    = count($council_sources);
  $comp       = $council_completeness[$council];
?>
<div class="ct-council-row" data-council-name="<?= htmlspecialchars(strtolower($council), ENT_QUOTES) ?>" style="border:1px solid #b1b4b6;margin-bottom:8px;border-radius:3px;padding:12px 16px;background:#f3f2f1">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div style="flex:1;min-width:220px">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <strong class="govuk-body govuk-!-margin-bottom-0"><?= htmlspecialchars($council) ?></strong>
        <?php if (!empty($cfg['cloudflare_blocked'])): ?>
        <span class="badge badge--cf" title="Source site is Cloudflare-blocked — re-imports/backfills need a wayback-archive workaround">☁ Cloudflare-blocked</span>
        <?php endif; ?>
        <?php if (!empty($comp['internal'])): ?>
        <span class="badge" style="background:#e8f0fe;color:#1d70b8" title="Internal tracking council — sources intentionally disabled after data split">Internal</span>
        <?php elseif (!$comp['complete']): ?>
        <span class="badge" style="background:#fff3cd;color:#594d00" title="Missing data: <?= htmlspecialchars(implode(', ', $comp['missing']), ENT_QUOTES) ?>">⚠ <?= $comp['have'] ?>/<?= $comp['total'] ?> covered</span>
        <?php endif; ?>
      </div>
      <div class="govuk-body-s" style="color:#505a5f;margin-top:4px">
        <?= $comp['have'] ?>/<?= $comp['total'] ?> periods covered
        <?php if ($c_imported > 0): ?>&nbsp;· <?= number_format($c_imported) ?> payments imported<?php endif; ?>
        <?php $c_spend = $council_spend_totals[$council] ?? 0; if ($c_spend > 0): ?>
        &nbsp;· <strong>£<?= number_format($c_spend / 1e6, 2) ?>M</strong>
        <?php endif; ?>
        <?php if ($cfg && (int)($cfg['col_category'] ?? -1) >= 0 && !empty($cfg['category_filter'])): ?>
        &nbsp;· <span class="badge" style="background:#d4efdf;color:#155724" title="IT spend identified by council's own category field: '<?= htmlspecialchars($cfg['category_filter'], ENT_QUOTES) ?>'">category filter</span>
        <?php elseif ($cfg): ?>
        &nbsp;· <span class="badge" style="background:#ddeeff;color:#003366" title="IT spend identified by matching supplier names against <?= count($council_spend_totals) ?> patterns">supplier patterns</span>
        <?php else: ?>
        &nbsp;· <span style="color:#b1b4b6">⚠ not configured</span>
        <?php endif; ?>
      </div>
      <?php if (!empty($cfg['listing_url'])): ?>
      <div class="govuk-body-s" style="color:#505a5f;margin-top:2px">
        <a href="<?= htmlspecialchars($cfg['listing_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer" style="color:#1d70b8;word-break:break-all"><?= htmlspecialchars($cfg['listing_url'], ENT_QUOTES) ?></a>
      </div>
      <?php endif; ?>
      <?php $c_range = $council_date_ranges[$council] ?? null; if ($c_range && $c_range[0] && $c_range[1]): ?>
      <div class="govuk-body-s" style="color:#505a5f;margin-top:2px">
        Data covered: <?= date('M Y', strtotime($c_range[0])) ?> – <?= date('M Y', strtotime($c_range[1])) ?>
      </div>
      <?php endif; ?>
    </div>
    <a href="?type=<?= htmlspecialchars($type) ?>&council=<?= council_url_id($council, $cfg['lad_code'] ?? null) ?>&page=<?= $council_page ?>" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0" style="flex-shrink:0">View sources →</a>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php $incomplete_qs = '&type=' . urlencode($type) . ($incomplete_only ? '&incomplete=1' : '') . ($council_search !== '' ? '&search=' . urlencode($council_search) : ''); ?>
<?php if ($total_council_pages > 1): ?>
<nav class="govuk-pagination" role="navigation" aria-label="<?= $type === 'devolved' ? 'Devolved governments' : 'Councils' ?> pagination">
  <?php if ($council_page > 1): ?>
  <div class="govuk-pagination__prev">
    <a class="govuk-link govuk-pagination__link" href="?page=<?= $council_page-1 ?><?= $incomplete_qs ?>" rel="prev">
      <svg class="govuk-pagination__icon govuk-pagination__icon--prev" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
        <path d="m6.5938-0.0078125-6.7266 6.7266 6.7441 6.4062 1.377-1.4453-4.1856-3.9768h12.896v-2h-12.984l4.2931-4.293-1.414-1.414z"></path>
      </svg>
      <span class="govuk-pagination__link-title">Previous</span>
    </a>
  </div>
  <?php endif; ?>
  <ul class="govuk-pagination__list">
    <?php for ($p = 1; $p <= $total_council_pages; $p++): ?>
    <li class="govuk-pagination__item<?= $p===$council_page?' govuk-pagination__item--current':'' ?>">
      <a class="govuk-link govuk-pagination__link" href="?page=<?= $p ?><?= $incomplete_qs ?>" aria-label="Page <?= $p ?>"<?= $p===$council_page?' aria-current="page"':'' ?>><?= $p ?></a>
    </li>
    <?php endfor; ?>
  </ul>
  <?php if ($council_page < $total_council_pages): ?>
  <div class="govuk-pagination__next">
    <a class="govuk-link govuk-pagination__link" href="?page=<?= $council_page+1 ?><?= $incomplete_qs ?>" rel="next">
      <span class="govuk-pagination__link-title">Next</span>
      <svg class="govuk-pagination__icon govuk-pagination__icon--next" xmlns="http://www.w3.org/2000/svg" height="13" width="15" aria-hidden="true" focusable="false" viewBox="0 0 15 13">
        <path d="m8.107-0.0078125-1.4136 1.414 4.2926 4.293h-12.986v2h12.896l-4.1855 3.9768 1.377 1.4453 6.7441-6.4062-6.7246-6.7266z"></path>
      </svg>
    </a>
  </div>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php endif; // showing_detail ?>

<?php endif; ?>

<?php if ($is_admin && $clone_source): ?>
<!-- Clone form (add-new removed) -->
<h2 class="govuk-heading-m govuk-!-margin-top-8" id="add">
  Clone source — <?= htmlspecialchars($clone_source['council']) ?>
</h2>
<p class="govuk-body-s govuk-!-colour-secondary">
  Pre-filled from <?= htmlspecialchars($clone_source['council']) ?> <?= htmlspecialchars($clone_source['period']) ?>.
  Update the period and URL for the new month, then save.
</p>

<form method="post" action="/contracts/sources.php#add" style="max-width:700px">
  <input type="hidden" name="action" value="add_source">
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
    <div class="govuk-form-group">
      <label class="govuk-label" for="council">Council</label>
      <input class="govuk-input" type="text" id="council" name="council" required
             value="<?= $clone_source ? htmlspecialchars($clone_source['council']) : '' ?>"
             list="council-list" placeholder="e.g. West Sussex County Council">
      <datalist id="council-list">
        <?php foreach (array_keys($by_council) as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>">
        <?php endforeach; ?>
      </datalist>
    </div>
    <div class="govuk-form-group">
      <label class="govuk-label" for="period">Period (YYYY-MM)</label>
      <input class="govuk-input" type="text" id="period" name="period" required
             pattern="\d{4}-\d{2}" placeholder="2026-01"
             value="<?= $clone_source ? '' : '' ?>">
    </div>
    <div class="govuk-form-group">
      <label class="govuk-label" for="format">Format</label>
      <select class="govuk-select" id="format" name="format" style="width:100%">
        <option value="csv"<?= ($clone_source&&$clone_source['format']==='csv')||!$clone_source?' selected':'' ?>>CSV</option>
        <option value="xlsx"<?= $clone_source&&$clone_source['format']==='xlsx'?' selected':'' ?>>XLSX</option>
      </select>
    </div>
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="url">URL</label>
    <input class="govuk-input" type="url" id="url" name="url" required
           value="<?= $clone_source ? htmlspecialchars($clone_source['url']) : '' ?>">
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="notes">Notes <span style="font-weight:400;color:#505a5f">(optional)</span></label>
    <input class="govuk-input" type="text" id="notes" name="notes"
           value="<?= $clone_source ? htmlspecialchars($clone_source['notes']??'') : '' ?>">
  </div>
  <button class="govuk-button" type="submit"><?= $clone_source ? 'Add cloned source' : 'Add source' ?></button>
</form>



<?php endif; // is_admin ?>

<?php else: // $type === 'ca' ?>

<?php if (empty($ca_sources)): ?>
<div class="govuk-inset-text"><p class="govuk-body">No strategic authority sources yet. Add one below.</p></div>
<?php else: ?>

<!-- Sources by strategic authority -->
<?php if ($showing_ca_detail): ?>
<?php
  $ca = $ca_param;
  $ca_sources_for = $by_ca[$ca];
  $cacfg = $ca_configs[$ca] ?? null;
  $ca_imported = array_sum(array_column($ca_sources_for, 'imported_count'));
  $ca_active   = count(array_filter($ca_sources_for, fn($s) => $s['active']));
  $ca_total    = count($ca_sources_for);
?>
<p class="govuk-!-margin-bottom-4">
  <a href="?type=ca" class="govuk-back-link">Back to all combined authorities</a>
</p>

<div style="border:1px solid #b1b4b6;margin-bottom:8px;border-radius:3px">
  <div style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#f3f2f1">
    <h2 class="govuk-heading-m govuk-!-margin-bottom-0"><?= htmlspecialchars($ca) ?></h2>
    <?php if (!empty($cacfg['cloudflare_blocked'])): ?>
    <span class="badge badge--cf" title="Source site is Cloudflare-blocked — re-imports/backfills need a wayback-archive workaround">☁ Cloudflare-blocked</span>
    <?php endif; ?>
    <div style="display:flex;align-items:center;gap:12px">
      <span class="govuk-body-s" style="color:#505a5f">
        <?= $ca_active ?>/<?= $ca_total ?> active
        <?php if ($ca_imported > 0): ?>&nbsp;· <?= number_format($ca_imported) ?> payments imported<?php endif; ?>
      </span>
      <?php if ($is_admin && $ca_imported > 0): ?>
      <form method="post" action="/contracts/sources.php?type=ca&ca=<?= urlencode($ca) ?>" style="display:inline;margin:0"
            onsubmit="return confirm('Clear all imported data for <?= htmlspecialchars($ca, ENT_QUOTES) ?>? This cannot be undone.')">
        <input type="hidden" name="action" value="ca_clear">
        <input type="hidden" name="ca_name" value="<?= htmlspecialchars($ca) ?>">
        <button type="submit" class="govuk-button govuk-!-margin-bottom-0"
                style="font-size:0.75rem;padding:2px 8px;background:#d4351c;border-color:#d4351c"
                onmouseover="this.style.background='#aa2a15'"
                onmouseout="this.style.background='#d4351c'">Clear data</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div style="padding:16px">
  <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:8px">
    <span></span>
    <?php if ($is_admin): ?>
    <a href="?type=ca&ca=<?= urlencode($ca) ?>&edit_ca=<?= urlencode($ca) ?>#config-<?= urlencode($ca) ?>" class="govuk-link" style="font-size:0.875rem">
      <?= $cacfg ? 'Edit column config' : 'Set column config' ?>
    </a>
    <?php endif; ?>
  </div>

  <?php if ($cacfg): ?>
  <div style="background:#e8f1fb;padding:8px 12px;margin-bottom:8px;font-size:0.8rem;color:#1d70b8;border-radius:3px">
    Type: <?= htmlspecialchars($cacfg['ca_type'] ?: '—') ?> ·
    Region: <?= htmlspecialchars($cacfg['region'] ?: '—') ?> ·
    Encoding: <?= htmlspecialchars($cacfg['encoding']) ?> ·
    Supplier col: <?= $cacfg['col_supplier'] ?> ·
    Amount col: <?= $cacfg['col_amount'] ?> ·
    Date col: <?= $cacfg['col_date'] ?> ·
    Service col: <?= $cacfg['col_service'] ?> ·
    Skip rows: <?= $cacfg['skip_rows'] ?><?php if ((int)($cacfg['col_category']??-1) >= 0): ?> · Category col: <?= $cacfg['col_category'] ?> filter: <em><?= htmlspecialchars($cacfg['category_filter']??'') ?></em><?php endif; ?>
    <?php if ($cacfg['notes']): ?> · <em><?= htmlspecialchars($cacfg['notes']) ?></em><?php endif; ?>
  </div>
  <?php else: ?>
  <div style="background:#fff3cd;padding:8px 12px;margin-bottom:8px;font-size:0.8rem;border-radius:3px">
    ⚠ No column config set for this strategic authority — import will use defaults. <a href="?type=ca&ca=<?= urlencode($ca) ?>&edit_ca=<?= urlencode($ca) ?>#config-<?= urlencode($ca) ?>">Set config →</a>
  </div>
  <?php endif; ?>

  <!-- Column config edit form -->
  <?php if ($edit_ca === $ca): ?>
  <div id="config-<?= urlencode($ca) ?>" style="background:#e8f1fb;padding:16px;margin-bottom:12px;border:1px solid #1d70b8">
    <h3 class="govuk-heading-s">Column config for <?= htmlspecialchars($ca) ?></h3>
    <form method="post" action="/contracts/sources.php?type=ca&ca=<?= urlencode($ca) ?>">
      <input type="hidden" name="action" value="ca_save_config">
      <input type="hidden" name="ca_name" value="<?= htmlspecialchars($ca) ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem">SA type</label>
          <input class="govuk-input" type="text" name="ca_type" value="<?= htmlspecialchars($cacfg['ca_type']??'') ?>" placeholder="e.g. Mayoral Strategic Authority">
        </div>
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem">Region</label>
          <input class="govuk-input" type="text" name="region" value="<?= htmlspecialchars($cacfg['region']??'') ?>">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem">Encoding</label>
          <select class="govuk-select" name="encoding" style="width:100%">
            <?php foreach (['utf8','utf16le','utf16be','win1252'] as $enc): ?>
              <option value="<?= $enc ?>"<?= ($cacfg['encoding']??'utf8')===$enc?' selected':'' ?>><?= $enc ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem">Skip header rows</label>
          <input class="govuk-input" type="number" name="skip_rows" value="<?= (int)($cacfg['skip_rows']??1) ?>" min="0" max="10">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:12px">
        <?php foreach ([
          ['col_supplier','Supplier col',$cacfg['col_supplier']??0],
          ['col_amount',  'Amount col',  $cacfg['col_amount']??1],
          ['col_date',    'Date col',    $cacfg['col_date']??2],
          ['col_service', 'Service col (-1=none)', $cacfg['col_service']??-1],
        ] as [$fname,$flabel,$fval]): ?>
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem"><?= $flabel ?></label>
          <input class="govuk-input" type="number" name="<?= $fname ?>" value="<?= (int)$fval ?>">
        </div>
        <?php endforeach; ?>
      </div>
      <div style="display:grid;grid-template-columns:1fr 3fr;gap:12px;margin-bottom:12px">
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem">Category col (-1=no filter)</label>
          <input class="govuk-input" type="number" name="col_category" value="<?= (int)($cacfg['col_category']??-1) ?>">
        </div>
        <div class="govuk-form-group govuk-!-margin-bottom-0">
          <label class="govuk-label" style="font-size:0.875rem">Category filter values (comma-separated, case-insensitive contains match)</label>
          <input class="govuk-input" type="text" name="category_filter" value="<?= htmlspecialchars($cacfg['category_filter']??'') ?>" placeholder="e.g. IT,Information Technology,Digital">
        </div>
      </div>
      <div class="govuk-form-group govuk-!-margin-bottom-3">
        <label class="govuk-label" style="font-size:0.875rem">Listing URL</label>
        <input class="govuk-input" type="url" name="listing_url" value="<?= htmlspecialchars($cacfg['listing_url']??'') ?>">
      </div>
      <div class="govuk-form-group govuk-!-margin-bottom-3">
        <label class="govuk-label" style="font-size:0.875rem">Notes</label>
        <input class="govuk-input" type="text" name="notes" value="<?= htmlspecialchars($cacfg['notes']??'') ?>">
      </div>
      <div class="govuk-form-group govuk-!-margin-bottom-3">
        <label class="govuk-checkboxes__label" style="font-size:0.875rem">
          <input type="checkbox" name="cloudflare_blocked" value="1"<?= !empty($cacfg['cloudflare_blocked']) ? ' checked' : '' ?>>
          ☁ Source site is Cloudflare-blocked (re-imports need a wayback-archive workaround)
        </label>
      </div>
      <button class="govuk-button govuk-!-margin-bottom-0" type="submit">Save config</button>
      <a href="?type=ca&ca=<?= urlencode($ca) ?>" class="govuk-link" style="margin-left:12px">Cancel</a>
    </form>
  </div>
  <?php endif; ?>

  <!-- Sources table -->
  <?php if ($is_admin): ?>
  <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;font-size:0.875rem">
    <button type="button"
            class="govuk-button govuk-button--warning govuk-!-margin-bottom-0"
            style="font-size:0.8rem;padding:4px 10px"
            onclick="caBulkToggle('<?= htmlspecialchars($ca, ENT_QUOTES) ?>', 'disable')">Disable selected</button>
    <button type="button"
            class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0"
            style="font-size:0.8rem;padding:4px 10px"
            onclick="caBulkToggle('<?= htmlspecialchars($ca, ENT_QUOTES) ?>', 'enable')">Enable selected</button>
    <a href="javascript:void(0)" onclick="caSelectAll('<?= htmlspecialchars($ca, ENT_QUOTES) ?>')" class="govuk-link" style="margin-left:4px">Select all</a>
    <a href="javascript:void(0)" onclick="caSelectNone('<?= htmlspecialchars($ca, ENT_QUOTES) ?>')" class="govuk-link">None</a>
  </div>
  <?php endif; ?>
  <table class="govuk-table" style="font-size:0.875rem;margin-bottom:0">
    <thead class="govuk-table__head">
      <tr class="govuk-table__row">
        <?php if ($is_admin): ?><th class="govuk-table__header" style="width:32px"></th><?php endif; ?>
        <th class="govuk-table__header">Period</th>
        <th class="govuk-table__header">URL</th>
        <th class="govuk-table__header">Format</th>
        <th class="govuk-table__header govuk-table__header--numeric">Imported</th>
        <th class="govuk-table__header">Last fetched</th>
        <th class="govuk-table__header">Status</th>
        <?php if ($is_admin): ?><th class="govuk-table__header">Actions</th><?php endif; ?>
      </tr>
    </thead>
    <tbody class="govuk-table__body">
      <?php foreach ($ca_sources_for as $s): ?>
      <tr class="govuk-table__row <?= $s['active']?'':'source-row-inactive' ?>">
        <?php if ($is_admin): ?>
        <td class="govuk-table__cell">
          <input type="checkbox" name="ids[]" value="<?= $s['id'] ?>" data-ca="<?= htmlspecialchars($ca) ?>">
        </td>
        <?php endif; ?>
        <td class="govuk-table__cell" style="white-space:nowrap"><strong><?= htmlspecialchars($s['period']) ?></strong></td>
        <td class="govuk-table__cell" style="max-width:300px">
          <a href="<?= htmlspecialchars($s['url']) ?>" class="govuk-link" target="_blank" style="font-size:0.8rem;word-break:break-all">
            <?= htmlspecialchars(mb_strimwidth($s['url'],0,60,'…')) ?>
          </a>
          <?php if ($s['notes']): ?><div style="font-size:0.75rem;color:#6f777b"><?= htmlspecialchars($s['notes']) ?></div><?php endif; ?>
        </td>
        <td class="govuk-table__cell"><?= strtoupper(htmlspecialchars($s['format'])) ?></td>
        <td class="govuk-table__cell govuk-table__cell--numeric"><?= $s['imported_count']>0?number_format($s['imported_count']):'—' ?></td>
        <td class="govuk-table__cell" style="white-space:nowrap"><?= $s['last_fetched']?htmlspecialchars(substr($s['last_fetched'],0,10)):'—' ?></td>
        <td class="govuk-table__cell">
          <span class="badge badge--<?= $s['active']?'active':'inactive' ?>"><?= $s['active']?'Active':'Inactive' ?></span>
        </td>
        <td class="govuk-table__cell" style="white-space:nowrap;font-size:0.875rem">
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="ca_toggle">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button style="border:none;background:none;cursor:pointer;font-size:0.875rem;padding:0;color:#1d70b8"><?= $s['active']?'Disable':'Enable' ?></button>
          </form>
          &nbsp;·&nbsp;<a href="?type=ca&ca=<?= urlencode($ca) ?>&ca_edit=<?= $s['id'] ?>" class="govuk-link">Edit</a>
          &nbsp;·&nbsp;<a href="?type=ca&ca_clone=<?= $s['id'] ?>#add" class="govuk-link">Clone</a>
          &nbsp;·&nbsp;
          <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
            <input type="hidden" name="action" value="ca_delete">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button style="border:none;background:none;cursor:pointer;font-size:0.875rem;padding:0;color:#d4351c">Delete</button>
          </form>
        </td>
      </tr>
      <?php if ($ca_edit_id===(int)$s['id']): ?>
      <tr class="edit-row">
        <td colspan="7">
          <form method="post" action="/contracts/sources.php?type=ca&ca=<?= urlencode($ca) ?>">
            <input type="hidden" name="action" value="ca_edit_source">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
              <div class="govuk-form-group govuk-!-margin-bottom-0">
                <label class="govuk-label" style="font-size:0.875rem">Combined authority</label>
                <input class="govuk-input" type="text" name="ca_name" value="<?= htmlspecialchars($s['ca_name']) ?>" required>
              </div>
              <div class="govuk-form-group govuk-!-margin-bottom-0">
                <label class="govuk-label" style="font-size:0.875rem">Period (YYYY-MM)</label>
                <input class="govuk-input" type="text" name="period" value="<?= htmlspecialchars($s['period']) ?>" required>
              </div>
              <div class="govuk-form-group govuk-!-margin-bottom-0">
                <label class="govuk-label" style="font-size:0.875rem">Format</label>
                <select class="govuk-select" name="format" style="width:100%">
                  <option value="csv"<?= $s['format']==='csv'?' selected':'' ?>>CSV</option>
                  <option value="xlsx"<?= $s['format']==='xlsx'?' selected':'' ?>>XLSX</option>
                  <option value="pdf"<?= $s['format']==='pdf'?' selected':'' ?>>PDF (not importable)</option>
                </select>
              </div>
            </div>
            <div class="govuk-form-group govuk-!-margin-bottom-3">
              <label class="govuk-label" style="font-size:0.875rem">URL</label>
              <input class="govuk-input" type="url" name="url" value="<?= htmlspecialchars($s['url']) ?>" required>
            </div>
            <div class="govuk-form-group govuk-!-margin-bottom-3">
              <label class="govuk-label" style="font-size:0.875rem">Notes</label>
              <input class="govuk-input" type="text" name="notes" value="<?= htmlspecialchars($s['notes']??'') ?>">
            </div>
            <button class="govuk-button govuk-!-margin-bottom-0" type="submit">Save</button>
            <a href="?type=ca&ca=<?= urlencode($ca) ?>" class="govuk-link" style="margin-left:12px">Cancel</a>
          </form>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php else: ?>
<!-- Strategic authority list -->
<div class="govuk-form-group">
  <div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap">
    <div style="flex:1;min-width:240px">
      <label class="govuk-label govuk-!-margin-bottom-1" for="ca-filter">Filter by strategic authority</label>
      <input class="govuk-input" style="width:100%;max-width:340px" type="text" id="ca-filter"
             placeholder="Start typing a name…" autocomplete="off"
             oninput="filterCAs(this.value)">
    </div>
    <?php if ($ca_incomplete_only): ?>
      <a href="?type=ca" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0" style="font-size:0.875rem">
        ✓ Showing incomplete only (<?= $ca_incomplete_count ?>) — clear filter
      </a>
    <?php else: ?>
      <a href="?type=ca&ca_incomplete=1" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0" style="font-size:0.875rem">
        Show incomplete only (<?= $ca_incomplete_count ?> of <?= count($by_ca) ?>)
      </a>
    <?php endif; ?>
  </div>
  <p class="govuk-body-s govuk-!-margin-top-1 govuk-!-margin-bottom-0" id="ca-filter-count" style="color:#505a5f"></p>
  <p class="govuk-body-s govuk-!-margin-top-1 govuk-!-margin-bottom-0" style="color:#505a5f">"Complete" = every registered period is active (none disabled).</p>
</div>
<div id="ca-list" style="margin-top:16px">
<?php foreach ($ca_names as $ca): ?>
<?php
  $ca_sources_for = $by_ca[$ca];
  $cacfg = $ca_configs[$ca] ?? null;
  $ca_imported = array_sum(array_column($ca_sources_for, 'imported_count'));
  $ca_active   = count(array_filter($ca_sources_for, fn($s) => $s['active']));
  $ca_total    = count($ca_sources_for);
  $cacomp      = $ca_completeness[$ca];
?>
<div class="ct-council-row" data-ca-name="<?= htmlspecialchars(strtolower($ca), ENT_QUOTES) ?>" style="border:1px solid #b1b4b6;margin-bottom:8px;border-radius:3px;padding:12px 16px;background:#f3f2f1">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div style="flex:1;min-width:220px">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <strong class="govuk-body govuk-!-margin-bottom-0"><?= htmlspecialchars($ca) ?></strong>
        <?php if (!empty($cacfg['cloudflare_blocked'])): ?>
        <span class="badge badge--cf" title="Source site is Cloudflare-blocked — re-imports/backfills need a wayback-archive workaround">☁ Cloudflare-blocked</span>
        <?php endif; ?>
        <?php if (!$cacomp['complete']): ?>
        <span class="badge" style="background:#fff3cd;color:#594d00" title="Disabled periods: <?= htmlspecialchars(implode(', ', $cacomp['missing']), ENT_QUOTES) ?>">⚠ <?= $cacomp['have'] ?>/<?= $cacomp['total'] ?> active</span>
        <?php endif; ?>
      </div>
      <div class="govuk-body-s" style="color:#505a5f;margin-top:4px">
        <?= $ca_active ?>/<?= $ca_total ?> active
        <?php if ($ca_imported > 0): ?>&nbsp;· <?= number_format($ca_imported) ?> payments imported<?php endif; ?>
        <?= $cacfg ? '' : ' · ⚠ not configured' ?>
      </div>
    </div>
    <a href="?type=ca&ca=<?= urlencode($ca) ?>" class="govuk-button govuk-button--secondary govuk-!-margin-bottom-0" style="flex-shrink:0">View sources →</a>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; // showing_ca_detail ?>

<?php endif; ?>

<?php if ($is_admin): ?>
<!-- Add / Clone form -->
<h2 class="govuk-heading-m govuk-!-margin-top-8" id="add">
  <?= $ca_clone_source ? 'Clone source — ' . htmlspecialchars($ca_clone_source['ca_name']) : 'Add a new strategic authority source' ?>
</h2>
<?php if ($ca_clone_source): ?>
<p class="govuk-body-s govuk-!-colour-secondary">
  Pre-filled from <?= htmlspecialchars($ca_clone_source['ca_name']) ?> <?= htmlspecialchars($ca_clone_source['period']) ?>.
  Update the period and URL for the new month, then save.
  <a href="/contracts/sources.php?type=ca#add" class="govuk-link">Start fresh instead</a>
</p>
<?php endif; ?>

<form method="post" action="/contracts/sources.php?type=ca#add" style="max-width:700px">
  <input type="hidden" name="action" value="ca_add_source">
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
    <div class="govuk-form-group">
      <label class="govuk-label" for="ca_name">Combined authority</label>
      <input class="govuk-input" type="text" id="ca_name" name="ca_name" required
             value="<?= $ca_clone_source ? htmlspecialchars($ca_clone_source['ca_name']) : '' ?>"
             list="ca-name-list" placeholder="e.g. West Midlands Strategic Authority">
      <datalist id="ca-name-list">
        <?php foreach (array_keys($by_ca) as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>">
        <?php endforeach; ?>
      </datalist>
    </div>
    <div class="govuk-form-group">
      <label class="govuk-label" for="ca_period">Period (YYYY-MM)</label>
      <input class="govuk-input" type="text" id="ca_period" name="period" required
             pattern="\d{4}-\d{2}" placeholder="2026-01">
    </div>
    <div class="govuk-form-group">
      <label class="govuk-label" for="ca_format">Format</label>
      <select class="govuk-select" id="ca_format" name="format" style="width:100%">
        <option value="csv"<?= ($ca_clone_source&&$ca_clone_source['format']==='csv')||!$ca_clone_source?' selected':'' ?>>CSV</option>
        <option value="xlsx"<?= $ca_clone_source&&$ca_clone_source['format']==='xlsx'?' selected':'' ?>>XLSX</option>
        <option value="pdf"<?= $ca_clone_source&&$ca_clone_source['format']==='pdf'?' selected':'' ?>>PDF (not importable)</option>
      </select>
    </div>
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="ca_url">URL</label>
    <input class="govuk-input" type="url" id="ca_url" name="url" required
           value="<?= $ca_clone_source ? htmlspecialchars($ca_clone_source['url']) : '' ?>">
  </div>
  <div class="govuk-form-group">
    <label class="govuk-label" for="ca_notes">Notes <span style="font-weight:400;color:#505a5f">(optional)</span></label>
    <input class="govuk-input" type="text" id="ca_notes" name="notes"
           value="<?= $ca_clone_source ? htmlspecialchars($ca_clone_source['notes']??'') : '' ?>">
  </div>
  <button class="govuk-button" type="submit"><?= $ca_clone_source ? 'Add cloned source' : 'Add source' ?></button>
</form>

<div class="govuk-inset-text govuk-!-margin-top-6">
  <p class="govuk-body-s govuk-!-margin-bottom-0">
    Column positions are configured per strategic authority, not per source.
    Click <strong>Edit column config</strong> next to a strategic authority's name to set or update them.
  </p>
</div>

<?php endif; // is_admin ?>

<?php endif; // $type ?>

<script>
function filterCouncils(query) {
  query = query.trim().toLowerCase();
  var items = document.querySelectorAll('#council-list > .ct-council-row');
  var shown = 0;
  items.forEach(function(el) {
    var match = !query || el.dataset.councilName.includes(query);
    el.style.display = match ? '' : 'none';
    if (match) shown++;
  });
  var count = document.getElementById('council-filter-count');
  count.textContent = query ? (shown + ' of ' + items.length + ' councils match') : '';
}
function selectAll(council) {
  document.querySelectorAll('[data-council="' + council + '"]').forEach(cb => cb.checked = true);
}
function selectNone(council) {
  document.querySelectorAll('[data-council="' + council + '"]').forEach(cb => cb.checked = false);
}
function importSelected(council, councilName) {
  const cbs = [...document.querySelectorAll('[data-council="' + council + '"]:checked')];
  if (!cbs.length) { alert('No sources selected.'); return; }
  const periods = cbs.map(cb => cb.dataset.period).filter(Boolean);
  if (!periods.length) {
    window.open('/contracts/import_transparency.php?council=' + encodeURIComponent(councilName) + '&force=1');
    return;
  }
  if (periods.length > 1 && !confirm('Open ' + periods.length + ' import tabs for ' + councilName + '?')) return;
  periods.forEach(p => window.open('/contracts/import_transparency.php?council=' + encodeURIComponent(councilName) + '&period=' + encodeURIComponent(p) + '&force=1'));
}
function bulkToggle(council, action) {
  const cbs = [...document.querySelectorAll('[data-council="' + council + '"]:checked')];
  if (!cbs.length) { alert('No sources selected.'); return; }
  if (action === 'disable' && !confirm('Disable ' + cbs.length + ' source(s)?')) return;
  const form = document.createElement('form');
  form.method = 'post';
  form.action = '/contracts/sources.php?council=' + encodeURIComponent(council);
  const add = (n, v) => { const i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); };
  add('action', 'bulk_toggle');
  add('bulk_action', action);
  cbs.forEach(cb => add('ids[]', cb.value));
  document.body.appendChild(form);
  form.submit();
}
function filterCAs(query) {
  query = query.trim().toLowerCase();
  var items = document.querySelectorAll('#ca-list > .ct-council-row');
  var shown = 0;
  items.forEach(function(el) {
    var match = !query || el.dataset.caName.includes(query);
    el.style.display = match ? '' : 'none';
    if (match) shown++;
  });
  var count = document.getElementById('ca-filter-count');
  if (count) count.textContent = query ? (shown + ' of ' + items.length + ' strategic authorities match') : '';
}
function caSelectAll(ca) {
  document.querySelectorAll('[data-ca="' + ca + '"]').forEach(cb => cb.checked = true);
}
function caSelectNone(ca) {
  document.querySelectorAll('[data-ca="' + ca + '"]').forEach(cb => cb.checked = false);
}
function caBulkToggle(ca, action) {
  const cbs = [...document.querySelectorAll('[data-ca="' + ca + '"]:checked')];
  if (!cbs.length) { alert('No sources selected.'); return; }
  if (action === 'disable' && !confirm('Disable ' + cbs.length + ' source(s)?')) return;
  const form = document.createElement('form');
  form.method = 'post';
  form.action = '/contracts/sources.php?type=ca&ca=' + encodeURIComponent(ca);
  const add = (n, v) => { const i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); };
  add('action', 'ca_bulk_toggle');
  add('bulk_action', action);
  cbs.forEach(cb => add('ids[]', cb.value));
  document.body.appendChild(form);
  form.submit();
}
</script>

<?php layout_foot(); ?>
