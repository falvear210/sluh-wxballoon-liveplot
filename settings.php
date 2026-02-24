<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
ensure_data_files();
start_admin_session();

$saved = false;
$cleaned = false;
$cleanedDeleted = null;
$cleanedKept = null;
$error = null;
$isConfigured = is_admin_password_configured();
$isAdmin = is_admin_authenticated();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'logout') {
        admin_logout();
        header('Location: settings.php');
        exit;
    }

    if ($action === 'login') {
        if (!$isConfigured) {
            $error = 'APP_ADMIN_PASSWORD is not configured in .env.';
        } else {
            $passwordInput = (string)($_POST['password'] ?? '');
            if (attempt_admin_login($passwordInput)) {
                header('Location: settings.php');
                exit;
            }
            $error = 'Invalid password.';
        }
    }

    if ($action === 'save') {
        if (!$isAdmin) {
            $error = 'Not authorized.';
        } else {
            try {
                $previousState = get_state();
                $station = trim((string)($_POST['aprs_station'] ?? ''));
                $apiKey = trim((string)($_POST['aprs_api_key'] ?? ''));
                $captureEnabled = isset($_POST['capture_enabled']);
                $browserPollingEnabled = isset($_POST['browser_polling_enabled']);
                $simulationMode = isset($_POST['simulation_mode']);
                $simulationFile = simulation_file_name((string)($_POST['simulation_file'] ?? SIMULATION_DEFAULT_FILE));
                $simulationPollSeconds = filter_var($_POST['simulation_poll_seconds'] ?? null, FILTER_VALIDATE_INT);
                if ($simulationPollSeconds === false || $simulationPollSeconds < 1) {
                    $simulationPollSeconds = SIMULATION_DEFAULT_POLL_SECONDS;
                }
                if ($simulationPollSeconds > 300) {
                    $simulationPollSeconds = 300;
                }
                $switchedToSimulation = !((bool)($previousState['simulation_mode'] ?? false)) && $simulationMode;

                update_config_overrides([
                    'aprs_station' => $station,
                    'aprs_api_key' => $apiKey,
                ]);

                if ($switchedToSimulation) {
                    clear_current_records(true);
                }

                update_state([
                    'capture_enabled' => $captureEnabled,
                    'browser_polling_enabled' => $browserPollingEnabled,
                    'simulation_mode' => $simulationMode,
                    'simulation_file' => $simulationFile,
                    'simulation_poll_seconds' => $simulationPollSeconds,
                    'simulation_next_index' => 0,
                    'last_capture_attempt_unix' => $switchedToSimulation ? null : ($previousState['last_capture_attempt_unix'] ?? null),
                    'last_capture_success_unix' => $switchedToSimulation ? null : ($previousState['last_capture_success_unix'] ?? null),
                    'last_aprs_time_unix' => $switchedToSimulation ? null : ($previousState['last_aprs_time_unix'] ?? null),
                    'last_error' => null,
                ]);

                header('Location: settings.php?saved=1');
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }

    if ($action === 'start_clean') {
        if (!$isAdmin) {
            $error = 'Not authorized.';
        } else {
            try {
                $preserveRealAprs = isset($_POST['preserve_real_aprs']);
                $confirmDeleteRealAprs = trim((string)($_POST['confirm_delete_real_aprs'] ?? ''));
                if (!$preserveRealAprs && $confirmDeleteRealAprs !== 'DELETE REAL APRS') {
                    throw new RuntimeException('To delete protected APRS data, type DELETE REAL APRS exactly.');
                }

                $result = clear_current_records($preserveRealAprs);
                update_state([
                    'simulation_next_index' => 0,
                    'last_capture_attempt_unix' => null,
                    'last_capture_success_unix' => null,
                    'last_aprs_time_unix' => null,
                    'last_error' => null,
                ]);

                $query = http_build_query([
                    'cleaned' => '1',
                    'deleted' => (string)($result['deleted'] ?? 0),
                    'kept' => (string)($result['kept'] ?? 0),
                ]);
                header('Location: settings.php?' . $query);
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }

    $isConfigured = is_admin_password_configured();
    $isAdmin = is_admin_authenticated();
}

$config = app_config();
$state = get_state();
$saved = ($_GET['saved'] ?? '') === '1';
$cleaned = ($_GET['cleaned'] ?? '') === '1';
$cleanedDeleted = filter_var($_GET['deleted'] ?? null, FILTER_VALIDATE_INT);
$cleanedKept = filter_var($_GET['kept'] ?? null, FILTER_VALIDATE_INT);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Weather Balloon Settings</title>
  <style>
    :root {
      --bg: #f5f7fb;
      --panel: #ffffff;
      --text: #1f2937;
      --muted: #6b7280;
      --primary: #0f766e;
      --danger: #b91c1c;
      --border: #e5e7eb;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Menlo, Consolas, monospace;
      background: linear-gradient(180deg, #eef4ff 0%, var(--bg) 40%);
      color: var(--text);
    }
    .container { max-width: 760px; margin: 0 auto; padding: 20px; }
    .panel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 14px;
      margin-bottom: 16px;
    }
    h1 { margin: 0 0 12px; font-size: 22px; }
    label { display: block; margin-bottom: 12px; font-size: 13px; }
    input[type="text"] {
      width: 100%;
      margin-top: 6px;
      padding: 8px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-family: inherit;
      background: white;
    }
    .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .muted { color: var(--muted); font-size: 13px; }
    .msg { margin: 0 0 12px; font-size: 13px; }
    .msg.ok { color: #047857; }
    .msg.err { color: var(--danger); }
    button, .linkbtn {
      border: 1px solid var(--primary);
      background: var(--primary);
      color: white;
      padding: 8px 12px;
      border-radius: 6px;
      font-family: inherit;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
      font-size: 13px;
    }
    .linkbtn.secondary { background: #fff; color: var(--primary); }
  </style>
</head>
<body>
<div class="container">
  <div class="panel">
    <div class="row" style="justify-content: space-between;">
      <h1>Settings</h1>
      <?php if ($isAdmin): ?>
        <form method="post" action="settings.php" style="margin:0;">
          <input type="hidden" name="action" value="logout">
          <button type="submit" class="secondary">Log Out</button>
        </form>
      <?php endif; ?>
    </div>
    <?php if ($saved): ?>
      <p class="msg ok">Settings saved.</p>
    <?php endif; ?>
    <?php if ($cleaned): ?>
      <p class="msg ok">Current launch reset complete. Removed <?= (int)$cleanedDeleted ?> records, kept <?= (int)$cleanedKept ?> protected APRS records.</p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
      <p class="msg err"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>
    <?php if (!$isConfigured): ?>
      <p class="msg err">Set <code>APP_ADMIN_PASSWORD</code> in <code>.env</code> to enable admin pages.</p>
    <?php elseif (!$isAdmin): ?>
      <form method="post" action="settings.php">
        <input type="hidden" name="action" value="login">
        <label>
          Password
          <input type="password" name="password" required>
        </label>
        <button type="submit">Log In</button>
      </form>
    <?php else: ?>
      <form method="post" action="settings.php">
        <input type="hidden" name="action" value="save">
        <label>
          APRS Station
          <input type="text" name="aprs_station" value="<?= htmlspecialchars((string)$config['aprs_station'], ENT_QUOTES) ?>" placeholder="N0YD-11">
        </label>
        <label>
          APRS API Key
          <input type="text" name="aprs_api_key" value="<?= htmlspecialchars((string)$config['aprs_api_key'], ENT_QUOTES) ?>" placeholder="Enter APRS API key">
        </label>
        <label>
          <input type="checkbox" name="capture_enabled" value="1" <?= ($state['capture_enabled'] ?? false) ? 'checked' : '' ?>>
          Enable APRS capture
        </label>
        <label>
          <input type="checkbox" name="browser_polling_enabled" value="1" <?= ($state['browser_polling_enabled'] ?? true) ? 'checked' : '' ?>>
          Allow browser polling API calls (turn off when server cron handles capture)
        </label>
        <label>
          <input type="checkbox" name="simulation_mode" value="1" <?= ($state['simulation_mode'] ?? false) ? 'checked' : '' ?>>
          Enable APRS simulation mode (uses local JSON data, 5-second push cadence)
        </label>
        <label>
          Simulation JSON file (inside <code>data/</code>)
          <input type="text" name="simulation_file" value="<?= htmlspecialchars((string)($state['simulation_file'] ?? SIMULATION_DEFAULT_FILE), ENT_QUOTES) ?>" placeholder="aprs_simulation.json">
        </label>
        <label>
          Simulation polling interval (seconds)
          <input type="number" name="simulation_poll_seconds" min="1" max="300" step="1" value="<?= (int)($state['simulation_poll_seconds'] ?? SIMULATION_DEFAULT_POLL_SECONDS) ?>">
        </label>
        <div class="row">
          <button type="submit">Save Settings</button>
          <a class="linkbtn secondary" href="index.php">Back to Live Plot</a>
          <a class="linkbtn secondary" href="editor.php">Open Data Editor</a>
          <a class="linkbtn secondary" href="admin_import.php">Admin APRS Import</a>
          <a class="linkbtn secondary" href="admin_csv_import.php">Admin CSV Import</a>
        </div>
      </form>
      <p class="muted" style="margin-top:12px;">APRS station/API key are saved in <code>data/config.json</code>. Capture/simulation toggles are saved in <code>data/state.json</code>.</p>
      <hr style="margin:14px 0; border:none; border-top:1px solid var(--border);">
      <form method="post" action="settings.php" onsubmit="return confirmStartClean(this);" style="margin:0;">
        <input type="hidden" name="action" value="start_clean">
        <p class="msg" style="margin-bottom:8px;"><strong>Start Clean (Current Launch)</strong></p>
        <label>
          <input type="checkbox" id="preserve_real_aprs" name="preserve_real_aprs" value="1" checked>
          Keep protected real APRS records (recommended)
        </label>
        <label>
          Confirmation (required only when deleting protected APRS): type <code>DELETE REAL APRS</code>
          <input type="text" name="confirm_delete_real_aprs" id="confirm_delete_real_aprs" placeholder="DELETE REAL APRS">
        </label>
        <div class="row">
          <button type="submit" style="background: var(--danger); border-color: var(--danger);">Start Clean on Current Launch</button>
        </div>
      </form>
      <script>
        // Confirm destructive "start clean" behavior before submitting.
        function confirmStartClean(form) {
          var preserve = form.querySelector('#preserve_real_aprs').checked;
          if (preserve) {
            return confirm('Start clean for current launch? Protected real APRS records will be kept.');
          }
          return confirm('Start clean for current launch and permanently delete protected real APRS records?');
        }
      </script>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
