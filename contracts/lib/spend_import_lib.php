<?php
declare(strict_types=1);

// Shared spend-importer helpers (supplier matching + IO/parse), extracted VERBATIM from
// import_transparency.php on 2026-09-19 (Phase 1, project_importer_rationalisation).
// Currently required ONLY by import_transparency.php — NOT yet shared with the ca/register
// importers (their copies have diverged; see reference_importer_matcher_divergence). Behaviour
// frozen: exact canonical code relocated, verified zero-diff via the golden-master harness.

// ── Tech supplier patterns loaded from ct_supplier_patterns table ──────────────

// Some council source CSVs (seen on West of England Combined Authority's Jul-Sep
// 2024 file: "AtkinsR\xe9alis UK Limited") are saved in Windows-1252/Latin-1, not
// UTF-8 -- the CSV decoding only transcodes UTF-16 BOMs, so a single accented byte
// like \xe9 (e-acute) survives untouched into the supplier string. strtolower()
// only touches ASCII, so the byte stays as-is and "atkinsrealis" never matches
// "atkinsr\xe9alis" -- silently losing that supplier's spend instead of erroring.
// Strip the common Latin-1/UTF-8 accented byte sequences down to their plain-ASCII
// letter before matching so this (and any future accented supplier name) matches
// regardless of source encoding. (Mirrors the same helper in
// import_ca_transparency.php -- keep both in sync.)
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
    foreach (['county council','borough council','city council','district council','metropolitan borough'] as $skip) {
        if (str_contains($lower, $skip)) return null;
    }
    // "Access UK Ltd T/A Adam" is Access UK Ltd's temporary-accommodation /
    // housing-placement managed service ("Access Adam") - a completely
    // different business line from The Access Group's software products
    // despite the shared corporate name. Exclude it so it doesn't inflate
    // "The Access Group" tech-spend totals (e.g. £122M/672 records at
    // Haringey, ~80% of the council's apparent tech spend, average
    // £181k/transaction - clearly temporary accommodation placements, not
    // software).
    if (str_contains($lower, 't/a adam') || str_contains($lower, 't/as adam')) return null;
    // "Atos Medical" (ostomy/voice-prosthesis devices) is an unrelated company
    // that collides with the Atos SE IT-services word-boundary match.
    if (str_contains($lower, 'atos medical')) return null;
    // "Atos Worldline Re Premier Inn" is a card-payment processor used to
    // book temporary-accommodation hotel stays (Kensington and Chelsea
    // records it under "TA & Housing Solutions"/"Temporary Accommodation &
    // Resettlement") -- housing placement costs, not IT services, despite
    // the "Atos" name collision (same pattern as "T/A Adam" above).
    if (str_contains($lower, 'atos wordline') || str_contains($lower, 'atos worldline')) return null;
    // "Teneo Financial Advisory Ltd/Limited" is the corporate restructuring /
    // insolvency-administration arm of Teneo (booked under "Administrator
    // Costs", "Directorate for Legal Services", "Care and Wellbeing" etc. at
    // Peterborough £1.0M, Scottish Govt £2.6M, Cornwall, Leeds, Hull) -- a
    // completely different business line from plain "Teneo Ltd"/"TENEO LIMITED"
    // which is a genuine IT/telecoms supplier (Wandsworth "Application
    // maintenance" £566k, Merton "IT-Telecomms", Wolverhampton "Digital and
    // IT", Bracknell). The 'teneo' word-boundary pattern matches both, so
    // exclude only the "Financial Advisory" variants (confirmed FP 2026-07-30).
    if (str_contains($lower, 'teneo financial advisory')) return null;
    // "Siemens Financial Services Ltd"/"Siemens Finance Ltd" (CH 00646166,
    // SIC 64910 financial leasing) is Siemens' asset-finance arm -- it leases
    // anything (vending machines, MFDs, vehicles, telecare kit) and the
    // council spend is booked under Fleet/Leasing/Corporate Budgets/Schools
    // DSG, NOT IT (~£15.4M across ~80 councils, e.g. Barnsley £5.37M "Corporate
    // Budgets"). A completely different business line from the genuine Siemens
    // tech companies sharing the brand -- Siemens plc, Siemens Mobility (Yunex
    // traffic), Siemens Industry Software, Siemens Healthcare -- which the bare
    // 'siemens' pattern (952) still matches. Same class as "Teneo Financial
    // Advisory" above (confirmed FP 2026-08-01). Matches 'financ' to catch
    // both "Financial" and "Finance Ltd"; no genuine Siemens tech name contains it.
    if (str_contains($lower, 'siemens financ')) return null;
    // "Social Care Network Solutions Ltd" / "Care Network Solutions Ltd" is a
    // learning-disabilities / residential / adult-wellbeing social-care provider
    // (Herefordshire "All Ages Commissioning" £2.2k, York £2.09M) that collides
    // with the bare word-boundary 'network solutions' pattern (1206 -> NETWORK
    // SOLUTIONS INC, a US domain registrar). Pattern 1206 fires before the
    // confirmed_invalid 'social care network solutions' pattern (1026) can, so
    // null the care-provider variants here (FP confirmed batch-3 audit 2026-07-31).
    if (str_contains($lower, 'social care network solutions')
        || str_contains($lower, 'care network solutions')) return null;
    // "Gamma Network Solutions Ltd/Limited" (UK telephony/comms/IT, part of
    // Gamma Communications) collides with the same bare word_boundary
    // 'network solutions' pattern (1206 -> NETWORK SOLUTIONS INC, a US
    // domain registrar), which fires before the specific substring pattern
    // 220 ('gamma network solutions' -> Gamma) can. Redirect to canonical
    // "Gamma" here so its spend aggregates onto the one Gamma supplier page
    // instead of being mis-split under the US registrar (~600 rows/~2.65M
    // nationally; canon-quality fix, spend is genuine tech either way -- 2026-08-02).
    if (str_contains($lower, 'gamma network')) return 'Gamma';
    // "Biogen (UK) Limited/Ltd" is a food/green-waste anaerobic-digestion
    // disposal firm (Essex £1.61M Waste Disposal, Bexley £1.05M Neighbourhoods,
    // Herts £1.01M Green Waste, + Ipswich/Leics/Epping), NOT tech. Collides with
    // the bare substring pattern 1540 ('iogen' -> Iogen). A service-keyword
    // exclusion would leak (Bexley/Ipswich/Leics label it Neighbourhoods/
    // Environmental Services, no 'waste' token), so null on the supplier brand
    // instead: nothing genuine-tech is named 'biogen' (Biogen Inc is US biotech
    // pharma, never a council IT supplier). Leaves genuine 'iogen Ltd' (Chesterfield
    // £6.6k) untouched -- it lacks the leading b (FP 2026-08-02).
    if (str_contains($lower, 'biogen')) return null;
    // "Tucker Tunstalls Ltd" / "Tucker and Tunstalls Limited" / "Tucker & Tunstalls Ltd"
    // is a Yorkshire roofing and building contractor (roofing services, capital
    // maintenance, school building works -- ~6.46M across 11 N England councils
    // incl. Leeds 2.88M, Sheffield 1.81M, North Lincs 0.69M, Bradford,
    // Calderdale, Hull, York, NELC, Barnsley, Rotherham). Collides with the bare
    // substring pattern 340 ('tunstall' -> Tunstall Healthcare). Guard on
    // 'tucker' + 'tunstall' co-occurrence: nothing genuine-tech is named
    // 'Tucker Tunstalls' (confirmed FP 2026-08-03).
    if (str_contains($lower, 'tucker') && str_contains($lower, 'tunstall')) return null;
    // "Tunstall Nursery School" / "Tunstall Nursery EB00000113" is a state
    // nursery school in Croydon (Schools Transfer Payment, Tuition Fees) and
    // Sutton/Haringey -- grant/funding payments, not tech (~1.07M at 3 councils).
    // Same 'tunstall' pattern collision (confirmed FP 2026-08-03).
    if (str_contains($lower, 'tunstall nursery')) return null;
    // "MHR Cleaning & Maintenance Services Ltd" (Brent) is a cleaning/facilities
    // contractor -- NOT MHR International UK, the HR/payroll software firm that
    // the bare pattern 568 ('mhr' -> MHR) matches genuinely at dozens of councils
    // (Leeds £2.92M, Trafford £2.28M, East Riding £1.68M, Rochdale bare "MHR"
    // £890k, Bracknell "MHR / Midland Software" etc). Bare 'mhr' cannot be
    // tightened without losing those genuine bare-"MHR" instances, so null the
    // lone cleaning-company collision here instead (confirmed FP 2026-08-16;
    // national spread shows only Brent carries an 'mhr cleaning' variant, and
    // "MHR Cleaning & Maintenance" is unambiguously a cleaner everywhere).
    if (str_contains($lower, 'mhr cleaning')) return null;
    // "DASH Information Systems Limited" (Chelmsford) is its own genuine IT
    // company; the substring pattern 811 ('ash information' -> Ash Information
    // Systems) matches inside "dASH information systems" and mis-attributes DASH's
    // spend onto Ash. Redirect to DASH's correct canon so the (genuinely tech)
    // spend aggregates on the right supplier page rather than inflating Ash
    // (canon-quality fix, value valid either way -- 2026-08-16).
    if (str_contains($lower, 'dash information')) return 'DASH Information Systems';
    // "Zoom" (bare pattern 661) is a collision magnet: genuine Zoom Video
    // Communications ("zoom.us", "Zoom Video Communications Inc", "zoom.com",
    // 13+ councils) shares the token with a raft of unrelated firms -- taxi/
    // private-hire and minibus operators (Leicester "A Zoom Ltd" £3.28M Taxi
    // Travel Expense; Blackburn "Zoom Private Hire & Minibuses Ltd" £407k),
    // car/van hire, estate lettings, property management, print/photographic.
    // Null the clearly non-tech variants ONLY; leave genuine video/telecoms
    // and bare "ZOOM" (Hertfordshire £648k IT/software/telephone) alone.
    // Guard fires only when a non-tech token co-occurs with zoom (FP 2026-08-01).
    if (str_contains($lower, "zoom")) {
        foreach (["private hire", "minibus", "zoom cars", "zoom vans",
                  "estate letting", "property management", "digital print",
                  "photographic", "a zoom ltd", "zoom taxi", "zoom display"] as $z) {
            if (str_contains($lower, $z)) return null;
        }
    }
    // "SCC" (bare pattern 523, canon "Specialist Computer Centres") is a
    // collision magnet: genuine SCC/Specialist Computer Centres is ~£150M
    // across 64 councils and self-identifies ("Specialist Computer Centres",
    // "SCC PLC", "SCC (COMPQ)"), but the bare 3-letter token also matches a
    // string of unrelated "SCC ..." bodies -- Surrey/county PENSION FUNDS,
    // an AIR COMPRESSOR firm (Barnsley), Salford City COLLEGE, a PRIMARY
    // SCHOOL, Sportscool coaching, and "SCC Agency t/a South Coast CARE"
    // (Brighton). Null the clearly non-tech "SCC ..." variants ONLY; leave all
    // genuine Specialist Computer Centres and bare "SCC"/"SCC PLC" alone.
    // Guard fires only when a non-tech token co-occurs with scc (FP 2026-08-01).
    if (str_contains($lower, "scc") && !str_contains($lower, "specialist computer")) {
        foreach (["pension", "county fund", "air compressor",
                  "college", "school", "counsell", "sportscool", "scc agency",
                  "south coast care", "highway",
                  // Surrey CC school budget entries (SCC [school name] = Surrey County Council)
                  "business rate",
                  // Surrey CC school budget entries (SCC [school name] = Surrey County Council)
                  "scc st ", "scc earlswood", "scc furzefield", "scc chobham",
                  "scc newdigate", "scc ewell", "scc chennestone", "scc wonersh",
                  "scc manorfield", "scc west byfleet", "scc reigate",
                  "scc st bartholomew", "scc st joseph", "scc st anne",
                  "scc st dunstan", "scc st cuthbert", "scc st martin",
                  "scc st peter", "scc st francis",
                  // Vail Williams LLP holding SCC (Surrey CC) client money
                  "vail williams",
                  // Newcastle internal ledger code 'SCC (COMPQ)' - service names are
                  // council directorates (Place, City Ops, etc.), not tech (FP 2026-08-03)
                  "scc (compq)",
                  // "SCC Chartered Accountants Ltd" is an accountancy firm, not
                  // Specialist Computer Centres (Fermanagh & Omagh £7.1k/3 rows;
                  // FP 2026-08-05)
                  "chartered accountant",
                  // Internal-ledger / non-tech "SCC ..." variants collide with the
                  // bare SCC token: Tandridge business rate (already above), Staffs
                  // "Sundry BACS/CHEQUE", Newcastle "SCC (COMPQ)" (already above),
                  // Surrey school budgets, pension/petty-cash/imprest/political-group
                  // ledger accounts, registrars, etc. Generalised set (2026-08-16 SCC sweep).
                  "sundry", "petty cash", "imprest", "holding account",
                  "banking and income", "registration serv", "registrar",
                  "van permit", "vehicle crossover", "historic environment",
                  "corporte", "corporate account", "frontline", "learning management",
                  "libraries", "publications", "appointee", "multi-agency", "hostel",
                  "client deposit", "conservative gr", "labour group",
                  "teaching centre", "active learning", "taxi", "cab company",
                  "scottish sensory", "sensory centre", "construction", "carpentry",
                  "salford",
                  // Surrey CC school budget lines: "SCC <name> Primary/Infant/Junior/
                  // Nursery/COE/Catholic/Academy ..." (often truncated "Sch"/"Scho").
                  "primary", "infant", "junior", "nursery", "catholic",
                  "coe", "academy"] as $s) {
            if (str_contains($lower, $s)) return null;
        }
    }
    // Capita Group spans many unrelated business lines beyond IT services -
    // pensions administration, property/facilities consultancy, recruitment,
    // workforce management, gas safety inspections and TV licensing collection
    // are not tech spend despite the shared "Capita" brand. Exclude these
    // specific subsidiaries so they don't inflate "Capita" tech-spend totals
    // (e.g. "Capita Pension Solutions Ltd" appearing as £910k/39 records of
    // "OTHER DEBTOR PUBLIC COR" at Barking and Dagenham).
    if (str_contains($lower, 'capita')) {
        foreach (['pension solutions', 'pensions solutions', 'property', 'real estate', 'p and i ltd', 'translation', 'hartshead', 'resourcing', 'workforce management', 'watchdog', 'capita gas', 'gas safe register', 'tv licen'] as $non_it) {
            if (str_contains($lower, $non_it)) return null;
        }
    }
    // "Sky Access UK Limited" (height/scaffold-free access equipment),
    // "Vector Rope Access UK Ltd" and other "[X] Rope Access" companies
    // (industrial rope-access surveying/maintenance), and "Deaf Access Uk"
    // (Leeds, a deaf accessibility/interpreting service under Libraries Arts
    // & Heritage) all collide with the 'access uk' substring match for The
    // Access Group software company -- in each case "Access UK"/"Access Uk"
    // is the 2nd+ word of an unrelated trade name, not the start of the
    // company name the way "Access UK Ltd"/"ACCESS UK LIMITED" itself is.
    // Exclude these so they don't inflate "The Access Group" tech-spend
    // totals (£104,802 + £40,643.73 + £2,940 + £2,840 across Tower Hamlets,
    // Cornwall, Herefordshire and Leeds).
    if (str_contains($lower, 'sky access') || str_contains($lower, 'rope access') || str_contains($lower, 'deaf access')) {
        return null;
    }
    // "Heywood" (Aquila Heywood, a Civica social-care software brand) is too
    // short/generic for substring matching - "heywood" collides with the
    // town of Heywood (Rochdale) and many unrelated suppliers/people named
    // Heywood (e.g. "HEYWOODS GRANGE" care home, "Heywood & Partners
    // Surveyors", "HEYWOOD WILLIAMS COMPONENTS", "CAR 2000 HEYWOOD LLP",
    // dozens of Heywood-based community groups at Rochdale). Require an
    // exact (whole-string) match against the known legal-name variants.
    if (in_array($lower, ['heywood ltd', 'heywood limited', 'aquila heywood ltd', 'aquila heywood limited', 'aquila heywood'], true)) {
        return 'Heywood';
    }

    static $substring = null, $word_boundary = null, $invalid_patterns = null, $service_invalid_kw = null;
    if ($substring === null) {
        global $pdo;
        $rows = $pdo->query("SELECT pattern, canonical_name, match_type, confirmed_invalid FROM ct_supplier_patterns ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $substring = $word_boundary = $invalid_patterns = [];
        foreach ($rows as $r) {
            if ($r['confirmed_invalid']) { $invalid_patterns[] = $r['pattern']; continue; }
            if ($r['match_type'] === 'word_boundary') $word_boundary[$r['pattern']] = $r['canonical_name'];
            else $substring[$r['pattern']] = $r['canonical_name'];
        }
        $kw_rows = $pdo->query("SELECT keyword FROM ct_service_keywords WHERE ruling='invalid' ORDER BY LENGTH(keyword) DESC")->fetchAll(PDO::FETCH_COLUMN);
        $service_invalid_kw = $kw_rows;
    }
    foreach ($word_boundary as $pattern => $canon) {
        if (preg_match('/\b' . $pattern . '\b/', $lower)) return $canon;
    }
    foreach ($substring as $pattern => $canon) {
        if (str_contains($lower, $pattern)) return $canon ?: null;
    }
    return null;
}

