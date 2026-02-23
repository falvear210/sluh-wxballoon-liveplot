<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
ensure_data_files();
$config = app_config();
$state = get_state();
$records = get_records();
$launches = list_launches();
$requestedLaunch = strtolower(trim((string)($_GET['launch'] ?? 'current')));
$selectedLaunch = 'current';
$selectedLaunchLabel = 'Current';

if ($requestedLaunch !== '' && $requestedLaunch !== 'current') {
    $normalizedLaunch = normalize_launch_id($requestedLaunch);
    if ($normalizedLaunch !== null) {
        $launchRecords = get_launch_records($normalizedLaunch);
        if (is_array($launchRecords)) {
            $records = $launchRecords;
            $selectedLaunch = $normalizedLaunch;
            $selectedLaunchLabel = ucwords(str_replace(['_', '-'], ' ', $normalizedLaunch));
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Weather Balloon Live Plot</title>
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
    .muted { color: var(--muted); font-size: 13px; }
    .small { font-size: 12px; color: var(--muted); }
    .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    .row.spread { justify-content: space-between; }
    select {
      padding: 7px;
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
    button.secondary, .linkbtn.secondary { background: #fff; color: var(--primary); }
    button:disabled { opacity: 0.5; cursor: not-allowed; }
    .chart {
      width: 100%;
      min-height: 340px;
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
      background: #fff;
    }
    .statusGrid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 10px;
    }
    .statCard {
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 10px;
      background: #fafafa;
    }
    .statLabel {
      font-size: 12px;
      color: var(--muted);
      margin-bottom: 4px;
    }
    .statValue {
      font-size: 20px;
      font-weight: 700;
      line-height: 1.2;
    }
    .statSubtle {
      font-size: 12px;
      color: var(--muted);
      margin-top: 4px;
    }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td {
      border-bottom: 1px solid var(--border);
      text-align: left;
      padding: 8px;
    }
    #locationMap {
      height: 420px;
      min-height: 420px;
    }
  </style>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
  <script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body>
<div class="container">
  <div class="panel">
    <div class="row spread">
      <h1>SLUH Weather Balloon Altitude Tracker</h1>
    </div>
    <p class="muted">Tracks altitude vs. time from APRS station <strong><?= htmlspecialchars($config['aprs_station'] !== '' ? $config['aprs_station'] : '(not configured)', ENT_QUOTES) ?></strong>.</p>
    <div class="row">
      <label>Launch
        <select id="launchSelect">
          <option value="current" <?= $selectedLaunch === 'current' ? 'selected' : '' ?>>Current (live)</option>
          <?php foreach ($launches as $launch): ?>
            <option value="<?= htmlspecialchars((string)$launch['id'], ENT_QUOTES) ?>" <?= $selectedLaunch === (string)$launch['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars(ucwords((string)$launch['label']) . ' (' . (int)$launch['record_count'] . ')', ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
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
      <span id="captureState" class="small"></span>
    </div>
    <p class="small">APRS data source credit: <a href="https://aprs.fi" target="_blank" rel="noreferrer">aprs.fi</a>. This app fetches only when capture is enabled and uses short-term caching to reduce API load.</p>
  </div>

  <div id="currentLaunchPanel" class="panel">
    <h2>Current Flight Status</h2>
    <div class="statusGrid">
      <div class="statCard">
        <div class="statLabel">Flight time (from first datapoint)</div>
        <div id="flightTimeValue" class="statValue">--:--:--</div>
      </div>
      <div class="statCard">
        <div class="statLabel">Detected burst</div>
        <div id="burstStatusValue" class="statValue">No</div>
        <div id="burstStatusDetail" class="statSubtle"></div>
      </div>
    </div>
  </div>

  <div class="panel">
    <h2>Altitude vs Time</h2>
    <div id="altitudePlot" class="chart"></div>
    <div class="row" style="margin-top:8px;">
      <button id="clearSelectionBtn" class="secondary" type="button">Clear selection</button>
      <span id="ascentStats" class="small">Select 2+ points on the altitude plot to calculate ascent rate.</span>
    </div>
  </div>

  <div class="panel">
    <h2>Flight Path Map</h2>
    <div id="locationMap" class="chart"></div>
    <p id="mapStatus" class="small"></p>
  </div>

  <div class="panel">
    <h2>Recorded Data</h2>
    <table>
      <thead>
      <tr>
        <th>Timestamp</th>
        <th id="altitudeColHeader">Altitude (m)</th>
        <th id="rateColHeader">Rate from previous (m/s)</th>
        <th id="rateAvgColHeader">Avg last 5 (m/s)</th>
        <th>Source</th>
      </tr>
      </thead>
      <tbody id="dataRows"></tbody>
    </table>
  </div>
</div>

<script>
  const initialState = <?= json_encode($state, JSON_UNESCAPED_SLASHES) ?>;
  const initialRecords = <?= json_encode($records, JSON_UNESCAPED_SLASHES) ?>;
  const selectedLaunch = <?= json_encode($selectedLaunch, JSON_UNESCAPED_SLASHES) ?>;
  const selectedLaunchLabel = <?= json_encode($selectedLaunchLabel, JSON_UNESCAPED_SLASHES) ?>;

  const launchSelect = document.getElementById('launchSelect');
  const captureState = document.getElementById('captureState');
  const currentLaunchPanel = document.getElementById('currentLaunchPanel');
  const flightTimeValue = document.getElementById('flightTimeValue');
  const burstStatusValue = document.getElementById('burstStatusValue');
  const burstStatusDetail = document.getElementById('burstStatusDetail');
  const dataRows = document.getElementById('dataRows');
  const tzSelect = document.getElementById('tzSelect');
  const unitSelect = document.getElementById('unitSelect');
  const altitudeColHeader = document.getElementById('altitudeColHeader');
  const rateColHeader = document.getElementById('rateColHeader');
  const rateAvgColHeader = document.getElementById('rateAvgColHeader');
  const ascentStats = document.getElementById('ascentStats');
  const clearSelectionBtn = document.getElementById('clearSelectionBtn');

  let records = Array.isArray(initialRecords) ? initialRecords : [];
  let state = initialState || {};
  let captureTimer = null;
  let flightMap = null;
  let flightLayer = null;
  let plotSortedRecords = [];
  let plotSelectionWired = false;
  const TZ_STORAGE_KEY = 'wxballoon_tz';
  const UNIT_STORAGE_KEY = 'wxballoon_unit';
  const mapStatus = document.getElementById('mapStatus');
  const isCurrentLaunch = selectedLaunch === 'current';

  function getSelectedTz() {
    return tzSelect.value || 'America/Chicago';
  }

  function getTzLabel() {
    const tz = getSelectedTz();
    if (tz === 'America/Chicago') return 'Central Time';
    if (tz === 'UTC') return 'UTC';
    return 'Browser Local';
  }

  function getSelectedUnit() {
    return unitSelect.value === 'ft' ? 'ft' : 'm';
  }

  function altitudeUnitLabel() {
    return getSelectedUnit() === 'ft' ? 'ft' : 'm';
  }

  function altitudeInSelectedUnit(metersValue) {
    const meters = Number(metersValue);
    if (!Number.isFinite(meters)) return 0;
    return getSelectedUnit() === 'ft' ? (meters * 3.28084) : meters;
  }

  function formatAltitude(metersValue) {
    return `${altitudeInSelectedUnit(metersValue).toFixed(1)} ${altitudeUnitLabel()}`;
  }

  function setAscentStatsText(msg) {
    ascentStats.textContent = msg;
  }

  function resetAscentStats() {
    setAscentStatsText('Select 2+ points on the altitude plot to calculate ascent rate.');
  }

  function updateAscentRateFromPointIndices(pointIndices) {
    const uniqueSorted = [...new Set(pointIndices)]
      .filter((idx) => Number.isInteger(idx) && idx >= 0 && idx < plotSortedRecords.length)
      .sort((a, b) => a - b);

    if (uniqueSorted.length < 2) {
      resetAscentStats();
      return;
    }

    const start = plotSortedRecords[uniqueSorted[0]];
    const end = plotSortedRecords[uniqueSorted[uniqueSorted.length - 1]];
    const dtSeconds = Number(end.unix_time) - Number(start.unix_time);
    if (dtSeconds <= 0) {
      setAscentStatsText('Selected range has no time delta.');
      return;
    }

    const altStart = altitudeInSelectedUnit(start.altitude_m);
    const altEnd = altitudeInSelectedUnit(end.altitude_m);
    const delta = altEnd - altStart;
    const ratePerSecond = delta / dtSeconds;

    setAscentStatsText(
      `Range: ${formatUnix(start.unix_time)} -> ${formatUnix(end.unix_time)} | Delta: ${delta.toFixed(1)} ${altitudeUnitLabel()} | Rate: ${ratePerSecond.toFixed(3)} ${altitudeUnitLabel()}/s`
    );
  }

  function wirePlotSelectionHandlers() {
    if (plotSelectionWired) return;
    const container = document.getElementById('altitudePlot');

    container.on('plotly_selected', (eventData) => {
      if (!eventData || !Array.isArray(eventData.points)) {
        resetAscentStats();
        return;
      }

      const indices = eventData.points.map((p) => Number(p.pointIndex));
      updateAscentRateFromPointIndices(indices);
    });

    container.on('plotly_deselect', () => {
      resetAscentStats();
    });

    plotSelectionWired = true;
  }

  function formatUnix(unixTime) {
    if (!unixTime) return '';
    const date = new Date(Number(unixTime) * 1000);
    const tz = getSelectedTz();
    const timeZone = tz === 'local' ? undefined : tz;
    return new Intl.DateTimeFormat('en-US', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: false,
      timeZoneName: 'short'
    }).format(date);
  }

  function formatTimeOnly(unixTime) {
    if (!unixTime) return '';
    const date = new Date(Number(unixTime) * 1000);
    const tz = getSelectedTz();
    const timeZone = tz === 'local' ? undefined : tz;
    return new Intl.DateTimeFormat('en-US', {
      timeZone,
      hour: '2-digit',
      minute: '2-digit',
      // second: '2-digit',
      hour12: false
    }).format(date);
  }

  function drawAltitudePlot() {
    const container = document.getElementById('altitudePlot');
    const sorted = [...records].sort((a, b) => (a.unix_time || 0) - (b.unix_time || 0));
    plotSortedRecords = sorted;

    if (!sorted.length) {
      container.innerHTML = '<div class="small" style="padding:12px;">No records yet.</div>';
      resetAscentStats();
      return;
    }

    const x = sorted.map((r) => formatTimeOnly(r.unix_time));
    const y = sorted.map((r) => altitudeInSelectedUnit(r.altitude_m));

    const trace = {
      x,
      y,
      type: 'scatter',
      mode: 'lines+markers',
      line: { color: '#0ea5a3', width: 3 },
      marker: { size: 6 },
      selected: { marker: { color: '#b91c1c', size: 8 } },
      unselected: { marker: { opacity: 0.45 } },
      hovertemplate: `%{x}<br>Altitude: %{y:.1f} ${altitudeUnitLabel()}<extra></extra>`
    };

    const layout = {
      margin: { l: 56, r: 18, t: 18, b: 60 },
      xaxis: { title: `Time (${getTzLabel()})`, type: 'category' },
      yaxis: { title: `Altitude (${altitudeUnitLabel()})` },
      dragmode: 'select',
      plot_bgcolor: '#ffffff',
      paper_bgcolor: '#ffffff'
    };

    Plotly.react(container, [trace], layout, { responsive: true, displaylogo: false });
    wirePlotSelectionHandlers();
    resetAscentStats();
  }

  function drawLocationMap() {
    const container = document.getElementById('locationMap');
    const withCoords = [...records]
      .filter((r) => Number.isFinite(Number(r.latitude)) && Number.isFinite(Number(r.longitude)))
      .sort((a, b) => (a.unix_time || 0) - (b.unix_time || 0));

    if (!flightMap) {
      flightMap = L.map(container, { preferCanvas: true });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(flightMap);
      flightLayer = L.layerGroup().addTo(flightMap);
      flightMap.setView([38.62, -90.27], 8);
    }

    flightLayer.clearLayers();

    if (!withCoords.length) {
      mapStatus.textContent = 'No latitude/longitude data yet.';
      return;
    }

    const points = withCoords.map((r) => [Number(r.latitude), Number(r.longitude)]);
    const first = withCoords[0];
    const latest = withCoords[withCoords.length - 1];

    L.polyline(points, { color: '#0f766e', weight: 3, opacity: 0.9 }).addTo(flightLayer);

    L.circleMarker([Number(first.latitude), Number(first.longitude)], {
      radius: 5,
      color: '#1d4ed8',
      fillColor: '#2563eb',
      fillOpacity: 0.9
    }).bindPopup(`Start<br>${formatUnix(first.unix_time)}`).addTo(flightLayer);

    L.circleMarker([Number(latest.latitude), Number(latest.longitude)], {
      radius: 6,
      color: '#b91c1c',
      fillColor: '#ef4444',
      fillOpacity: 0.95
    }).bindPopup(`Latest<br>${formatUnix(latest.unix_time)}<br>${formatAltitude(latest.altitude_m)}`).addTo(flightLayer);

    const bounds = L.latLngBounds(points.map((p) => L.latLng(p[0], p[1])));
    if (bounds.isValid()) {
      if (points.length === 1) {
        flightMap.setView(points[0], 13);
      } else {
        flightMap.fitBounds(bounds, { padding: [24, 24], maxZoom: 13 });
      }
    }

    mapStatus.textContent = `Showing ${withCoords.length} path points.`;
  }

  function renderTable() {
    const asc = [...records]
      .filter((r) => Number.isFinite(Number(r.unix_time)) && Number.isFinite(Number(r.altitude_m)))
      .sort((a, b) => Number(a.unix_time) - Number(b.unix_time));

    if (!asc.length) {
      dataRows.innerHTML = '<tr><td colspan="5">No records yet.</td></tr>';
      return;
    }

    const rows = [];
    for (let i = 0; i < asc.length; i++) {
      const current = asc[i];
      let ratePrev = null;
      if (i > 0) {
        const prev = asc[i - 1];
        const dt = Number(current.unix_time) - Number(prev.unix_time);
        if (dt > 0) {
          const dAlt = altitudeInSelectedUnit(current.altitude_m) - altitudeInSelectedUnit(prev.altitude_m);
          ratePrev = dAlt / dt;
        }
      }

      const recentRates = [];
      for (let j = Math.max(1, i - 4); j <= i; j++) {
        const p = asc[j - 1];
        const c = asc[j];
        const dt = Number(c.unix_time) - Number(p.unix_time);
        if (dt <= 0) continue;
        const dAlt = altitudeInSelectedUnit(c.altitude_m) - altitudeInSelectedUnit(p.altitude_m);
        recentRates.push(dAlt / dt);
      }

      const avg5 = recentRates.length
        ? recentRates.reduce((sum, v) => sum + v, 0) / recentRates.length
        : null;

      rows.push({
        record: current,
        ratePrev,
        avg5
      });
    }

    rows.reverse();
    dataRows.innerHTML = rows.map((row) => `
      <tr>
        <td>${formatUnix(row.record.unix_time)}</td>
        <td>${formatAltitude(row.record.altitude_m)}</td>
        <td>${Number.isFinite(row.ratePrev) ? `${row.ratePrev.toFixed(3)} ${altitudeUnitLabel()}/s` : '--'}</td>
        <td>${Number.isFinite(row.avg5) ? `${row.avg5.toFixed(3)} ${altitudeUnitLabel()}/s` : '--'}</td>
        <td>${row.record.source || ''}</td>
      </tr>
    `).join('');
  }

  function renderCaptureState() {
    if (!isCurrentLaunch) {
      captureState.textContent = `Viewing launch: ${selectedLaunchLabel}`;
      return;
    }

    const enabled = !!state.capture_enabled;
    const browserPollingEnabled = !!state.browser_polling_enabled;
    const lastOk = state.last_capture_success_unix ? formatUnix(state.last_capture_success_unix) : 'never';
    const err = state.last_error ? ` | last error: ${state.last_error}` : '';
    const mode = browserPollingEnabled ? 'browser polling on' : 'cron mode (browser polling off)';
    captureState.textContent = `Capture ${enabled ? 'enabled' : 'disabled'} (${mode}; change in Settings) | last success: ${lastOk}${err}`;
  }

  function formatDuration(totalSeconds) {
    const sec = Math.max(0, Number(totalSeconds) || 0);
    const hours = Math.floor(sec / 3600);
    const minutes = Math.floor((sec % 3600) / 60);
    const seconds = sec % 60;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  function computeFlightMetrics() {
    const sorted = [...records]
      .filter((r) => Number.isFinite(Number(r.unix_time)) && Number.isFinite(Number(r.altitude_m)))
      .sort((a, b) => Number(a.unix_time) - Number(b.unix_time));

    if (!sorted.length) {
      return {
        hasData: false,
        flightSeconds: 0,
        burstDetected: false,
        burstUnix: null
      };
    }

    const firstUnix = Number(sorted[0].unix_time);
    const lastUnix = Number(sorted[sorted.length - 1].unix_time);
    let burstUnix = null;
    let prevTrend = 0; // 1 = rising, -1 = falling

    for (let i = 1; i < sorted.length; i++) {
      const prevAlt = Number(sorted[i - 1].altitude_m);
      const currAlt = Number(sorted[i].altitude_m);
      const delta = currAlt - prevAlt;
      const trend = delta > 0 ? 1 : (delta < 0 ? -1 : 0);

      if (trend === 0) continue;
      if (prevTrend > 0 && trend < 0) {
        burstUnix = Number(sorted[i].unix_time);
        break;
      }
      prevTrend = trend;
    }

    return {
      hasData: true,
      flightSeconds: Math.max(0, lastUnix - firstUnix),
      burstDetected: burstUnix !== null,
      burstUnix
    };
  }

  function renderCurrentLaunchStatus() {
    if (!isCurrentLaunch) {
      currentLaunchPanel.style.display = 'none';
      return;
    }

    currentLaunchPanel.style.display = '';
    const metrics = computeFlightMetrics();

    if (!metrics.hasData) {
      flightTimeValue.textContent = '--:--:--';
      burstStatusValue.textContent = 'No data';
      burstStatusDetail.textContent = 'Waiting for datapoints.';
      return;
    }

    flightTimeValue.textContent = formatDuration(metrics.flightSeconds);
    if (metrics.burstDetected) {
      burstStatusValue.textContent = 'Yes';
      burstStatusDetail.textContent = `First detected at ${formatUnix(metrics.burstUnix)}.`;
    } else {
      burstStatusValue.textContent = 'No';
      burstStatusDetail.textContent = 'Altitude has not switched from rising to falling yet.';
    }
  }

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
      throw new Error(payload.error || payload.capture?.message || 'Request failed');
    }
    return payload;
  }

  async function refreshStatus() {
    if (!isCurrentLaunch) return;
    if (!state.browser_polling_enabled) return;

    const res = await fetch('api.php?action=status');
    const raw = await res.text();
    let payload = null;
    try {
      payload = raw ? JSON.parse(raw) : null;
    } catch {
      return;
    }
    if (payload.ok) {
      state = payload.state || state;
      records = payload.records || records;
      renderAll();
    }
  }

  async function captureTick() {
    if (!isCurrentLaunch) return;
    if (!state.browser_polling_enabled) return;
    if (!state.capture_enabled) return;
    try {
      const payload = await postAction('capture_now', {});
      state = payload.state;
      records = payload.records;
      renderAll();
    } catch {
      await refreshStatus();
    }
  }

  function scheduleCapture() {
    if (captureTimer) {
      clearInterval(captureTimer);
      captureTimer = null;
    }

    if (isCurrentLaunch && state.capture_enabled && state.browser_polling_enabled) {
      captureTimer = setInterval(captureTick, 60000);
    }
  }

  function renderAll() {
    altitudeColHeader.textContent = `Altitude (${altitudeUnitLabel()})`;
    rateColHeader.textContent = `Rate from previous (${altitudeUnitLabel()}/s)`;
    rateAvgColHeader.textContent = `Avg last 5 (${altitudeUnitLabel()}/s)`;
    renderCaptureState();
    renderCurrentLaunchStatus();
    renderTable();
    drawAltitudePlot();
    drawLocationMap();
    if (flightMap) {
      setTimeout(() => flightMap.invalidateSize(), 0);
    }
  }

  launchSelect.addEventListener('change', () => {
    const selected = launchSelect.value || 'current';
    const url = new URL(window.location.href);
    if (selected === 'current') {
      url.searchParams.delete('launch');
    } else {
      url.searchParams.set('launch', selected);
    }
    window.location.href = url.toString();
  });

  tzSelect.addEventListener('change', () => {
    localStorage.setItem(TZ_STORAGE_KEY, getSelectedTz());
    renderAll();
  });

  unitSelect.addEventListener('change', () => {
    localStorage.setItem(UNIT_STORAGE_KEY, getSelectedUnit());
    renderAll();
  });

  clearSelectionBtn.addEventListener('click', () => {
    const container = document.getElementById('altitudePlot');
    Plotly.restyle(container, { selectedpoints: [null] }, [0]);
    resetAscentStats();
  });

  (function initTz() {
    const saved = localStorage.getItem(TZ_STORAGE_KEY);
    if (saved && ['America/Chicago', 'UTC', 'local'].includes(saved)) {
      tzSelect.value = saved;
    } else {
      tzSelect.value = 'America/Chicago';
    }
  })();

  (function initUnit() {
    const saved = localStorage.getItem(UNIT_STORAGE_KEY);
    if (saved && ['m', 'ft'].includes(saved)) {
      unitSelect.value = saved;
    } else {
      unitSelect.value = 'm';
    }
  })();

  renderAll();
  scheduleCapture();
</script>
</body>
</html>
