<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
ensure_data_files();
start_admin_session();

$saved = false;
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
                $station = trim((string)($_POST['aprs_station'] ?? ''));
                $apiKey = trim((string)($_POST['aprs_api_key'] ?? ''));
                $captureEnabled = isset($_POST['capture_enabled']);

                update_config_overrides([
                    'aprs_station' => $station,
                    'aprs_api_key' => $apiKey,
                ]);

                update_state([
                    'capture_enabled' => $captureEnabled,
                    'last_error' => null,
                ]);

                header('Location: settings.php?saved=1');
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
        <div class="row">
          <button type="submit">Save Settings</button>
          <a class="linkbtn secondary" href="index.php">Back to Live Plot</a>
          <a class="linkbtn secondary" href="editor.php">Open Data Editor</a>
          <a class="linkbtn secondary" href="admin_import.php">Admin APRS Import</a>
          <a class="linkbtn secondary" href="admin_csv_import.php">Admin CSV Import</a>
        </div>
      </form>
      <p class="muted" style="margin-top:12px;">The station and API key are saved in <code>data/config.json</code>.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
