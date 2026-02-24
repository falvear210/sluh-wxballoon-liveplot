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
const SIMULATION_DEFAULT_FILE = 'aprs_simulation.json';
const SIMULATION_DEFAULT_POLL_SECONDS = 5;
const APP_DEFAULT_USER_AGENT = 'wxballoon-liveplot/1.0 (+http://localhost/wxballoon-liveplot)';

/** Load key/value pairs from a .env file into process environment variables. */
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

/** Return application config merged from env defaults and persisted overrides. */
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

/** Ensure required data directories and JSON files exist with safe defaults. */
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
            'browser_polling_enabled' => true,
            'simulation_mode' => false,
            'simulation_file' => SIMULATION_DEFAULT_FILE,
            'simulation_poll_seconds' => SIMULATION_DEFAULT_POLL_SECONDS,
            'simulation_next_index' => 0,
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

/** Read JSON from disk and return a fallback value if unavailable or invalid. */
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

/** Atomically persist JSON to disk using a temporary file and rename swap. */
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

/** Load saved configuration overrides from the config store. */
function get_config_overrides(): array
{
    ensure_data_files();
    $config = read_json_file(CONFIG_FILE, []);
    if (!is_array($config)) {
        return [];
    }

    return $config;
}

/** Merge and persist configuration overrides, then return the full result. */
function update_config_overrides(array $patch): array
{
    $config = array_merge(get_config_overrides(), $patch);
    write_json_file(CONFIG_FILE, $config);
    return $config;
}

/** Return current-launch records sorted by unix timestamp ascending. */
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

/** Normalize and validate a launch ID for safe filename/path usage. */
function normalize_launch_id(string $launchId): ?string
{
    $id = strtolower(trim($launchId));
    if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) {
        return null;
    }

    return $id;
}

/** Load records for a specific historical launch ID. */
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

/** List available historical launches and lightweight metadata for each. */
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

/** Convert APRS DDMM.MM/DDDMM.MM coordinate text to signed decimal degrees. */
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

/** Parse APRS latitude/longitude values from a raw packet text line. */
function parse_aprs_lat_lon_from_raw_line(string $line): array
{
    if (!preg_match('/!([0-9]{4}\.[0-9]{2})([NS])\/([0-9]{5}\.[0-9]{2})([EW])/', $line, $m)) {
        return [null, null];
    }

    $lat = aprs_coord_to_decimal($m[1], $m[2]);
    $lon = aprs_coord_to_decimal($m[3], $m[4]);
    return [$lat, $lon];
}

/** Parse packet timestamp from a raw APRS line and convert to Unix time. */
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

/** Extract a station callsign from a raw APRS line, or use fallback station. */
function parse_aprs_station_from_raw_line(string $line, string $fallbackStation): string
{
    if (preg_match('/:\s*([A-Za-z0-9-]+)>/', $line, $m)) {
        return strtoupper(trim($m[1]));
    }

    return $fallbackStation;
}

/** Parse multiline APRS paste text into normalized record objects with stats. */
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

/** Parse a CSV timestamp string in the provided timezone into Unix time. */
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

/** Read a CSV file and return rows as lowercased associative arrays. */
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

/** Transform associative CSV rows into normalized launch record structures. */
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

/** Import and parse a CSV launch file into normalized records. */
function import_csv_launch_file(string $filePath, string $fallbackStation, string $timezone = 'UTC'): array
{
    $rows = read_csv_assoc_rows($filePath);
    return parse_csv_launch_rows($rows, $fallbackStation, $timezone);
}

/** Convert a display launch name into a filesystem-safe slug. */
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

/** Ensure the launch slug is unique by appending an incrementing suffix. */
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

/** Persist a new historical launch file and return its ID and path. */
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

/** Start the admin session if it is not already active. */
function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('wxballoon_admin');
    session_start();
}

/** Return true when an admin password is configured. */
function is_admin_password_configured(): bool
{
    $config = app_config();
    return trim((string)($config['app_admin_password'] ?? '')) !== '';
}

/** Return true when an authenticated admin session is active. */
function is_admin_authenticated(): bool
{
    return is_admin_password_configured() && (bool)($_SESSION['is_admin'] ?? false);
}

/** Validate login password and mark the session as authenticated admin. */
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

/** Destroy admin session authentication state. */
function admin_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Append one record to current launch data and keep records time-sorted. */
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

/** Return true when a record should be treated as protected real APRS data. */
function is_real_aprs_record(array $record): bool
{
    if (array_key_exists('is_real_aprs', $record)) {
        return (bool)$record['is_real_aprs'];
    }

    $source = trim((string)($record['source'] ?? ''));
    return in_array($source, ['aprs', 'imported_aprs_raw'], true);
}