function is_confirmed_invalid_supplier(string $canon): bool {
    // Re-read the static from the same function scope via a second call trick:
    // simpler to keep a module-level cache here instead.
    static $cache = null;
    if ($cache === null) {
        global $pdo;
        $cache = $pdo->query("SELECT pattern FROM ct_supplier_patterns WHERE confirmed_invalid=1")->fetchAll(PDO::FETCH_COLUMN);
    }
    $lower = strtolower($canon);
    foreach ($cache as $pat) {
        if (str_contains($lower, strtolower($pat))) return true;
    }
    return false;
}


function is_supplier_service_excluded(string $canon, ?string $service, ?string $category = null): bool {
    static $cache = null;
    if ($cache === null) {
        global $pdo;
        $rows = $pdo->query("SELECT supplier_pattern, service_keyword FROM ct_supplier_service_exclusions")->fetchAll(PDO::FETCH_ASSOC);
        $cache = [];
        foreach ($rows as $r) {
            $cache[] = [strtolower($r['supplier_pattern']), strtolower($r['service_keyword'])];
        }
    }
    $cl = strtolower($canon);
    foreach ($cache as [$sup, $svc]) {
        if (!str_contains($cl, $sup)) continue;
        if ($service !== null && str_contains(strtolower($service), $svc)) return true;
        if ($category !== null && str_contains(strtolower($category), $svc)) return true;
    }
    return false;
}
function sanitise_str($s): ?string {
    if ($s === null) return null;
    $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\s]+/u', ' ', (string)$s);
    $str = ltrim(rtrim((string)$str));
    return $str === '' ? null : $str;
}

