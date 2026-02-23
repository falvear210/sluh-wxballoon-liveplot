<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
ensure_data_files();
start_admin_session();
$config = app_config();

$isConfigured = is_admin_password_configured();
$isAdmin = is_admin_authenticated();

$error = null;
$notice = null;
$createdLaunchId = null;
$launchName = '';
$stationInput = '';
$timezoneInput = 'UTC';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'logout') {
        admin_logout();
        header('Location: admin_csv_import.php');
        exit;
    }

    if ($action === 'login') {
        if (!$isConfigured) {
            $error = 'APP_ADMIN_PASSWORD is not configured in .env.';
        } else {
            $passwordInput = (string)($_POST['password'] ?? '');
            if (attempt_admin_login($passwordInput)) {
                header('Location: admin_csv_import.php');
                exit;
            }
            $error = 'Invalid password.';
        }
    }

    if ($action === 'import') {
        if (!$isAdmin) {
            $error = 'Not authorized.';
        } else {
            try {
                $launchName = trim((string)($_POST['launch_name'] ?? ''));
                $stationInput = trim((string)($_POST['station'] ?? ''));
                $timezoneInput = trim((string)($_POST['timezone'] ?? 'UTC'));
                if ($timezoneInput === '') {
                    $timezoneInput = 'UTC';
                }

                if ($launchName === '') {
                    throw new RuntimeException('Launch name is required.');
                }

                try {
                    new DateTimeZone($timezoneInput);
                } catch (Throwable $e) {
                    throw new RuntimeException('Invalid timezone.');
                }

                if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
                    throw new RuntimeException('CSV file is required.');
                }

                $upload = $_FILES['csv_file'];
                $errCode = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($errCode !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('File upload failed (error code ' . $errCode . ').');
                }

                $tmpName = (string)($upload['tmp_name'] ?? '');
                if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                    throw new RuntimeException('Invalid uploaded file.');
                }

                $fallbackStation = $stationInput;
                if ($fallbackStation === '') {
                    $fallbackStation = trim((string)($config['aprs_station'] ?? ''));
                }
                if ($fallbackStation === '') {
                    $fallbackStation = 'unknown';
                }

                $parsed = import_csv_launch_file($tmpName, $fallbackStation, $timezoneInput);
                if ((int)$parsed['parsed'] === 0) {
                    throw new RuntimeException('No valid rows found in CSV.');
                }

                $created = create_launch_from_records($launchName, $parsed['records']);
                $createdLaunchId = (string)$created['id'];
                $notice = 'Imported launch "' . $createdLaunchId . '" with ' . (int)$parsed['parsed'] . ' rows. Malformed: ' . (int)$parsed['malformed'] . ', duplicates: ' . (int)$parsed['duplicates'] . '.';

                $launchName = '';
                $stationInput = '';
                $timezoneInput = 'UTC';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }

    $isConfigured = is_admin_password_configured();
    $isAdmin = is_admin_authenticated();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin CSV Import</title>
  <style>
    :root {
      --bg: #f5f7fb;
      --panel: #ffffff;
      --text: #1f2937;
      --muted: #6b7280;
      --primary: #0f766e;
      --danger: #b91c1c;
      --ok: #166534;
      --border: #e5e7eb;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Menlo, Consolas, monospace;
      background: linear-gradient(180deg, #eef4ff 0%, var(--bg) 40%);
      color: var(--text);
    }
    .container { max-width: 900px; margin: 0 auto; padding: 20px; }
    .panel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 14px;
      margin-bottom: 16px;
    }
    h1 { margin: 0 0 12px; font-size: 22px; }
    h2 { margin: 0 0 10px; font-size: 16px; }
    .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .muted { color: var(--muted); font-size: 13px; }
    .msg { margin: 0 0 12px; font-size: 13px; }
    .msg.ok { color: var(--ok); }
    .msg.err { color: var(--danger); }
    label { display: block; margin-bottom: 12px; font-size: 13px; }
    input[type="text"], input[type="password"], input[type="file"] {
      width: 100%;
      margin-top: 6px;
      padding: 8px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-family: inherit;
      background: white;
    }
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
    .linkbtn.secondary, button.secondary { background: #fff; color: var(--primary); }
  </style>
</head>
<body>
<div class="container">
  <div class="panel">
    <div class="row" style="justify-content: space-between;">
      <h1>Admin CSV Import</h1>
      <div class="row">
        <a class="linkbtn secondary" href="settings.php">Back to Settings</a>
        <?php if ($isAdmin): ?>
          <form method="post" action="admin_csv_import.php" style="margin:0;">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="secondary">Log Out</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($notice !== null): ?>
      <p class="msg ok"><?= htmlspecialchars($notice, ENT_QUOTES) ?></p>
      <?php if ($createdLaunchId !== null): ?>
        <p class="msg ok"><a href="index.php?launch=<?= urlencode($createdLaunchId) ?>">Open imported launch in plot</a></p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($error !== null): ?>
      <p class="msg err"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <?php if (!$isConfigured): ?>
      <p class="msg err">Set <code>APP_ADMIN_PASSWORD</code> in <code>.env</code> to enable this page.</p>
    <?php elseif (!$isAdmin): ?>
      <h2>Admin Login</h2>
      <form method="post" action="admin_csv_import.php">
        <input type="hidden" name="action" value="login">
        <label>
          Password
          <input type="password" name="password" required>
        </label>
        <button type="submit">Log In</button>
      </form>
    <?php else: ?>
      <h2>Import Launch From CSV</h2>
      <form method="post" action="admin_csv_import.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="import">
        <label>
          Launch Name
          <input type="text" name="launch_name" value="<?= htmlspecialchars($launchName, ENT_QUOTES) ?>" placeholder="Launch 2025-02-21" required>
        </label>
        <label>
          Station (optional fallback)
          <input type="text" name="station" value="<?= htmlspecialchars($stationInput, ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars((string)($config['aprs_station'] ?: 'N0YD-11'), ENT_QUOTES) ?>">
        </label>
        <label>
          Timezone for CSV timestamps
          <input type="text" name="timezone" value="<?= htmlspecialchars($timezoneInput, ENT_QUOTES) ?>" placeholder="UTC">
        </label>
        <label>
          CSV file
          <input type="file" name="csv_file" accept=".csv,text/csv" required>
        </label>
        <button type="submit">Import CSV Launch</button>
      </form>
      <p class="muted" style="margin-top:12px;">Expected columns: <code>time,lasttime,lat,lng,speed,course,altitude,comment</code>.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