/** Find one record by source and source timestamp key. */
function get_record_by_source_time(string $source, int $sourceTimeUnix): ?array
{
    $records = get_records();
    foreach ($records as $record) {
        if (($record['source'] ?? '') === $source && (int)($record['source_time_unix'] ?? -1) === $sourceTimeUnix) {
            return $record;
        }
    }

    return null;
}

/** Delete matching records unless protected APRS records are disallowed. */
function delete_records_by_source_time(string $source, int $sourceTimeUnix, bool $allowProtectedDelete = false): int
{
    ensure_data_files();
    $records = read_json_file(RECORDS_FILE, []);
    if (!is_array($records)) {
        return 0;
    }

    $before = count($records);
    $records = array_values(array_filter($records, static function (array $record) use ($source, $sourceTimeUnix, $allowProtectedDelete): bool {
        $isMatch = (($record['source'] ?? '') === $source && (int)($record['source_time_unix'] ?? -1) === $sourceTimeUnix);
        if (!$isMatch) {
            return true;
        }

        if (!$allowProtectedDelete && is_real_aprs_record($record)) {
            return true;
        }

        return false;
    }));

    $deleted = $before - count($records);
    if ($deleted > 0) {
        usort($records, static fn(array $a, array $b): int => ($a['unix_time'] ?? 0) <=> ($b['unix_time'] ?? 0));
        write_json_file(RECORDS_FILE, $records);
    }

    return $deleted;
}

/** Check whether a record already exists by source and source timestamp key. */
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

/** Clear current-launch records, optionally preserving protected real APRS rows. */
function clear_current_records(bool $preserveRealAprs = true): array
{
    ensure_data_files();
    $records = read_json_file(RECORDS_FILE, []);
    if (!is_array($records)) {
        write_json_file(RECORDS_FILE, []);
        return ['deleted' => 0, 'kept' => 0];
    }

    if (!$preserveRealAprs) {
        $deleted = count($records);
        write_json_file(RECORDS_FILE, []);
        return ['deleted' => $deleted, 'kept' => 0];
    }

    $kept = array_values(array_filter($records, static fn(array $record): bool => is_real_aprs_record($record)));
    usort($kept, static fn(array $a, array $b): int => ($a['unix_time'] ?? 0) <=> ($b['unix_time'] ?? 0));
    write_json_file(RECORDS_FILE, $kept);

    return [
        'deleted' => count($records) - count($kept),
        'kept' => count($kept),
    ];
}

/** Return app runtime state merged with required default values. */
function get_state(): array
{
    ensure_data_files();
    $state = read_json_file(STATE_FILE, []);
    if (!is_array($state)) {
        return [
            'capture_enabled' => false,
            'browser_polling_enabled' => true,
            'simulation_mode' => false,
            'simulation_file' => SIMULATION_DEFAULT_FILE,
            'simulation_poll_seconds' => SIMULATION_DEFAULT_POLL_SECONDS,
            'simulation_next_index' => 0,
            'last_capture_attempt_unix' => null,
            'last_capture_success_unix' => null,
            'last_error' => 'State reset due to invalid state file',
            'last_aprs_time_unix' => null,
        ];
    }

    return array_merge([
        'capture_enabled' => false,
        'browser_polling_enabled' => true,
        'simulation_mode' => false,
        'simulation_file' => SIMULATION_DEFAULT_FILE,
        'simulation_poll_seconds' => SIMULATION_DEFAULT_POLL_SECONDS,
        'simulation_next_index' => 0,
        'last_capture_attempt_unix' => null,
        'last_capture_success_unix' => null,
        'last_error' => null,
        'last_aprs_time_unix' => null,
    ], $state);
}

/** Sanitize and validate a simulation JSON filename within the data directory. */
function simulation_file_name(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return SIMULATION_DEFAULT_FILE;
    }

    $safe = basename($trimmed);
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $safe)) {
        return SIMULATION_DEFAULT_FILE;
    }

    return $safe;
}

/** Load simulation entries from a JSON array or { entries: [...] } envelope. */
function load_simulation_entries(string $fileName): array
{
    $path = DATA_DIR . '/' . simulation_file_name($fileName);
    $decoded = read_json_file($path, null);

    if (is_array($decoded) && array_is_list($decoded)) {
        return $decoded;
    }

    if (is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])) {
        return $decoded['entries'];
    }

    throw new RuntimeException('Simulation file is missing, invalid, or has no entries.');
}

/** Parse simulation latitude from known key variants. */
function parse_simulated_latitude(array $entry): ?float
{
    $raw = $entry['lat'] ?? $entry['latitude'] ?? null;
    return is_numeric($raw) ? (float)$raw : null;
}

