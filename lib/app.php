<?php
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../data';
const RECORDS_FILE = DATA_DIR . '/records.json';
const STATE_FILE = DATA_DIR . '/state.json';
const CACHE_FILE = DATA_DIR . '/aprs_cache.json';
const CONFIG_FILE = DATA_DIR . '/config.json';
const LAUNCHES_DIR = DATA_DIR . '/launches';
const APRS_API_BASE = 'https://api.aprs.fi/api/get';
const APRS_MIN_FETCH_SECONDS = 60;
const APP_DEFAULT_USER_AGENT = 'wxballoon-liveplot/1.0 (+http://localhost/wxballoon-liveplot)';

function load_dotenv_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($value !== '' && (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function app_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    load_dotenv_file(__DIR__ . '/../.env');

    $station = trim((string)(getenv('APRS_STATION') ?: ''));
    $apiKey = trim((string)(getenv('APRS_API_KEY') ?: ''));
    $userAgent = trim((string)(getenv('APP_USER_AGENT') ?: APP_DEFAULT_USER_AGENT));
    $adminPassword = trim((string)(getenv('APP_ADMIN_PASSWORD') ?: ''));
    $storedConfig = get_config_overrides();

    if (array_key_exists('aprs_station', $storedConfig)) {
        $station = trim((string)$storedConfig['aprs_station']);
    }
    if (array_key_exists('aprs_api_key', $storedConfig)) {
        $apiKey = trim((string)$storedConfig['aprs_api_key']);
    }
    if (array_key_exists('app_user_agent', $storedConfig)) {
        $storedUserAgent = trim((string)$storedConfig['app_user_agent']);
        if ($storedUserAgent !== '') {
            $userAgent = $storedUserAgent;
        }
    }

    $config = [
        'aprs_station' => $station,
        'aprs_api_key' => $apiKey,
        'app_user_agent' => $userAgent,
        'app_admin_password' => $adminPassword,
    ];

    return $config;
}

function ensure_data_files(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }

    if (!file_exists(RECORDS_FILE)) {
        write_json_file(RECORDS_FILE, []);
    }

    if (!file_exists(STATE_FILE)) {
        write_json_file(STATE_FILE, [
            'capture_enabled' => false,
            'last_capture_attempt_unix' => null,
            'last_capture_success_unix' => null,
            'last_error' => null,
            'last_aprs_time_unix' => null,
        ]);
    }

    if (!file_exists(CACHE_FILE)) {
        write_json_file(CACHE_FILE, [
            'fetched_at_unix' => null,
            'payload' => null,
        ]);
    }

    if (!file_exists(CONFIG_FILE)) {
        write_json_file(CONFIG_FILE, []);
    }

    if (!is_dir(LAUNCHES_DIR)) {
        mkdir(LAUNCHES_DIR, 0775, true);
    }
}

function read_json_file(string $path, mixed $default): mixed
{
    if (!file_exists($path)) {
        return $default;
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $default;
    }

    $data = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : $default;
}

function write_json_file(string $path, mixed $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON.');
    }

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write temporary JSON file.');
    }

    if (!rename($tmp, $path)) {
        throw new RuntimeException('Failed to replace JSON file atomically.');
    }
}

function get_config_overrides(): array
{
    ensure_data_files();
    $config = read_json_file(CONFIG_FILE, []);
    if (!is_array($config)) {
        return [];
    }

    return $config;
}

function update_config_overrides(array $patch): array
{
    $config = array_merge(get_config_overrides(), $patch);
    write_json_file(CONFIG_FILE, $config);
    return $config;
}

function get_records(): array
{
    ensure_data_files();
    $records = read_json_file(RECORDS_FILE, []);
    if (!is_array($records)) {
        return [];
    }

    usort($records, static fn(array $a, array $b): int => ($a['unix_time'] ?? 0) <=> ($b['unix_time'] ?? 0));
    return $records;
}

function normalize_launch_id(string $launchId): ?string
{
    $id = strtolower(trim($launchId));
    if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) {
        return null;
    }

    return $id;
}

