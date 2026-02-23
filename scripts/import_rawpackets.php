<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$inputFile = $root . '/rawpackets.txt';
$recordsFile = $root . '/data/records.json';
require_once $root . '/lib/app.php';
$station = app_config()['aprs_station'] !== '' ? app_config()['aprs_station'] : 'unknown';

function rawpackets_aprs_coord_to_decimal(string $value, string $hemisphere): ?float
{
    if (!preg_match('/^(\d{2,3})(\d{2}\.\d{2})$/', $value, $m)) {
        return null;
    }

    $deg = (float)$m[1];
    $minutes = (float)$m[2];
    $decimal = $deg + ($minutes / 60.0);

    if ($hemisphere === 'S' || $hemisphere === 'W') {
        $decimal *= -1;
    }

    return round($decimal, 6);
}

function rawpackets_parse_aprs_lat_lon(string $line): array
{
    if (!preg_match('/!([0-9]{4}\.[0-9]{2})([NS])\/([0-9]{5}\.[0-9]{2})([EW])/', $line, $m)) {
        return [null, null];
    }

    $lat = rawpackets_aprs_coord_to_decimal($m[1], $m[2]);
    $lon = rawpackets_aprs_coord_to_decimal($m[3], $m[4]);

    return [$lat, $lon];
}

if (!file_exists($inputFile)) {
    fwrite(STDERR, "Missing input file: {$inputFile}\n");
    exit(1);
}

if (!file_exists($recordsFile)) {
    fwrite(STDERR, "Missing records file: {$recordsFile}\n");
    exit(1);
}

$recordsRaw = file_get_contents($recordsFile);
if ($recordsRaw === false) {
    fwrite(STDERR, "Failed to read records file.\n");
    exit(1);
}

$records = json_decode($recordsRaw, true);
if (!is_array($records)) {
    fwrite(STDERR, "Invalid JSON in records file.\n");
    exit(1);
}

$existingIndex = [];
foreach ($records as $idx => $r) {
    $source = (string)($r['source'] ?? '');
    $t = (int)($r['source_time_unix'] ?? 0);
    if ($source !== '' && $t > 0) {
        $existingIndex[$source . ':' . $t] = $idx;
    }
}

$lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    fwrite(STDERR, "Failed to read raw packets.\n");
    exit(1);
}

$tz = new DateTimeZone('America/Chicago');
$added = 0;
$skipped = 0;
$malformed = 0;
$enriched = 0;

foreach ($lines as $line) {
    if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) CST:\s+/', $line, $mTs)) {
        $malformed++;
        continue;
    }

    if (!preg_match('/\/A=(\d{6})/', $line, $mAlt)) {
        $malformed++;
        continue;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mTs[1], $tz);
    if (!$dt) {
        $malformed++;
        continue;
    }

    $unix = $dt->getTimestamp();
    [$lat, $lon] = rawpackets_parse_aprs_lat_lon($line);

    $key = 'rawpacket:' . $unix;
    if (isset($existingIndex[$key])) {
        $idx = $existingIndex[$key];
        $updated = false;

        if (!array_key_exists('latitude', $records[$idx]) && $lat !== null) {
            $records[$idx]['latitude'] = $lat;
            $updated = true;
        }

        if (!array_key_exists('longitude', $records[$idx]) && $lon !== null) {
            $records[$idx]['longitude'] = $lon;
            $updated = true;
        }

        if (($records[$idx]['station'] ?? '') === '' || ($records[$idx]['station'] ?? null) === null) {
            $records[$idx]['station'] = $station;
            $updated = true;
        }

        if ($updated) {
            $enriched++;
        }

        $skipped++;
        continue;
    }

    $altFeet = (int)$mAlt[1];
    $altMeters = round($altFeet * 0.3048, 4);

    $records[] = [
        'source' => 'rawpacket',
        'source_time_unix' => $unix,
        'unix_time' => $unix,
        'timestamp_utc' => gmdate('Y-m-d H:i:s', $unix) . ' UTC',
        'altitude_m' => $altMeters,
        'station' => $station,
        'latitude' => $lat,
        'longitude' => $lon,
    ];

    $existingIndex[$key] = count($records) - 1;
    $added++;
}

usort($records, static fn(array $a, array $b): int => ((int)($a['unix_time'] ?? 0)) <=> ((int)($b['unix_time'] ?? 0)));

$out = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($out === false) {
    fwrite(STDERR, "Failed to encode updated JSON.\n");
    exit(1);
}

if (file_put_contents($recordsFile, $out . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Failed to write updated records file.\n");
    exit(1);
}

fwrite(STDOUT, "Added {$added} records, skipped {$skipped}, malformed {$malformed}, enriched {$enriched}.\n");
