<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/app.php';

$inputFile = $root . '/private/rawpackets.txt';
$outputFile = $root . '/data/aprs_simulation.json';

if (!is_file($inputFile)) {
    fwrite(STDERR, "Missing input file: {$inputFile}\n");
    exit(1);
}

$config = app_config();
$fallbackStation = trim((string)($config['aprs_station'] ?? ''));
if ($fallbackStation === '') {
    $fallbackStation = 'SIM';
}

$raw = file_get_contents($inputFile);
if ($raw === false) {
    fwrite(STDERR, "Failed to read {$inputFile}.\n");
    exit(1);
}

$parsed = parse_aprs_raw_text($raw, $fallbackStation);
$records = (array)($parsed['records'] ?? []);

$simulationEntries = array_map(static function (array $record): array {
    return [
        'source' => 'simulated',
        'source_time_unix' => (int)($record['source_time_unix'] ?? 0),
        'unix_time' => (int)($record['unix_time'] ?? 0),
        'timestamp_utc' => (string)($record['timestamp_utc'] ?? ''),
        'altitude_m' => isset($record['altitude_m']) ? (float)$record['altitude_m'] : null,
        'station' => (string)($record['station'] ?? 'SIM'),
        'latitude' => isset($record['latitude']) && is_numeric($record['latitude']) ? (float)$record['latitude'] : null,
        'longitude' => isset($record['longitude']) && is_numeric($record['longitude']) ? (float)$record['longitude'] : null,
        'comment' => (string)($record['comment'] ?? ''),
        'voltage_v' => isset($record['voltage_v']) && is_numeric($record['voltage_v']) ? (float)$record['voltage_v'] : null,
        'temperature_c' => isset($record['temperature_c']) && is_numeric($record['temperature_c']) ? (float)$record['temperature_c'] : null,
        'pressure_pa' => isset($record['pressure_pa']) && is_numeric($record['pressure_pa']) ? (float)$record['pressure_pa'] : null,
    ];
}, $records);

write_json_file($outputFile, $simulationEntries);

fwrite(
    STDOUT,
    "Wrote " . count($simulationEntries) . " simulation rows to {$outputFile} " .
    "(" . (int)($parsed['malformed'] ?? 0) . " malformed, " . (int)($parsed['duplicates'] ?? 0) . " duplicates skipped).\n"
);