/** Parse simulation longitude from known key variants. */
function parse_simulated_longitude(array $entry): ?float
{
    $raw = $entry['lng'] ?? $entry['lon'] ?? $entry['longitude'] ?? null;
    return is_numeric($raw) ? (float)$raw : null;
}

/** Parse simulation altitude from known key variants. */
function parse_simulated_altitude(array $entry): ?float
{
    $raw = $entry['altitude_m'] ?? $entry['altitude'] ?? null;
    return is_numeric($raw) ? (float)$raw : null;
}

/** Parse simulation source timestamp from known key variants. */
function parse_simulated_time_unix(array $entry): ?int
{
    $raw = $entry['source_time_unix'] ?? $entry['unix_time'] ?? $entry['lasttime'] ?? $entry['time'] ?? null;
    if (!is_numeric($raw)) {
        return null;
    }

    $value = (int)$raw;
    return $value > 0 ? $value : null;
}

/** Resolve simulation source label, defaulting to simulated_aprs. */
function parse_simulated_source(array $entry): string
{
    $raw = trim((string)($entry['source'] ?? 'simulated_aprs'));
    return $raw !== '' ? $raw : 'simulated_aprs';
}

/** Resolve simulation station from entry or configured station fallback. */
function parse_simulated_station(array $entry, array $config): string
{
    $station = trim((string)($entry['station'] ?? $config['aprs_station'] ?? 'SIM'));
    return $station !== '' ? $station : 'SIM';
}

/** Capture one datapoint from simulation feed and advance simulation cursor. */
function capture_from_simulation(array $state, array $config): array
{
    $fileName = simulation_file_name((string)($state['simulation_file'] ?? SIMULATION_DEFAULT_FILE));
    $entries = load_simulation_entries($fileName);
    $count = count($entries);
    if ($count === 0) {
        throw new RuntimeException('Simulation file has no rows.');
    }

    $index = (int)($state['simulation_next_index'] ?? 0);
    if ($index < 0 || $index >= $count) {
        $index = 0;
    }

    $rawEntry = $entries[$index];
    if (!is_array($rawEntry)) {
        throw new RuntimeException('Simulation entry at index ' . $index . ' is invalid.');
    }

    $altitude = parse_simulated_altitude($rawEntry);
    if ($altitude === null) {
        throw new RuntimeException('Simulation entry at index ' . $index . ' is missing altitude.');
    }

    $providedTimeUnix = parse_simulated_time_unix($rawEntry);
    $lastAprsUnix = (int)($state['last_aprs_time_unix'] ?? 0);
    $timeUnix = $providedTimeUnix ?? max(time(), $lastAprsUnix + 1);
    $source = parse_simulated_source($rawEntry);
    $station = parse_simulated_station($rawEntry, $config);
    $timestampUtc = trim((string)($rawEntry['timestamp_utc'] ?? ''));
    if ($timestampUtc === '') {
        $timestampUtc = gmdate('Y-m-d H:i:s', $timeUnix) . ' UTC';
    }
    $latitude = parse_simulated_latitude($rawEntry);
    $longitude = parse_simulated_longitude($rawEntry);

    add_record([
        'source' => $source,
        'is_real_aprs' => false,
        'source_time_unix' => $timeUnix,
        'unix_time' => $timeUnix,
        'timestamp_utc' => $timestampUtc,
        'altitude_m' => $altitude,
        'station' => $station,
        'latitude' => $latitude,
        'longitude' => $longitude,
    ]);

    $nextIndex = ($index + 1) % $count;
    $okState = update_state([
        'simulation_file' => $fileName,
        'simulation_next_index' => $nextIndex,
        'last_capture_success_unix' => time(),
        'last_aprs_time_unix' => $timeUnix,
        'last_error' => null,
    ]);

    return [
        'ok' => true,
        'message' => 'Simulation capture complete.',
        'state' => $okState,
        'entry' => [
            'source_time_unix' => $timeUnix,
            'altitude_m' => $altitude,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ],
        'fetch_source' => 'simulation',
    ];
}

/** Merge and persist app runtime state, then return the merged state. */
function update_state(array $patch): array
{
    $state = array_merge(get_state(), $patch);
    write_json_file(STATE_FILE, $state);
    return $state;
}

/** Parse manual UI timestamp (Central Time) into Unix time. */
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

/** Fetch latest APRS location payload using short cache to reduce API load. */
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

/** Perform one capture cycle from APRS (or simulation when enabled). */
function capture_from_aprs(bool $force = false): array
{
    $config = app_config();

    $state = update_state([
        'last_capture_attempt_unix' => time(),
    ]);

    if ((bool)($state['simulation_mode'] ?? false)) {
        return capture_from_simulation($state, $config);
    }

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
            'is_real_aprs' => true,
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
