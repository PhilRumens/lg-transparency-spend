<?php
/**
 * Admin CLI for API keys (invite-only — there is no self-serve endpoint).
 *
 *   php api_key_mint.php create "Owner Name" owner@example.com [per_min] [per_day] ["notes"]
 *   php api_key_mint.php list
 *   php api_key_mint.php revoke   <id>
 *   php api_key_mint.php activate <id>
 *
 * On create the full key is printed ONCE and never stored — only its SHA-256
 * hash + a 12-char prefix are saved. Copy it to the consumer securely.
 */
require_once __DIR__ . '/../config.php';

$cmd = $argv[1] ?? '';
$pdo = db();

switch ($cmd) {
    case 'create':
        $owner = $argv[2] ?? '';
        $email = $argv[3] ?? null;
        if ($owner === '') { fwrite(STDERR, "usage: create \"Owner\" email [per_min] [per_day] [\"notes\"]\n"); exit(1); }
        $perMin = isset($argv[4]) ? (int) $argv[4] : 60;
        $perDay = isset($argv[5]) ? (int) $argv[5] : 20000;
        $notes  = $argv[6] ?? null;

        $key    = 'lgc_live_' . bin2hex(random_bytes(16)); // 9 + 32 chars
        $prefix = substr($key, 0, 12);
        $hash   = hash('sha256', $key);

        $st = $pdo->prepare(
            'INSERT INTO ct_api_keys (key_prefix, key_hash, owner_name, owner_email,
                                      rate_per_min, rate_per_day, active, created_at, notes)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), ?)'
        );
        $st->execute([$prefix, $hash, $owner, $email, $perMin, $perDay, $notes]);
        $id = $pdo->lastInsertId();

        echo "Created key #$id for $owner\n";
        echo "  rate: $perMin/min, $perDay/day\n";
        echo "  ---- FULL KEY (shown once, store it now) ----\n";
        echo "  $key\n";
        echo "  ---------------------------------------------\n";
        break;

    case 'list':
        $rows = $pdo->query(
            'SELECT id, key_prefix, owner_name, owner_email, active,
                    rate_per_min, rate_per_day, created_at, last_used_at
               FROM ct_api_keys ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { echo "(no keys)\n"; break; }
        printf("%-3s %-12s %-24s %-6s %-9s %-19s\n", 'id', 'prefix', 'owner', 'active', 'per_min', 'last_used');
        foreach ($rows as $r) {
            printf("%-3d %-12s %-24s %-6s %-9d %-19s\n",
                $r['id'], $r['key_prefix'], substr($r['owner_name'], 0, 24),
                $r['active'] ? 'yes' : 'NO', $r['rate_per_min'], $r['last_used_at'] ?? '-');
        }
        break;

    case 'revoke':
    case 'activate':
        $id = (int) ($argv[2] ?? 0);
        if ($id < 1) { fwrite(STDERR, "usage: $cmd <id>\n"); exit(1); }
        $active = $cmd === 'activate' ? 1 : 0;
        $st = $pdo->prepare('UPDATE ct_api_keys SET active = ? WHERE id = ?');
        $st->execute([$active, $id]);
        echo ($st->rowCount() ? "key #$id set active=$active\n" : "no such key #$id\n");
        break;

    default:
        fwrite(STDERR, "commands: create | list | revoke <id> | activate <id>\n");
        exit(1);
}
