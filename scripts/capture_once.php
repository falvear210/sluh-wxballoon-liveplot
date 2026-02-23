<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/app.php';

ensure_data_files();

try {
    $state = get_state();
    if (!($state['capture_enabled'] ?? false)) {
        fwrite(STDOUT, "Capture disabled; skipping.\n");
        exit(0);
    }

    $result = capture_from_aprs(false);
    if (!($result['ok'] ?? false)) {
        $message = (string)($result['message'] ?? 'Capture failed.');
        fwrite(STDERR, $message . "\n");
        exit(2);
    }

    $entry = is_array($result['entry'] ?? null) ? $result['entry'] : [];
    $timeUnix = (int)($entry['source_time_unix'] ?? 0);
    $altitude = (float)($entry['altitude_m'] ?? 0);
    fwrite(STDOUT, "Capture ok: time={$timeUnix}, altitude_m={$altitude}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Capture error: " . $e->getMessage() . "\n");
    exit(1);
}