function get_launch_records(string $launchId): ?array
{
    ensure_data_files();
    $id = normalize_launch_id($launchId);
    if ($id === null) {
        return null;
    }

    $path = LAUNCHES_DIR . '/' . $id . '.json';
    if (!is_file($path)) {
        return null;
    }

    $records = read_json_file($path, []);
    if (!is_array($records)) {
        return [];
    }

    usort($records, static fn(array $a, array $b): int => ($a['unix_time'] ?? 0) <=> ($b['unix_time'] ?? 0));
    return $records;
}

function list_launches(): array
{
    ensure_data_files();
    $files = glob(LAUNCHES_DIR . '/*.json');
    if (!is_array($files)) {
        return [];
    }

    $launches = [];
    foreach ($files as $path) {
        if (!is_file($path)) {
            continue;
        }

        $id = basename($path, '.json');
        $normalized = normalize_launch_id($id);
        if ($normalized === null) {
            continue;
        }

        $records = read_json_file($path, []);
        $count = is_array($records) ? count($records) : 0;
        $launches[] = [
            'id' => $normalized,
            'label' => str_replace(['_', '-'], ' ', $normalized),
            'record_count' => $count,
        ];
    }

    usort($launches, static fn(array $a, array $b): int => strcmp((string)$b['id'], (string)$a['id']));
    return $launches;
}

function aprs_coord_to_decimal(string $value, string $hemisphere): ?float
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

function parse_aprs_lat_lon_from_raw_line(string $line): array
{
    if (!preg_match('/!([0-9]{4}\.[0-9]{2})([NS])\/([0-9]{5}\.[0-9]{2})([EW])/', $line, $m)) {
        return [null, null];
    }

    $lat = aprs_coord_to_decimal($m[1], $m[2]);
    $lon = aprs_coord_to_decimal($m[3], $m[4]);
    return [$lat, $lon];
}

function parse_aprs_timestamp_from_raw_line(string $line): ?int
{
    if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+([A-Z]{2,4}):\s+/', $line, $m)) {
        return null;
    }

    $tzAbbrev = strtoupper($m[2]);
    $timezoneByAbbrev = [
        'CST' => 'America/Chicago',
        'CDT' => 'America/Chicago',
        'UTC' => 'UTC',
        'GMT' => 'UTC',
    ];
    $tzName = $timezoneByAbbrev[$tzAbbrev] ?? 'America/Chicago';

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m[1], new DateTimeZone($tzName));
    if (!$dt) {
        return null;
    }

    return $dt->getTimestamp();
}

function parse_aprs_station_from_raw_line(string $line, string $fallbackStation): string
{
    if (preg_match('/:\s*([A-Za-z0-9-]+)>/', $line, $m)) {
        return strtoupper(trim($m[1]));
    }

    return $fallbackStation;
}

function parse_aprs_raw_text(string $inputText, string $fallbackStation): array
{
    $text = trim($inputText);
    if ($text === '') {
        return [
            'records' => [],
            'parsed' => 0,
            'malformed' => 0,
            'duplicates' => 0,
        ];
    }

    $lines = preg_split('/\r\n|\n|\r/', $text);
    if (!is_array($lines)) {
        return [
            'records' => [],
            'parsed' => 0,
            'malformed' => 0,
            'duplicates' => 0,
        ];
    }

    $records = [];
    $seenUnix = [];
    $parsed = 0;
    $malformed = 0;
    $duplicates = 0;

    foreach ($lines as $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '') {
            continue;
        }

        $unix = parse_aprs_timestamp_from_raw_line($trimmed);
        if ($unix === null) {
            $malformed++;
            continue;
        }

        if (!preg_match('/\/A=(\d{6})/', $trimmed, $mAlt)) {
            $malformed++;
            continue;
        }

        if (isset($seenUnix[$unix])) {
            $duplicates++;
            continue;
        }
        $seenUnix[$unix] = true;

        $altFeet = (int)$mAlt[1];
        $altMeters = round($altFeet * 0.3048, 4);
        [$lat, $lon] = parse_aprs_lat_lon_from_raw_line($trimmed);
        $station = parse_aprs_station_from_raw_line($trimmed, $fallbackStation);

        $records[] = [
            'source' => 'imported_aprs_raw',
            'source_time_unix' => $unix,
            'unix_time' => $unix,
            'timestamp_utc' => gmdate('Y-m-d H:i:s', $unix) . ' UTC',
            'altitude_m' => $altMeters,
            'station' => $station,
            'latitude' => $lat,
            'longitude' => $lon,
        ];
        $parsed++;
    }

    usort($records, static fn(array $a, array $b): int => ((int)($a['unix_time'] ?? 0)) <=> ((int)($b['unix_time'] ?? 0)));

    return [
        'records' => $records,
        'parsed' => $parsed,
        'malformed' => $malformed,
        'duplicates' => $duplicates,
    ];
}

