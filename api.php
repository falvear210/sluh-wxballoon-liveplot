<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
start_admin_session();

header('Content-Type: application/json');

/** Emit a JSON response and terminate request execution. */
function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** Enforce admin auth for editor actions that mutate or delete records. */
function require_admin_for_editor_action(string $action): void
{
    $adminOnlyActions = ['add_manual', 'delete_record'];
    if (in_array($action, $adminOnlyActions, true) && !is_admin_authenticated()) {
        respond([
            'ok' => false,
            'error' => 'Unauthorized.',
        ], 403);
    }
}

try {
    ensure_data_files();
    require_admin_for_editor_action((string)$action);

    if ($action === 'status') {
        respond([
            'ok' => true,
            'state' => get_state(),
            'records' => get_records(),
        ]);
    }

    if ($method === 'POST' && $action === 'toggle_capture') {
        $enabled = filter_var($_POST['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            respond(['ok' => false, 'error' => 'Missing or invalid enabled flag.'], 400);
        }

        $state = update_state([
            'capture_enabled' => $enabled,
            'last_error' => null,
        ]);

        if ($enabled) {
            $capture = capture_from_aprs(false);
            respond([
                'ok' => true,
                'state' => $capture['state'],
                'capture' => $capture,
                'records' => get_records(),
            ]);
        }

        respond([
            'ok' => true,
            'state' => $state,
            'records' => get_records(),
        ]);
    }

    if ($method === 'POST' && $action === 'capture_now') {
        $state = get_state();
        if (!($state['capture_enabled'] ?? false)) {
            respond([
                'ok' => false,
                'error' => 'Capture is disabled. Enable capture toggle first.',
                'state' => $state,
            ], 400);
        }

        $capture = capture_from_aprs(false);
        respond([
            'ok' => $capture['ok'],
            'capture' => $capture,
            'state' => $capture['state'],
            'records' => get_records(),
        ], $capture['ok'] ? 200 : 502);
    }

    if ($method === 'POST' && $action === 'add_manual') {
        $timestamp = (string)($_POST['timestamp'] ?? '');
        $altitudeInput = (string)($_POST['altitude_m'] ?? '');

        $unix = parse_manual_timestamp_to_unix($timestamp);
        if ($unix === null) {
            respond(['ok' => false, 'error' => 'Invalid timestamp format. Use YYYY-MM-DDTHH:MM.'], 400);
        }

        if (!is_numeric($altitudeInput)) {
            respond(['ok' => false, 'error' => 'Altitude must be numeric.'], 400);
        }

        $altitude = (float)$altitudeInput;
        add_record([
            'source' => 'manual',
            'source_time_unix' => $unix,
            'unix_time' => $unix,
            'timestamp_utc' => gmdate('Y-m-d H:i:s', $unix) . ' UTC',
            'altitude_m' => $altitude,
            'station' => null,
        ]);

        respond([
            'ok' => true,
            'state' => get_state(),
            'records' => get_records(),
        ]);
    }

    if ($method === 'POST' && $action === 'delete_record') {
        $source = trim((string)($_POST['source'] ?? ''));
        $sourceTimeUnix = filter_var($_POST['source_time_unix'] ?? null, FILTER_VALIDATE_INT);
        $allowProtectedDelete = filter_var($_POST['allow_protected_delete'] ?? null, FILTER_VALIDATE_BOOLEAN) === true;

        if ($source === '' || $sourceTimeUnix === false) {
            respond(['ok' => false, 'error' => 'Missing source or source_time_unix.'], 400);
        }

        $target = get_record_by_source_time($source, (int)$sourceTimeUnix);
        if ($target === null) {
            respond(['ok' => false, 'error' => 'No matching record found.'], 404);
        }
        if (is_real_aprs_record($target) && !$allowProtectedDelete) {
            respond([
                'ok' => false,
                'error' => 'Protected APRS datapoints cannot be deleted without explicit override.',
            ], 403);
        }

        $deleted = delete_records_by_source_time($source, (int)$sourceTimeUnix, $allowProtectedDelete);
        if ($deleted === 0) {
            respond(['ok' => false, 'error' => 'No matching record found.'], 404);
        }

        respond([
            'ok' => true,
            'deleted' => $deleted,
            'state' => get_state(),
            'records' => get_records(),
        ]);
    }

    respond(['ok' => false, 'error' => 'Unknown action.'], 404);
} catch (Throwable $e) {
    $state = [];
    try {
        $state = update_state([
            'last_error' => $e->getMessage(),
        ]);
    } catch (Throwable $inner) {
        $state = [
            'capture_enabled' => false,
            'last_error' => $e->getMessage() . ' | state-update-failed: ' . $inner->getMessage(),
        ];
    }

    respond([
        'ok' => false,
        'error' => $e->getMessage(),
        'state' => $state,
    ], 500);
}