function fetch_url(string $url, bool $verify_peer = true): ?string {
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
            CURLOPT_SSL_VERIFYPEER => $verify_peer,
            // Enable curl's in-memory cookie engine so cookies set on one
            // redirect hop (e.g. SharePoint's FedAuth) are sent on the next.
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
    // XLSX — return as-is
    if (substr($raw, 0, 2) === 'PK') return $raw;
    // BIFF8 legacy binary XLS (OLE2 Compound Document) -- return as-is so
    // parse_xlsx() can detect the D0CF11E0 magic and route to parse_xls_biff8().
    // Must come BEFORE the mb_check_encoding/Windows-1252 conversion below,
    // which would otherwise corrupt the binary content (BIFF8 is not UTF-8).
    if (substr($raw, 0, 4) === "\xD0\xCF\x11\xE0") return $raw;
    // UTF-16 LE with BOM
    if (substr($raw, 0, 2) === "\xFF\xFE") {
        $result = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        unset($raw);
        return $result;
    }
    // UTF-16 BE with BOM
    if (substr($raw, 0, 2) === "\xFE\xFF") {
        $result = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        unset($raw);
        return $result;
    }
    // Auto-detect UTF-16 by null bytes
    if (substr_count(substr($raw, 0, 200), "\0") > 5) {
        $result = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        unset($raw);
        return $result;
    }
    // Strip UTF-8 BOM
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);

    // Some council source CSVs (seen on West of England Combined Authority's
    // Jul-Sep 2024 file, supplier "AtkinsR\xe9alis UK Limited") are saved in
    // Windows-1252/Latin-1 rather than UTF-8. A lone \xe9 byte is not valid
    // standalone UTF-8, so sanitise_str()'s preg_replace(..., '/u') -- which
    // requires valid UTF-8 input -- silently returns null for the *entire*
    // supplier string, and the row gets dropped as if the supplier cell were
    // empty (no error, no log, just a quietly missing row). Detect invalid
    // UTF-8 and transcode the whole file from Windows-1252 once up front so
    // every downstream consumer (sanitise_str, match_supplier, etc.) sees
    // well-formed UTF-8 regardless of source encoding. (Mirrors the same
    // check in import_ca_transparency.php -- keep both in sync.)
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        if ($converted !== false && $converted !== '') $raw = $converted;
    }
    return $raw;
}

