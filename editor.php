<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
ensure_data_files();
start_admin_session();

if (!is_admin_authenticated()) {
    header('Location: settings.php');
    exit;
}

$records = get_records();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Weather Balloon Data Editor</title>
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
    .container { max-width: 1080px; margin: 0 auto; padding: 20px; }
    .panel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 14px;
      margin-bottom: 16px;
    }
    h1 { margin: 0 0 8px; font-size: 22px; }
    h2 { margin: 0 0 10px; font-size: 16px; }
    .muted, .small { color: var(--muted); font-size: 12px; }
    .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    .row.spread { justify-content: space-between; }
    input[type="datetime-local"], input[type="number"], select {
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
    .linkbtn.secondary { background: #fff; color: var(--primary); }
    button.delete { border-color: var(--danger); background: var(--danger); }
    button:disabled { opacity: 0.5; cursor: not-allowed; }
    .status {
      font-size: 13px;
      padding: 8px;
      border-radius: 6px;
      border: 1px solid var(--border);
      background: #fafafa;
      display: none;
    }
    .status.ok { color: var(--ok); border-color: #bbf7d0; background: #f0fdf4; }
    .status.err { color: var(--danger); border-color: #fecaca; background: #fef2f2; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td {
      border-bottom: 1px solid var(--border);
      text-align: left;
      padding: 8px;
      vertical-align: middle;
    }
  </style>
</head>
<body>
<div class="container">
  <div class="panel">
    <div class="row spread">
      <h1>Data Editor</h1>
      <a class="linkbtn secondary" href="index.php">Back to Live Plot</a>
    </div>
    <div class="row">
      <label>Display timezone
        <select id="tzSelect">
          <option value="America/Chicago">Central Time</option>
          <option value="UTC">UTC</option>
          <option value="local">Browser Local</option>
        </select>
      </label>
      <label>Altitude unit
        <select id="unitSelect">
          <option value="m">Meters</option>
          <option value="ft">Feet</option>
        </select>
      </label>
    </div>
  </div>

  <div class="panel">
    <h2>Add Manual Record</h2>
    <form id="manualForm" class="row">
      <label>Timestamp (Central Time)
        <input name="timestamp" type="datetime-local" required>
      </label>
      <label>Altitude (m)
        <input name="altitude_m" type="number" step="0.1" required>
      </label>
      <button type="submit">Add record</button>
    </form>
    <p class="small">Manual timestamps are interpreted as Central Time (America/Chicago).</p>
    <div id="manualStatus" class="status"></div>
  </div>

  <div class="panel">
    <h2>All Records</h2>
    <table>
      <thead>
      <tr>
        <th>Timestamp</th>
        <th id="altitudeColHeader">Altitude (m)</th>
        <th>Source</th>
        <th>Station</th>
        <th>Action</th>
      </tr>
      </thead>
      <tbody id="dataRows"></tbody>
    </table>
  </div>
</div>

<script>
  const initialRecords = <?= json_encode($records, JSON_UNESCAPED_SLASHES) ?>;
  const manualForm = document.getElementById('manualForm');
  const manualStatus = document.getElementById('manualStatus');
  const dataRows = document.getElementById('dataRows');
  const tzSelect = document.getElementById('tzSelect');
  const unitSelect = document.getElementById('unitSelect');
  const altitudeColHeader = document.getElementById('altitudeColHeader');
  const TZ_STORAGE_KEY = 'wxballoon_tz';
  const UNIT_STORAGE_KEY = 'wxballoon_unit';

  let records = Array.isArray(initialRecords) ? initialRecords : [];

  // Show feedback for add/delete operations in the editor panel.
  function setStatus(msg, ok) {
    manualStatus.textContent = msg;
    manualStatus.className = 'status ' + (ok ? 'ok' : 'err');
    manualStatus.style.display = 'block';
  }

  // Read the currently selected display timezone.
  function getSelectedTz() {
    return tzSelect.value || 'America/Chicago';
  }

  // Read the selected altitude unit.
  function getSelectedUnit() {
    return unitSelect.value === 'ft' ? 'ft' : 'm';
  }

  // Return the short unit label used in headers/cells.
  function altitudeUnitLabel() {
    return getSelectedUnit() === 'ft' ? 'ft' : 'm';
  }

  // Convert altitude from meters into the selected display unit.
  function altitudeInSelectedUnit(metersValue) {
    const meters = Number(metersValue);
    if (!Number.isFinite(meters)) return 0;
    return getSelectedUnit() === 'ft' ? (meters * 3.28084) : meters;
  }

  // Format altitude text with one decimal and unit suffix.
  function formatAltitude(metersValue) {
    return `${altitudeInSelectedUnit(metersValue).toFixed(1)} ${altitudeUnitLabel()}`;
  }

  // Format a unix timestamp in the selected timezone.
  function formatUnix(unixTime) {
    const date = new Date(Number(unixTime) * 1000);
    const tz = getSelectedTz();
    const timeZone = tz === 'local' ? undefined : tz;
    return new Intl.DateTimeFormat('en-US', {
      timeZone,
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit',
      hour12: false, timeZoneName: 'short'
    }).format(date);
  }

  // Call a JSON API action and return parsed payload or throw on failure.
  async function postAction(action, data) {
    const body = new URLSearchParams({ action, ...data });
    const res = await fetch('api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    });
    const raw = await res.text();
    let payload;
    try {
      payload = raw ? JSON.parse(raw) : null;
    } catch {
      throw new Error(`API returned invalid JSON (HTTP ${res.status}).`);
    }
    if (!payload) {
      throw new Error(`API returned an empty response (HTTP ${res.status}).`);
    }
    if (!res.ok || !payload.ok) {
      throw new Error(payload.error || 'Request failed');
    }
    return payload;
  }

  // Refresh records from API status endpoint and redraw the table.
  async function refreshRecords() {
    const res = await fetch('api.php?action=status');
    const raw = await res.text();
    let payload = null;
    try {
      payload = raw ? JSON.parse(raw) : null;
    } catch {
      return;
    }
    if (payload.ok) {
      records = payload.records || [];
      renderTable();
    }
  }

  // Render editor table rows and disable delete controls for protected APRS data.
  function renderTable() {
    altitudeColHeader.textContent = `Altitude (${altitudeUnitLabel()})`;
    const sorted = [...records].sort((a, b) => (b.unix_time || 0) - (a.unix_time || 0));
    if (!sorted.length) {
      dataRows.innerHTML = '<tr><td colspan="5">No records yet.</td></tr>';
      return;
    }

    dataRows.innerHTML = sorted.map((r) => {
      const isProtected = !!r.is_real_aprs || ['aprs', 'imported_aprs_raw'].includes(String(r.source || ''));
      const actionCell = isProtected
        ? '<button class="delete" type="button" disabled title="Protected real APRS record">Protected</button>'
        : `<button class="delete" data-source="${encodeURIComponent(r.source || '')}" data-source-time="${Number(r.source_time_unix || 0)}">Delete</button>`;
      return `
      <tr>
        <td>${formatUnix(r.unix_time)}</td>
        <td>${formatAltitude(r.altitude_m)}</td>
        <td>${r.source || ''}</td>
        <td>${r.station || ''}</td>
        <td>
          ${actionCell}
        </td>
      </tr>
    `;
    }).join('');
  }

  manualForm.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const fd = new FormData(manualForm);
    try {
      const payload = await postAction('add_manual', {
        timestamp: String(fd.get('timestamp') || ''),
        altitude_m: String(fd.get('altitude_m') || '')
      });
      records = payload.records || records;
      renderTable();
      manualForm.reset();
      setStatus('Manual record added.', true);
    } catch (err) {
      setStatus(err.message, false);
    }
  });

  dataRows.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('button.delete');
    if (!btn) return;

    const source = decodeURIComponent(btn.dataset.source || '');
    const sourceTimeUnix = btn.dataset.sourceTime || '';
    if (!source || !sourceTimeUnix) return;

    if (!confirm('Delete this datapoint? Protected real APRS datapoints cannot be deleted from this screen.')) return;

    btn.disabled = true;
    try {
      const payload = await postAction('delete_record', {
        source,
        source_time_unix: sourceTimeUnix
      });
      records = payload.records || records;
      renderTable();
      setStatus('Datapoint deleted.', true);
    } catch (err) {
      setStatus(err.message, false);
      btn.disabled = false;
    }
  });

  tzSelect.addEventListener('change', () => {
    localStorage.setItem(TZ_STORAGE_KEY, getSelectedTz());
    renderTable();
  });

  unitSelect.addEventListener('change', () => {
    localStorage.setItem(UNIT_STORAGE_KEY, getSelectedUnit());
    renderTable();
  });

  // Restore persisted timezone preference on page load.
  (function initTz() {
    const saved = localStorage.getItem(TZ_STORAGE_KEY);
    if (saved && ['America/Chicago', 'UTC', 'local'].includes(saved)) {
      tzSelect.value = saved;
    } else {
      tzSelect.value = 'America/Chicago';
    }
  })();

  // Restore persisted altitude-unit preference on page load.
  (function initUnit() {
    const saved = localStorage.getItem(UNIT_STORAGE_KEY);
    if (saved && ['m', 'ft'].includes(saved)) {
      unitSelect.value = saved;
    } else {
      unitSelect.value = 'm';
    }
  })();

  renderTable();
</script>
</body>
</html>