function parse_csv_timestamp_value(string $value, string $timezone): ?int
{
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone($timezone));
    if (!$dt) {
        return null;
    }

    return $dt->getTimestamp();
}

function read_csv_assoc_rows(string $path): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Failed to open CSV file.');
    }

    $header = fgetcsv($fh);
    if (!is_array($header) || count($header) === 0) {
        fclose($fh);
        throw new RuntimeException('CSV header row is missing.');
    }

    $normalizedHeader = array_map(static fn($h): string => strtolower(trim((string)$h)), $header);
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!is_array($row)) {
            continue;
        }

        $assoc = [];
        foreach ($normalizedHeader as $idx => $key) {
            $assoc[$key] = array_key_exists($idx, $row) ? trim((string)$row[$idx]) : '';
        }
        $rows[] = $assoc;
    }

    fclose($fh);
    return $rows;
}

function parse_csv_launch_rows(array $rows, string $fallbackStation, string $timezone = 'UTC'): array
{
    $records = [];
    $malformed = 0;
    $duplicates = 0;
    $seenUnix = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            $malformed++;
            continue;
        }

        $timeRaw = (string)($row['lasttime'] ?? '');
        if ($timeRaw === '') {
            $timeRaw = (string)($row['time'] ?? '');
        }

        $unix = parse_csv_timestamp_value($timeRaw, $timezone);
        if ($unix === null) {
            $malformed++;
            continue;
        }

        if (isset($seenUnix[$unix])) {
            $duplicates++;
            continue;
        }
        $seenUnix[$unix] = true;

        $altRaw = (string)($row['altitude'] ?? '');
        if ($altRaw === '' || !is_numeric($altRaw)) {
            $malformed++;
            continue;
        }

        $latRaw = (string)($row['lat'] ?? '');
        $lngRaw = (string)($row['lng'] ?? '');

        $records[] = [
            'source' => 'imported_csv',
            'source_time_unix' => $unix,
            'unix_time' => $unix,
            'timestamp_utc' => gmdate('Y-m-d H:i:s', $unix) . ' UTC',
            'altitude_m' => (float)$altRaw,
            'station' => $fallbackStation,
            'latitude' => is_numeric($latRaw) ? (float)$latRaw : null,
            'longitude' => is_numeric($lngRaw) ? (float)$lngRaw : null,
        ];
    }

    usort($records, static fn(array $a, array $b): int => ((int)$a['unix_time']) <=> ((int)$b['unix_time']));

    return [
        'records' => $records,
        'parsed' => count($records),
        'malformed' => $malformed,
        'duplicates' => $duplicates,
    ];
}

function import_csv_launch_file(string $filePath, string $fallbackStation, string $timezone = 'UTC'): array
{
    $rows = read_csv_assoc_rows($filePath);
    return parse_csv_launch_rows($rows, $fallbackStation, $timezone);
}

function slugify_launch_name(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'launch-' . gmdate('Ymd-His');
    }

    return $slug;
}

