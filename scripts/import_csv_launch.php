<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/app.php';

function print_usage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php scripts/import_csv_launch.php --file data/raw/2025-02-21.csv [--name \"Launch Name\"] [--station N0YD-11] [--tz UTC]\n");
}

ensure_data_files();
$config = app_config();

$options = getopt('', ['file:', 'name::', 'station::', 'tz::', 'help']);
if ($options === false || isset($options['help']) || !isset($options['file'])) {
    print_usage();
    exit(isset($options['help']) ? 0 : 1);
}

$file = trim((string)$options['file']);
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Error: file not found: {$file}\n");
    exit(1);
}

$tz = trim((string)($options['tz'] ?? 'UTC'));
if ($tz === '') {
    $tz = 'UTC';
}
try {
    new DateTimeZone($tz);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: invalid timezone '{$tz}'.\n");
    exit(1);
}

$launchName = trim((string)($options['name'] ?? ''));
if ($launchName === '') {
    $launchName = pathinfo($file, PATHINFO_FILENAME);
}

$station = trim((string)($options['station'] ?? ''));
if ($station === '') {
    $station = trim((string)($config['aprs_station'] ?? ''));
}
if ($station === '') {
    $station = 'unknown';
}

try {
    $parsed = import_csv_launch_file($file, $station, $tz);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

if (count($parsed['records']) === 0) {
    fwrite(STDERR, "Error: no valid rows found. malformed=" . (int)$parsed['malformed'] . ", duplicates=" . (int)$parsed['duplicates'] . "\n");
    exit(1);
}

$created = create_launch_from_records($launchName, $parsed['records']);

fwrite(STDOUT, "Created launch '{$created['id']}' with " . (int)$parsed['parsed'] . " records.\n");
fwrite(STDOUT, "Skipped malformed=" . (int)$parsed['malformed'] . ", duplicates=" . (int)$parsed['duplicates'] . ".\n");
fwrite(STDOUT, "Saved: {$created['path']}\n");
