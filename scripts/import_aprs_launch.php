<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/app.php';

function print_usage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php scripts/import_aprs_launch.php --name \"Launch Name\" [--input /path/to/paste.txt] [--station CALLSIGN]\n");
    fwrite(STDOUT, "  cat paste.txt | php scripts/import_aprs_launch.php --name \"Launch Name\"\n");
}

ensure_data_files();
$config = app_config();

$options = getopt('', ['name:', 'input::', 'station::', 'help']);
if ($options === false || isset($options['help']) || !isset($options['name'])) {
    print_usage();
    exit(isset($options['help']) ? 0 : 1);
}

$launchName = trim((string)$options['name']);
if ($launchName === '') {
    fwrite(STDERR, "Error: --name cannot be empty.\n");
    exit(1);
}

$defaultStation = trim((string)($config['aprs_station'] ?? ''));
$fallbackStation = trim((string)($options['station'] ?? $defaultStation));
if ($fallbackStation === '') {
    $fallbackStation = 'unknown';
}

$inputText = '';
if (isset($options['input'])) {
    $inputPath = trim((string)$options['input']);
    if ($inputPath === '' || !is_file($inputPath)) {
        fwrite(STDERR, "Error: input file not found: {$inputPath}\n");
        exit(1);
    }
    $raw = file_get_contents($inputPath);
    if ($raw === false) {
        fwrite(STDERR, "Error: failed to read input file.\n");
        exit(1);
    }
    $inputText = $raw;
} else {
    $stdin = stream_get_contents(STDIN);
    if ($stdin === false) {
        fwrite(STDERR, "Error: failed to read stdin.\n");
        exit(1);
    }
    $inputText = $stdin;
}

$inputText = trim($inputText);
if ($inputText === '') {
    fwrite(STDERR, "Error: no APRS text was provided.\n");
    exit(1);
}

$parsed = parse_aprs_raw_text($inputText, $fallbackStation);
if ((int)$parsed['parsed'] === 0) {
    fwrite(STDERR, "Error: no valid APRS rows were parsed (malformed rows: " . (int)$parsed['malformed'] . ").\n");
    exit(1);
}

$created = create_launch_from_records($launchName, $parsed['records']);

fwrite(STDOUT, "Created launch '{$created['id']}' with " . (int)$parsed['parsed'] . " records (" . (int)$parsed['malformed'] . " malformed rows, " . (int)$parsed['duplicates'] . " duplicate rows skipped).\n");
fwrite(STDOUT, "Saved: {$created['path']}\n");