function parse_csv(string $content): array {
    $content = str_replace("\0", '', $content);
    $content = str_replace(["\r\n", "\r"], ["\n", "\n"], $content);
    $rows = [];
    foreach (explode("\n", $content) as $line) {
        if (trim($line) === '') continue;
        $rows[] = str_getcsv($line);
    }
    return $rows;
}

// Streaming version: parse CSV line by line, calling $callback($row) for each
function parse_csv_streaming(string $content, callable $callback): int {
    $content = str_replace("\0", '', $content);
    $content = str_replace(["\r\n", "\r"], ["\n", "\n"], $content);
    $count = 0;
    foreach (explode("\n", $content) as $line) {
        if (trim($line) === '') continue;
        $callback(str_getcsv($line));
        $count++;
    }
    return $count;
}

// OpenDocument Spreadsheet (.ods) -- a handful of councils (e.g. North
// Tyneside from 2025-04) switched export tools mid-series to this format.
// Internally completely different from OOXML xlsx: one content.xml holding
// every sheet's <table:table-row>/<table:table-cell> elements, not
// per-sheet xl/worksheets/sheetN.xml -- parse_xlsx()'s ZipArchive lookup
// for that path always fails on these files (confirmed 2026-06-24, all 13
// North Tyneside ods periods silently returned 0 rows).
function parse_ods_rows(string $raw, int $sheet_num = 1): array {
    foreach ([sys_get_temp_dir(), '/tmp', __DIR__] as $dir) {
        $tmp = $dir . '/ods_' . uniqid() . '.ods';
        if (@file_put_contents($tmp, $raw) !== false) break;
        $tmp = null;
    }
    if (!$tmp) { out("  ODS: could not write temp file", 'err'); return []; }
    if (!class_exists('ZipArchive')) { @unlink($tmp); out("  ODS: ZipArchive not available", 'err'); return []; }

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); out("  ODS: ZipArchive failed to open", 'err'); return []; }
    $xml = $zip->getFromName('content.xml');
    $zip->close();
    @unlink($tmp);
    if ($xml === false) { out("  ODS: no content.xml in archive", 'err'); return []; }

    // Locate the Nth <table:table> element (1-based, default sheet 1).
    // Multi-sheet ODS files (e.g. Welsh Government annual ODS with one sheet
    // per month) carry all sheets in content.xml; the original single-table
    // strpos() path only ever read sheet 1. Walk to the correct table.
    $tableStart = false;
    $searchFrom = 0;
    for ($tIdx = 0; $tIdx < $sheet_num; $tIdx++) {
        $tableStart = strpos($xml, '<table:table ', $searchFrom);
        if ($tableStart === false) break;
        $searchFrom = $tableStart + 1;
    }
    if ($tableStart === false) return [];
    $tableEnd = strpos($xml, '</table:table>', $tableStart);
    if ($tableEnd === false) return [];
    $tableXml = substr($xml, $tableStart, $tableEnd - $tableStart);

    // Split on row boundaries with explode() rather than one big regex over
    // the whole (multi-MB) table -- a single .*? row regex applied via
    // preg_match_all over the full string hits PCRE's backtrack limit on
    // real council files (confirmed: preg_last_error()==2 on a 5MB
    // content.xml), silently returning zero rows.
    $rowChunks = explode('<table:table-row', $tableXml);
    array_shift($rowChunks);

    $rows = [];
    foreach ($rowChunks as $chunk) {
        $closeTag = strpos($chunk, '>');
        if ($closeTag === false) continue;
        $rowAttrs = substr($chunk, 0, $closeTag);
        if (substr($rowAttrs, -1) === '/') continue; // self-closing, no cells

        $rowEnd = strpos($chunk, '</table:table-row>');
        $rowInner = $rowEnd !== false ? substr($chunk, $closeTag + 1, $rowEnd - $closeTag - 1) : substr($chunk, $closeTag + 1);

        $cells = [];
        $cellChunks = explode('<table:table-cell', $rowInner);
        array_shift($cellChunks);
        foreach ($cellChunks as $cchunk) {
            $cclose = strpos($cchunk, '>');
            if ($cclose === false) continue;
            $cellAttrs = substr($cchunk, 0, $cclose);
            $cellSelfClosing = substr($cellAttrs, -1) === '/';
            $colRepeat = 1;
            if (preg_match('/table:number-columns-repeated="(\d+)"/', $cellAttrs, $cr)) {
                $colRepeat = (int)$cr[1];
            }
            $text = '';
            if (!$cellSelfClosing) {
                $cellEnd = strpos($cchunk, '</table:table-cell>');
                $cellInner = $cellEnd !== false ? substr($cchunk, $cclose + 1, $cellEnd - $cclose - 1) : '';
                if ($cellInner !== '' && preg_match_all('/<text:p\b[^>]*>(.*?)<\/text:p>/s', $cellInner, $tp)) {
                    $parts = array_map(function ($t) {
                        $t = preg_replace('/<[^>]+>/', '', $t);
                        return html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }, $tp[1]);
                    $text = implode(' ', $parts);
                }
            }
            if ($text === '' && $colRepeat > 50) break; // trailing blank padding (sheets declare up to 16384 cols)
            $repeatCapped = min($colRepeat, 50);
            for ($i = 0; $i < $repeatCapped; $i++) $cells[] = $text;
        }

        $hasContent = false;
        foreach ($cells as $c) { if ($c !== '') { $hasContent = true; break; } }
        if (!$hasContent) continue;

        // table:number-rows-repeated is NOT honoured here -- confirmed
        // empirically (North Tyneside April 2025: honouring it literally
        // inflated the month's total to £113.3M against a cross-checked
        // real baseline of £60.47M; other months/eras run £38-62M). The
        // exporting tool sets this attribute on real transaction rows that
        // are NOT actually duplicated -- a council-side export quirk, not
        // genuine repetition. Every content row is exactly one transaction.
        $rows[] = $cells;
    }

    return $rows;
}