function unique_launch_id(string $baseSlug): string
{
    $candidate = $baseSlug;
    $i = 2;
    while (is_file(LAUNCHES_DIR . '/' . $candidate . '.json')) {
        $candidate = $baseSlug . '-' . $i;
        $i++;
    }

    return $candidate;
}

function create_launch_from_records(string $launchName, array $records): array
{
    ensure_data_files();

    $baseSlug = slugify_launch_name($launchName);
    $launchId = unique_launch_id($baseSlug);
    $path = LAUNCHES_DIR . '/' . $launchId . '.json';
    write_json_file($path, $records);

    return [
        'id' => $launchId,
        'path' => $path,
    ];
}

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('wxballoon_admin');
    session_start();
}

function is_admin_password_configured(): bool
{
    $config = app_config();
    return trim((string)($config['app_admin_password'] ?? '')) !== '';
}

function is_admin_authenticated(): bool
{
    return is_admin_password_configured() && (bool)($_SESSION['is_admin'] ?? false);
}

function attempt_admin_login(string $passwordInput): bool
{
    $config = app_config();
    $configuredPassword = trim((string)($config['app_admin_password'] ?? ''));
    if ($configuredPassword === '') {
        return false;
    }

    if (!hash_equals($configuredPassword, $passwordInput)) {
        return false;
    }

    $_SESSION['is_admin'] = true;
    return true;
}

function admin_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function add_record(array $record): void
{
    ensure_data_files();
    $records = read_json_file(RECORDS_FILE, []);
    if (!is_array($records)) {
        $records = [];
    }

    $records[] = $record;
    usort($records, static fn(array $a, array $b): int => ($a['unix_time'] ?? 0) <=> ($b['unix_time'] ?? 0));
    write_json_file(RECORDS_FILE, $records);
}

function delete_records_by_source_time(string $source, int $sourceTimeUnix): int
{
    ensure_data_files();
    $records = read_json_file(RECORDS_FILE, []);
    if (!is_array($records)) {
        return 0;
    }

    $before = count($records);
    $records = array_values(array_filter($records, static function (array $record) use ($source, $sourceTimeUnix): bool {
        return !(($record['source'] ?? '') === $source && (int)($record['source_time_unix'] ?? -1) === $sourceTimeUnix);
    }));

    $deleted = $before - count($records);
    if ($deleted > 0) {
        usort($records, static fn(array $a, array $b): int => ($a['unix_time'] ?? 0) <=> ($b['unix_time'] ?? 0));
        write_json_file(RECORDS_FILE, $records);
    }

    return $deleted;
}

function record_exists_by_source_time(string $source, int $sourceTimeUnix): bool
{
    $records = get_records();
    foreach ($records as $record) {
        if (($record['source'] ?? '') === $source && (int)($record['source_time_unix'] ?? -1) === $sourceTimeUnix) {
            return true;
        }
    }

    return false;
}

function get_state(): array
{
    ensure_data_files();
    $state = read_json_file(STATE_FILE, []);
    if (!is_array($state)) {
        return [
            'capture_enabled' => false,
            'last_capture_attempt_unix' => null,
            'last_capture_success_unix' => null,
            'last_error' => 'State reset due to invalid state file',
            'last_aprs_time_unix' => null,
        ];
    }

    return array_merge([
        'capture_enabled' => false,
        'last_capture_attempt_unix' => null,
        'last_capture_success_unix' => null,
        'last_error' => null,
        'last_aprs_time_unix' => null,
    ], $state);
}

function update_state(array $patch): array
{
    $state = array_merge(get_state(), $patch);
    write_json_file(STATE_FILE, $state);
    return $state;
}

function parse_manual_timestamp_to_unix(string $value): ?int
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $trimmed, new DateTimeZone('America/Chicago'));
    if (!$dt) {
        return null;
    }

    return $dt->getTimestamp();
}

