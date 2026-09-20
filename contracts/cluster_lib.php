<?php
/**
 * cluster_lib.php
 * Contract expiry-cluster engine. Finds groups of geographically-near councils
 * running the SAME LGAM product with contracts expiring within a time window.
 *
 * Pure logic — no output, no login. Included by expiry_clusters.php (page) and
 * cluster_cli.php (test harness). Reads: ct_contract_register_entries,
 * ct_lgam_entry_products, ct_lgam_products, ct_council_config, ct_la_geo.
 */
declare(strict_types=1);

/** Haversine distance in km between two lat/lon points. */
function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Find expiry clusters.
 *
 * @param PDO   $pdo
 * @param array $opts radius_km, window_months, min_councils, product_id (optional filter), nation (optional 'E'/'S'/'W'/'N')
 * @return array ranked clusters (highest score first)
 */
function find_expiry_clusters(PDO $pdo, array $opts = []): array {
    $radius_km      = (float)($opts['radius_km']     ?? 40);
    $window_months  = (int)  ($opts['window_months'] ?? 9);
    $min_councils   = max(2, (int)($opts['min_councils'] ?? 3));
    $product_filter = $opts['product_id'] ?? null;
    $nation         = $opts['nation'] ?? null;
    // How far ahead to look: only include contracts expiring within this many
    // months from today. null/0 = no horizon (any future expiry). Distinct from
    // window_months, which bounds how tightly a cluster's expiries co-terminate.
    $horizon_months = isset($opts['horizon_months']) ? (int)$opts['horizon_months'] : null;
    // Grouping mode: 'proximity' (councils near each other by distance) or 'sa'
    // (councils in the same Strategic Authority). In 'sa' mode the radius is
    // ignored and councils with no Strategic Authority are excluded.
    $group_by = ($opts['group_by'] ?? 'proximity') === 'sa' ? 'sa' : 'proximity';

    // Placeholder combined_authority values meaning "no Strategic Authority".
    $NO_SA = ['No current strategic authority', 'No current combined authority', ''];
    $is_real_sa = fn($sa) => !in_array((string)$sa, $NO_SA, true);

    // ── National ubiquity: how many distinct councils hold each product
    //    (across ALL tech contracts, not just future) — drives the weight. ──
    $ubiq = [];
    foreach ($pdo->query("
        SELECT ep.product_id, COUNT(DISTINCT e.council) cc
        FROM ct_lgam_entry_products ep
        JOIN ct_contract_register_entries e ON e.id = ep.entry_id
        WHERE e.is_tech = 1
        GROUP BY ep.product_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ubiq[(int)$r['product_id']] = (int)$r['cc'];
    }

    // ── Candidate contracts: tech, future expiry, has product + coords ──
    $sql = "
        SELECT e.id AS entry_id, e.council, cc.lad_code, e.contract_title,
               e.value_amount, e.end_date, e.supplier_canon, cc.combined_authority,
               ep.product_id, p.display_name AS product_name, p.supplier_canon AS product_supplier,
               p.vendor AS product_vendor, p.description AS product_desc,
               g.lat, g.lon
        FROM ct_contract_register_entries e
        JOIN ct_council_config cc ON cc.council = e.council
        JOIN ct_lgam_entry_products ep ON ep.entry_id = e.id
        JOIN ct_lgam_products p ON p.id = ep.product_id
        JOIN ct_la_geo g ON g.lad_code = cc.lad_code
        WHERE e.is_tech = 1
          AND e.end_date IS NOT NULL
          AND e.end_date >= CURDATE()";
    $params = [];
    if ($horizon_months !== null && $horizon_months > 0) {
        $sql .= " AND e.end_date <= DATE_ADD(CURDATE(), INTERVAL ? MONTH)";
        $params[] = $horizon_months;
    }
    if ($group_by === 'sa') {
        // Only councils that belong to a real Strategic Authority can group by it.
        $sql .= " AND cc.combined_authority NOT IN (?, ?, ?)";
        array_push($params, $NO_SA[0], $NO_SA[1], $NO_SA[2]);
    }
    if ($product_filter !== null) { $sql .= " AND ep.product_id = ?"; $params[] = (int)$product_filter; }
    if ($nation !== null)         { $sql .= " AND LEFT(cc.lad_code,1) = ?"; $params[] = $nation; }
    $sql .= " ORDER BY ep.product_id, e.end_date";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Group candidates by product ──
    $by_product = [];
    foreach ($rows as $r) { $by_product[(int)$r['product_id']][] = $r; }

    $window_days = $window_months * 30.4;
    $clusters = [];

    // Two councils are "connected" (can share a cluster) if they're near each
    // other (proximity mode) or in the same Strategic Authority (sa mode).
    $connected = $group_by === 'sa'
        ? fn(array $a, array $b): bool => $a['combined_authority'] === $b['combined_authority']
        : fn(array $a, array $b): bool => haversine_km($a['lat'], $a['lon'], $b['lat'], $b['lon']) <= $radius_km;

    foreach ($by_product as $pid => $contracts) {
        // Collapse to one representative contract per council for this product:
        // keep the earliest future end_date + sum council's value on this product.
        $per_council = [];
        foreach ($contracts as $c) {
            $lad = $c['lad_code'];
            if (!isset($per_council[$lad])) {
                $per_council[$lad] = [
                    'lad_code' => $lad, 'council' => $c['council'],
                    'combined_authority' => $c['combined_authority'],
                    'lat' => (float)$c['lat'], 'lon' => (float)$c['lon'],
                    'end_ts' => strtotime($c['end_date']), 'end_date' => $c['end_date'],
                    'value' => (float)$c['value_amount'],
                    'product_name' => $c['product_name'] ?: $c['product_supplier'],
                    'product_supplier' => $c['product_supplier'] ?: ($c['product_vendor'] ?: ''),
                    'product_desc' => $c['product_desc'] ?: '',
                ];
            } else {
                $per_council[$lad]['value'] += (float)$c['value_amount'];
                if (strtotime($c['end_date']) < $per_council[$lad]['end_ts']) {
                    $per_council[$lad]['end_ts'] = strtotime($c['end_date']);
                    $per_council[$lad]['end_date'] = $c['end_date'];
                }
            }
        }
        $councils = array_values($per_council);
        if (count($councils) < $min_councils) continue;

        // Seed clustering with PAIRWISE proximity. For each seed, gather councils
        // within radius_km of the seed AND within window_days of the seed's end
        // date, THEN prune to a group where every member is within radius_km of
        // every other member (greedy: drop the member with the most out-of-range
        // partners until the group is fully pairwise-connected). This guarantees
        // "near each other" is literally true for all pairs, not just near a
        // common centre — otherwise a central seed can bridge two distant towns.
        $seen_signatures = [];
        foreach ($councils as $seed) {
            $cand_list = [];
            foreach ($councils as $cand) {
                $days_apart = abs($cand['end_ts'] - $seed['end_ts']) / 86400;
                if ($connected($seed, $cand) && $days_apart <= $window_days) {
                    $cand_list[] = $cand;
                }
            }
            if (count($cand_list) < $min_councils) continue;

            // Prune to a pairwise-within-radius group (approx max clique, greedy).
            $members = $cand_list;
            while (count($members) >= $min_councils) {
                $worst_i = -1; $worst_bad = 0;
                foreach ($members as $i => $mi) {
                    $bad = 0;
                    foreach ($members as $j => $mj) {
                        if ($i === $j) continue;
                        if (!$connected($mi, $mj)) $bad++;
                    }
                    if ($bad > $worst_bad) { $worst_bad = $bad; $worst_i = $i; }
                }
                if ($worst_bad === 0) break;               // fully pairwise-connected
                array_splice($members, $worst_i, 1);        // drop worst offender, retry
                $members = array_values($members);
            }
            if (count($members) < $min_councils) continue;

            // Dedupe: identify a cluster by its sorted member set.
            $sig = $pid . ':' . implode(',', array_map(fn($m) => $m['lad_code'], $members));
            sort($members);
            $sig_codes = array_map(fn($m) => $m['lad_code'], $members);
            sort($sig_codes);
            $sig = $pid . ':' . implode(',', $sig_codes);
            if (isset($seen_signatures[$sig])) continue;
            $seen_signatures[$sig] = true;

            // Metrics
            $ends = array_map(fn($m) => $m['end_ts'], $members);
            $span_days = (max($ends) - min($ends)) / 86400;
            $total_value = array_sum(array_map(fn($m) => $m['value'], $members));
            $ncouncils = count($members);

            $ubiq_count = $ubiq[$pid] ?? 1;
            $ubiq_weight = 1.0 / log(2.718281828 + $ubiq_count); // niche product -> higher
            $tightness = 1.0 / (1.0 + ($span_days / 30.4));        // narrow span -> higher (1.0 = same month)
            $value_factor = log(1.0 + max(0, $total_value));

            $score = $ncouncils * $tightness * $ubiq_weight * $value_factor;

            // Strategic Authority label: set when every member shares one real SA
            // (always true in 'sa' mode; opportunistic in proximity mode).
            $sa_set = array_unique(array_map(fn($m) => $m['combined_authority'], $members));
            $sa_name = (count($sa_set) === 1 && $is_real_sa($sa_set[array_key_first($sa_set)]))
                ? $sa_set[array_key_first($sa_set)] : '';

            $clusters[] = [
                'product_id'   => $pid,
                'product_name' => $members[0]['product_name'],
                'product_supplier' => $members[0]['product_supplier'] ?? '',
                'product_desc' => $members[0]['product_desc'] ?? '',
                'sa_name'      => $sa_name,
                'councils'     => $members,
                'n_councils'   => $ncouncils,
                'span_days'    => round($span_days),
                'earliest_end' => date('Y-m-d', min($ends)),
                'latest_end'   => date('Y-m-d', max($ends)),
                'total_value'  => $total_value,
                'ubiq_count'   => $ubiq_count,
                'ubiq_weight'  => round($ubiq_weight, 4),
                'tightness'    => round($tightness, 3),
                'score'        => round($score, 2),
            ];
        }
    }

    // Rank by score desc
    usort($clusters, fn($a, $b) => $b['score'] <=> $a['score']);

    // Merge overlapping clusters of the same product. Seed clustering produces
    // many near-identical variants of the same council group (subsets, or the
    // same set found from different seeds). Keep the highest-scoring; drop any
    // later cluster that either (a) is a subset of a kept one, or (b) shares a
    // high proportion (Jaccard >= 0.6) of councils with a kept one.
    $kept = [];
    foreach ($clusters as $cl) {
        $codes = array_map(fn($m) => $m['lad_code'], $cl['councils']);
        sort($codes);
        $set = array_flip($codes);
        $dup = false;
        foreach ($kept as $k) {
            if ($k['product_id'] !== $cl['product_id']) continue;
            $kcodes = array_map(fn($m) => $m['lad_code'], $k['councils']);
            $inter = count(array_intersect_key($set, array_flip($kcodes)));
            if ($inter === 0) continue;
            $union = count($set) + count($kcodes) - $inter;
            $jaccard = $inter / $union;
            // subset of a kept cluster, or heavily overlapping -> drop
            if ($inter === count($set) || $jaccard >= 0.6) { $dup = true; break; }
        }
        if (!$dup) $kept[] = $cl;
    }

    return $kept;
}