// Mid Devon District Council publishes monthly spend data as a plain HTML
// table on its own site -- no CSV/XLSX export, no API, no search form.
// Registered as format='csv' purely as a schema-compatible placeholder
// (ct_spend_sources.format has no 'html' option). Tables are clean
// <tr><th|td>...</tr> markup with no rowspan/colspan/nested-table
// complexity, confirmed across both a "standard" live page and an
// anomalous Wayback-archived layout -- extracts into the same
// array-of-arrays shape parse_xlsx() produces (header row included, same
// skip_rows=1 convention) so it plugs into the existing $rows-based
// pipeline unchanged.
function parse_html_table(string $html): array {
    if (!preg_match('/<table[^>]*>(.*?)<\/table>/is', $html, $tm)) return [];
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tm[1], $row_matches);
    $rows = [];
    foreach ($row_matches[1] as $row_html) {
        preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $row_html, $cell_matches);
        if (empty($cell_matches[1])) continue;
        $rows[] = array_map(
            fn($c) => trim(html_entity_decode(preg_replace('/<[^>]+>/', '', $c), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            $cell_matches[1]
        );
    }
    return $rows;
}

function parse_xls_biff8(string $raw, int $sheet_num = 1): array {
    // Parse a legacy binary BIFF8 (.xls) file using Python's xlrd library
    // (xlrd 2.x supports .xls only -- safe to call exclusively for OLE2 files).
    // Writes the raw bytes to a temp file, then runs a Python one-liner that
    // reads all rows and prints them as JSON (one JSON array per line).
    // Used by South Norfolk District Council (all sources are BIFF8 .xls).
    foreach ([sys_get_temp_dir(), '/tmp', __DIR__] as $dir) {
        $tmp = $dir . '/xls_' . uniqid() . '.xls';
        if (@file_put_contents($tmp, $raw) !== false) break;
        $tmp = null;
    }
    if (!$tmp) { out("  XLS: could not write temp file", 'err'); return []; }

    $py = 'import xlrd, json, sys; wb=xlrd.open_workbook(sys.argv[1]); '
        . 'ws=wb.sheets()[' . ((int)$sheet_num - 1) . ']; '
        . '[print(json.dumps([str(ws.cell(r,c).value) for c in range(ws.ncols)])) for r in range(ws.nrows)]';
    $cmd = 'python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg($tmp) . ' 2>&1';
    $output = [];
    exec($cmd, $output, $rc);
    @unlink($tmp);

    if ($rc !== 0) {
        out("  XLS: python3/xlrd parse failed (rc={$rc}): " . implode(' ', array_slice($output, 0, 3)), 'err');
        return [];
    }

    $rows = [];
    foreach ($output as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) $rows[] = $decoded;
    }
    return $rows;
}

