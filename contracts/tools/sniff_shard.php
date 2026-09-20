<?php
/**
 * sniff_shard.php — Part 2a. READ-ONLY. Splits the Phase-1 work-queue TSV into
 * shard files, one per council-file-sniffer agent, and prints the dispatch table.
 *
 * Writes NOTHING to the DB. Only reads the queue TSV and writes shard TSVs +
 * touches an (empty, header-only) report file the agents append to.
 *
 * Usage:
 *   php ~/scripts/sniff_shard.php                                   # default queue + 18/shard
 *   php ~/scripts/sniff_shard.php --queue=/path.tsv --per=15
 *   php ~/scripts/sniff_shard.php --skip-dead                       # drop DEAD_SOURCE rows
 */

$argv_join = implode(' ', array_slice($argv, 1));
$queue = getenv('HOME') . '/sniffer_queue_' . date('Ymd') . '.tsv';
if (preg_match('/--queue=(\S+)/', $argv_join, $m)) $queue = $m[1];
$per = 18;
if (preg_match('/--per=(\d+)/', $argv_join, $m)) $per = max(1, (int)$m[1]);
$skip_dead = strpos($argv_join, '--skip-dead') !== false;

if (!is_readable($queue)) { fwrite(STDERR, "queue not readable: $queue\n"); exit(1); }

$lines = file($queue, FILE_IGNORE_NEW_LINES);
$header = array_shift($lines);
$rows = [];
foreach ($lines as $ln) {
    if ($ln === '') continue;
    $f = explode("\t", $ln);
    if ($skip_dead && ($f[8] ?? '') === 'DEAD_SOURCE') continue;
    $rows[] = $ln;
}

$stamp   = date('Ymd');
$dir     = getenv('HOME') . "/sniffer_shards_$stamp";
$report  = getenv('HOME') . "/sniffer_report_$stamp.tsv";
if (!is_dir($dir)) mkdir($dir, 0755, true);

// wipe any stale shards from a prior run of the same day
foreach (glob("$dir/shard_*.tsv") as $old) unlink($old);

$shards = array_chunk($rows, $per);
$paths = [];
foreach ($shards as $i => $chunk) {
    $p = sprintf("%s/shard_%02d.tsv", $dir, $i + 1);
    $fh = fopen($p, 'w');
    fwrite($fh, $header . "\n");
    foreach ($chunk as $ln) fwrite($fh, $ln . "\n");
    fclose($fh);
    $paths[] = [$p, count($chunk)];
}

// report file: header only, agents append classification rows
if (!file_exists($report)) {
    $fh = fopen($report, 'w');
    fwrite($fh, "council\tside\tlisting_url\theld\tverdict\tnew_period_or_note\tchecked_at\n");
    fclose($fh);
}

echo "SNIFF SHARD — queue=$queue  rows=" . count($rows) . "  per=$per  shards=" . count($paths) . ($skip_dead ? "  (dead dropped)\n" : "\n");
echo "report: $report\n";
echo "shard_dir: $dir\n\n";
foreach ($paths as [$p, $n]) echo str_pad(basename($p), 16) . "  $n councils  $p\n";