function fetch_aprs_location(bool $force = false): array
{
    ensure_data_files();
    $config = app_config();
    $station = $config['aprs_station'];
    $apiKey = $config['aprs_api_key'];
    $userAgent = $config['app_user_agent'];

    if ($station === '' || $apiKey === '') {
        throw new RuntimeException('APRS is not configured. Set APRS_STATION and APRS_API_KEY in .env.');
    }

    $cache = read_json_file(CACHE_FILE, ['fetched_at_unix' => null, 'payload' => null]);
    $cachedAt = (int)($cache['fetched_at_unix'] ?? 0);
    $now = time();

    if (!$force && $cachedAt > 0 && ($now - $cachedAt) < APRS_MIN_FETCH_SECONDS) {
        if (isset($cache['payload']) && is_array($cache['payload'])) {
            return ['source' => 'cache', 'payload' => $cache['payload']];
        }
    }

    $query = http_build_query([
        'name' => $station,
        'what' => 'loc',
        'apikey' => $apiKey,
        'format' => 'json',
    ]);

    $url = APRS_API_BASE . '?' . $query;

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: " . $userAgent . "\r\n" .
                "Accept: application/json\r\n",
            'timeout' => 6,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        throw new RuntimeException('Unable to reach APRS API.');
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        throw new RuntimeException('APRS API returned invalid JSON.');
    }

    write_json_file(CACHE_FILE, [
        'fetched_at_unix' => $now,
        'payload' => $payload,
    ]);

    return ['source' => 'network', 'payload' => $payload];
}

function capture_from_aprs(bool $force = false): array
{
    $config = app_config();

    update_state([
        'last_capture_attempt_unix' => time(),
    ]);

    $result = fetch_aprs_location($force);
    $payload = $result['payload'];

    if (($payload['result'] ?? '') !== 'ok') {
        $fail = update_state([
            'last_error' => 'APRS API returned non-ok result.',
        ]);

        return [
            'ok' => false,
            'message' => 'APRS API returned non-ok result.',
            'state' => $fail,
        ];
    }

    $entries = $payload['entries'] ?? null;
    if (!is_array($entries) || count($entries) === 0 || !is_array($entries[0])) {
        $fail = update_state([
            'last_error' => 'No location entries were returned.',
        ]);

        return [
            'ok' => false,
            'message' => 'No location entries were returned.',
            'state' => $fail,
        ];
    }

    $entry = $entries[0];
    $altitude = isset($entry['altitude']) ? (float)$entry['altitude'] : null;
    $timeUnix = isset($entry['lasttime']) ? (int)$entry['lasttime'] : (isset($entry['time']) ? (int)$entry['time'] : null);
    $latitude = null;
    if (isset($entry['lat'])) {
        $latitude = (float)$entry['lat'];
    } elseif (isset($entry['latitude'])) {
        $latitude = (float)$entry['latitude'];
    }

    $longitude = null;
    if (isset($entry['lng'])) {
        $longitude = (float)$entry['lng'];
    } elseif (isset($entry['lon'])) {
        $longitude = (float)$entry['lon'];
    } elseif (isset($entry['longitude'])) {
        $longitude = (float)$entry['longitude'];
    }

    if ($altitude === null || $timeUnix === null || $timeUnix <= 0) {
        $fail = update_state([
            'last_error' => 'APRS entry did not include altitude/time.',
        ]);

        return [
            'ok' => false,
            'message' => 'APRS entry did not include altitude/time.',
            'state' => $fail,
        ];
    }

    if (!record_exists_by_source_time('aprs', $timeUnix)) {
        add_record([
            'source' => 'aprs',
            'source_time_unix' => $timeUnix,
            'unix_time' => $timeUnix,
            'timestamp_utc' => gmdate('Y-m-d H:i:s', $timeUnix) . ' UTC',
            'altitude_m' => $altitude,
            'station' => $config['aprs_station'],
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    $okState = update_state([
        'last_capture_success_unix' => time(),
        'last_aprs_time_unix' => $timeUnix,
        'last_error' => null,
    ]);

    return [
        'ok' => true,
        'message' => 'Capture complete.',
        'state' => $okState,
        'entry' => [
            'source_time_unix' => $timeUnix,
            'altitude_m' => $altitude,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ],
        'fetch_source' => $result['source'],
    ];
}