function parse_xlsx(string $raw, int $sheet_num = 1): array {
    // .ods and .xlsx are both zip-based ('PK' signature) but internally
    // incompatible -- sniff the ODF mimetype (the first, uncompressed zip
    // entry per the OpenDocument spec) before falling into xlsx parsing.
    if (str_contains(substr($raw, 0, 200), 'application/vnd.oasis.opendocument.spreadsheet')) {
        return parse_ods_rows($raw, $sheet_num);
    }
    // BIFF8 legacy binary XLS: magic bytes D0 CF 11 E0 (OLE2 Compound
    // Document). parse_xlsx()'s ZipArchive path cannot handle these -- route
    // through parse_xls_biff8() which uses Python xlrd (xlrd 2.x supports
    // .xls only, making this safe to call exclusively for BIFF8 signatures).
    // Used by South Norfolk District Council (all .xls sources 2022-2026).
    if (substr($raw, 0, 4) === "\xD0\xCF\x11\xE0") {
        return parse_xls_biff8($raw, $sheet_num);
    }
    foreach ([sys_get_temp_dir(), '/tmp', __DIR__] as $dir) {
        $tmp = $dir . '/xlsx_' . uniqid() . '.xlsx';
        if (@file_put_contents($tmp, $raw) !== false) break;
        $tmp = null;
    }
    if (!$tmp) { out("  XLSX: could not write temp file", 'err'); return []; }

    if (!class_exists('ZipArchive')) { @unlink($tmp); out("  XLSX: ZipArchive not available", 'err'); return []; }

    $zip = new ZipArchive();
    $code = $zip->open($tmp);
    if ($code !== true) {
        @unlink($tmp);
        $hex = bin2hex(substr($raw, 0, 20));
        $printable = preg_replace('/[^\x20-\x7E]/', '.', substr($raw, 0, 100));
        out("  XLSX: ZipArchive failed to open (code: {$code}, size: " . strlen($raw) . " bytes)", 'err');
        out("    Hex start: {$hex}", 'warn');
        out("    Printable: " . htmlspecialchars($printable), 'warn');
        return [];
    }

    // Get shared strings
    $shared = [];
    $ss_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss_xml) {
        $ss_xml = preg_replace('/<rPr>.*?<\/rPr>/s', '', $ss_xml);
        // Walk <si> entries individually (concatenating multi-run <t> text and
        // defaulting to '' for empty/self-closing <t/>) so an empty shared
        // string doesn't shift every subsequent index out of alignment - a
        // single <si><t/></si> entry would otherwise desync $shared from the
        // numeric indices referenced by <c t="s"><v>N</v></c> cells for the
        // rest of the sheet, scrambling columns like Vendor Name.
        preg_match_all('/<si>(.*?)<\/si>/s', $ss_xml, $si_m);
        foreach ($si_m[1] as $si) {
            preg_match_all('/<t(?:\s[^>]*)?>([^<]*)<\/t>/s', $si, $tm);
            $shared[] = implode('', $tm[1]);
        }
    }

    // Try the requested sheet, fall back to sheet2 (for Excelerator format)
    $sheet_xml = $zip->getFromName("xl/worksheets/sheet{$sheet_num}.xml");
    if ($sheet_num === 1 && $sheet_xml && str_contains($sheet_xml, 'Excelerator')) {
        $s2 = $zip->getFromName('xl/worksheets/sheet2.xml');
        if ($s2) $sheet_xml = $s2;
    }
    $zip->close();
    @unlink($tmp);
    if (!$sheet_xml) return [];

    $rows = [];
    preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet_xml, $row_matches);
    foreach ($row_matches[1] as $row_xml) {
        $cells = [];
        $row_xml = preg_replace("#<c [^>]*/>#", "", $row_xml);
        preg_match_all('/<c ([^>]+)>(.*?)<\/c>/s', $row_xml, $cell_matches, PREG_SET_ORDER);
        foreach ($cell_matches as $cm) {
            // Get column index from ref (e.g. "B3" → col 1)
            preg_match('/r="([A-Z]+)/', $cm[1], $ref_m);
            $col_ref = $ref_m[1] ?? '';
            $col_idx = 0;
            foreach (str_split($col_ref) as $ch) $col_idx = $col_idx * 26 + (ord($ch) - 64);
            $col_idx--;

            $type = '';
            preg_match('/t="([^"]+)"/', $cm[1], $tm);
            $type = $tm[1] ?? '';
            $val_match = [];
            preg_match('/<v>([^<]*)<\/v>/', $cm[2], $val_match);
            $val = $val_match[1] ?? '';
            if ($type === 's') $val = html_entity_decode($shared[(int)$val] ?? '', ENT_XML1, 'UTF-8');
            elseif ($type === 'inlineStr') {
                // inlineStr: value is in <is><t>...</t></is>, not in <v>
                preg_match('/<t>([^<]*)<\/t>/', $cm[2], $t_match);
                $val = html_entity_decode($t_match[1] ?? '', ENT_XML1, 'UTF-8');
            } elseif ($type === 'str') {
                // Formula-cached string: calculated result is in <v>, already extracted above
                $val = html_entity_decode($val, ENT_XML1, 'UTF-8');
            } else {
                $val = html_entity_decode($val, ENT_XML1, 'UTF-8');
            }
            // Pad array to column index
            while (count($cells) <= $col_idx) $cells[] = '';
            $cells[$col_idx] = $val;
        }
        $rows[] = $cells;
    }
    return $rows;
}
