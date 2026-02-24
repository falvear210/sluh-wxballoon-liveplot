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
  <title>Weather Balloon Compact Dashboard</title>
  <style>
    :root {
      --bg: #edf2f7;
      --panel: #ffffff;
      --text: #1f2937;
      --muted: #6b7280;
      --primary: #0f766e;
      --border: #dbe2ea;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
      margin: 0;
      font-family: Menlo, Consolas, monospace;
      background: radial-gradient(circle at 20% -5%, #dbeafe 0%, var(--bg) 36%);
      color: var(--text);
    }
    .shell {
      height: 100vh;
      padding: 12px;
      display: grid;
      grid-template-columns: 340px 1fr;
      gap: 12px;
      overflow: hidden;
    }
    .panel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px;
      min-height: 0;
    }
    .leftCol {
      display: grid;
      grid-template-rows: auto 1fr;
      gap: 12px;
      min-height: 0;
    }
    .rightCol {
      min-height: 0;
      display: grid;
      grid-template-rows: 1fr 1fr;
      gap: 12px;
    }
    h1 { margin: 0; font-size: 20px; line-height: 1.2; }
    h2 { margin: 0 0 8px; font-size: 14px; }
    .small { font-size: 12px; color: var(--muted); }
    .controls {
      display: grid;
      gap: 8px;
      grid-template-columns: 1fr 1fr;
    }
    .controls label {
      display: grid;
      gap: 4px;
      font-size: 12px;
      margin: 0;
    }
    .controls .span2 {
      grid-column: 1 / -1;
    }
    select, button, .linkbtn {
      font-family: inherit;
      font-size: 12px;
      border-radius: 6px;
      border: 1px solid var(--border);
      padding: 7px 8px;
    }
    select { background: #fff; color: var(--text); }
    button, .linkbtn {
      border-color: var(--primary);
      background: var(--primary);
      color: #fff;
      cursor: pointer;
      text-decoration: none;
      text-align: center;
    }
    .linkbtn.secondary, button.secondary {
      background: #fff;
      color: var(--primary);
    }
    .buttonRow {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }
    .simBadge {
      display: none;
      width: max-content;
      font-size: 12px;
      font-weight: 700;
      color: #7f1d1d;
      background: #fee2e2;
      border: 1px solid #fecaca;
      border-radius: 999px;
      padding: 3px 8px;
    }
    .simBadge.on { display: inline-block; }
    .statusGrid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 8px;
    }
    .statCard {
      border: 1px solid var(--border);
      border-radius: 8px;
      background: #f8fafc;
      padding: 9px;
    }
    .statLabel {
      font-size: 11px;
      color: var(--muted);
      margin-bottom: 3px;
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
    .chartWrap {
      height: 100%;
      display: grid;
      grid-template-rows: auto 1fr;
      min-height: 0;
    }
    .chart {
      width: 100%;
      height: 100%;
      min-height: 0;
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
      background: #fff;
    }
    #locationMap { min-height: 0; }
    @media (max-width: 1000px) {
      .shell {
        height: auto;
        min-height: 100vh;
        overflow: auto;
        grid-template-columns: 1fr;
        grid-template-rows: auto auto;
      }
      .controls {
        grid-template-columns: 1fr;
      }
      .controls .span2 {
        grid-column: auto;
      }
      .rightCol { grid-template-rows: minmax(280px, 42vh) minmax(280px, 42vh); }
    }
  </style>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
  <script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body>
<div class="shell">
  <aside class="leftCol">
    <div class="panel">
      <h1>Compact Live Dashboard</h1>
      <p class="small" style="margin:6px 0 8px;">Station: <strong><?= htmlspecialchars($config['aprs_station'] !== '' ? $config['aprs_station'] : '(not configured)', ENT_QUOTES) ?></strong></p>
      <div class="controls">
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
        <div id="captureState" class="small span2"></div>
        <span id="simulationBadge" class="simBadge span2">SIMULATION MODE ACTIVE</span>
        <div class="buttonRow span2">
          <a class="linkbtn secondary" href="settings.php">Settings</a>
          <a class="linkbtn secondary" href="index.php">Classic View</a>
        </div>
      </div>
    </div>

    <div class="panel" style="overflow:auto;">
      <h2>Current Flight Status</h2>
      <div class="statusGrid">
        <div class="statCard">
          <div class="statLabel">Flight time (from first datapoint)</div>
          <div id="flightTimeValue" class="statValue">--:--:--</div>
          <div id="flightRateLastValue" class="statSubtle">Rate (last 2): --</div>
          <div id="flightRateAvgValue" class="statSubtle">Rate (avg last 5): --</div>
        </div>
        <div class="statCard">
          <div class="statLabel">Detected burst</div>
          <div id="burstStatusValue" class="statValue">No</div>
          <div id="burstStatusDetail" class="statSubtle"></div>
        </div>
        <div class="statCard">
          <div class="statLabel">Altitude-based stage</div>
          <div id="flightStageValue" class="statValue">--</div>
          <div id="flightStageRange" class="statSubtle"></div>
          <p id="flightStageDescription" class="statSubtle"></p>
        </div>
      </div>
    </div>
  </aside>

  <main class="rightCol">
    <section class="panel chartWrap">
      <h2>Flight Path Map</h2>
      <div id="locationMap" class="chart"></div>
    </section>
    <section class="panel chartWrap">
      <h2>Altitude vs Time</h2>
      <div id="altitudePlot" class="chart"></div>
    </section>
  </main>
</div>

<script>
  const initialState = <?= json_encode($state, JSON_UNESCAPED_SLASHES) ?>;
  const initialRecords = <?= json_encode($records, JSON_UNESCAPED_SLASHES) ?>;
  const selectedLaunch = <?= json_encode($selectedLaunch, JSON_UNESCAPED_SLASHES) ?>;
  const selectedLaunchLabel = <?= json_encode($selectedLaunchLabel, JSON_UNESCAPED_SLASHES) ?>;
  const launchSelect = document.getElementById('launchSelect');
  const tzSelect = document.getElementById('tzSelect');
  const unitSelect = document.getElementById('unitSelect');
  const captureState = document.getElementById('captureState');
  const simulationBadge = document.getElementById('simulationBadge');
  const flightTimeValue = document.getElementById('flightTimeValue');
  const flightRateLastValue = document.getElementById('flightRateLastValue');
  const flightRateAvgValue = document.getElementById('flightRateAvgValue');
  const burstStatusValue = document.getElementById('burstStatusValue');
  const burstStatusDetail = document.getElementById('burstStatusDetail');
  const flightStageValue = document.getElementById('flightStageValue');
  const flightStageRange = document.getElementById('flightStageRange');
  const flightStageDescription = document.getElementById('flightStageDescription');
  const TZ_STORAGE_KEY = 'wxballoon_tz';
  const UNIT_STORAGE_KEY = 'wxballoon_unit';
  const LIVE_CAPTURE_INTERVAL_MS = 60000;
  const METERS_TO_FEET = 3.28084;
  const isCurrentLaunch = selectedLaunch === 'current';

  const FLIGHT_STAGES = {
    preLaunch: { title: 'Pre-Launch', range: '< 600 ft', description: 'Balloon is still on/near the ground before sustained climb.' },
    initialAscent: { title: 'Initial Ascent', range: '600 - 10,000 ft', description: 'Early climb through lower atmosphere; validate payload health and trajectory.' },
    troposphericAscent: { title: 'Tropospheric Ascent', range: '10,000 - 40,000 ft', description: 'Strong ascent in troposphere; track rate and heading drift.' },
    stratosphericAscent: { title: 'Stratospheric Ascent', range: '40,000 - 95,000 ft', description: 'Approaching peak altitude where balloon expansion accelerates.' },
    nearPeakAltitude: { title: 'Near Peak Altitude', range: '> 95,000 ft', description: 'Near burst region; watch for ascent slowdown and transition.' },
    burstAndThinAirFreefall: { title: 'Burst & Thin-Air Freefall', range: 'Burst to 60,000 ft', description: 'Descent has begun after burst; initial fall can be steep.' },
    parachuteDescent: { title: 'Parachute Descent', range: '60,000 - 5,000 ft', description: 'Parachute-dominated descent; monitor landing corridor.' },
    finalApproachLanding: { title: 'Final Approach & Landing', range: '5,000 ft to ground', description: 'Low-altitude recovery phase and touchdown approach.' }
  };

  let records = Array.isArray(initialRecords) ? initialRecords : [];
  let state = initialState || {};
  let captureTimer = null;
  let flightMap = null;
  let flightLayer = null;

  // Read the currently selected display timezone.
  function getSelectedTz() {
    return tzSelect.value || 'America/Chicago';
  }

  // Return simulation polling cadence constrained to a safe range.
  function getSimulationPollSeconds() {
    const raw = Number(state.simulation_poll_seconds);
    if (!Number.isFinite(raw)) return 5;
    return Math.min(300, Math.max(1, Math.floor(raw)));
  }

  // Convert timezone selection to a friendly label used in chart text.
  function getTzLabel() {
    const tz = getSelectedTz();
    if (tz === 'America/Chicago') return 'Central Time';
    if (tz === 'UTC') return 'UTC';
    return 'Browser Local';
  }

  // Read the selected altitude unit.
  function getSelectedUnit() {
    return unitSelect.value === 'ft' ? 'ft' : 'm';
  }

  // Return short unit label used in UI values.
  function altitudeUnitLabel() {
    return getSelectedUnit() === 'ft' ? 'ft' : 'm';
  }

  // Convert altitude from meters into the selected display unit.
  function altitudeInSelectedUnit(metersValue) {
    const meters = Number(metersValue);
    if (!Number.isFinite(meters)) return 0;
    return getSelectedUnit() === 'ft' ? (meters * METERS_TO_FEET) : meters;
  }

  // Format unix timestamp in the selected timezone.
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

  // Format only time component for chart x-axis labels.
  function formatTimeOnly(unixTime) {
    if (!unixTime) return '';
    const date = new Date(Number(unixTime) * 1000);
    const tz = getSelectedTz();
    const timeZone = tz === 'local' ? undefined : tz;
    return new Intl.DateTimeFormat('en-US', {
      timeZone,
      hour: '2-digit',
      minute: '2-digit',
      hour12: false
    }).format(date);
  }

  // Format elapsed seconds as HH:MM:SS.
  function formatDuration(totalSeconds) {
    const sec = Math.max(0, Number(totalSeconds) || 0);
    const hours = Math.floor(sec / 3600);
    const minutes = Math.floor((sec % 3600) / 60);
    const seconds = sec % 60;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  // Format signed vertical-rate text with direction context.
  function formatVerticalRate(ratePerSecond) {
    if (!Number.isFinite(ratePerSecond)) return '--';
    const direction = ratePerSecond > 0 ? 'ascent' : (ratePerSecond < 0 ? 'descent' : 'level');
    return `${ratePerSecond.toFixed(3)} ${altitudeUnitLabel()}/s (${direction})`;
  }

  // Convert meters to feet for stage threshold calculations.
  function metersToFeet(metersValue) {
    const meters = Number(metersValue);
    if (!Number.isFinite(meters)) return 0;
    return meters * METERS_TO_FEET;
  }

  // Compute latest instantaneous and rolling-average vertical rates.
  function computeRecentVerticalRates(sorted) {
    if (!Array.isArray(sorted) || sorted.length < 2) {
      return { lastRate: null, avg5: null };
    }
    const rates = [];
    for (let i = 1; i < sorted.length; i++) {
      const prev = sorted[i - 1];
      const curr = sorted[i];
      const dt = Number(curr.unix_time) - Number(prev.unix_time);
      if (dt <= 0) continue;
      const dAlt = altitudeInSelectedUnit(curr.altitude_m) - altitudeInSelectedUnit(prev.altitude_m);
      rates.push(dAlt / dt);
    }
    if (!rates.length) return { lastRate: null, avg5: null };
    const lastRate = rates[rates.length - 1];
    const recent = rates.slice(-5);
    const avg5 = recent.reduce((sum, v) => sum + v, 0) / recent.length;
    return { lastRate, avg5 };
  }

  // Map current flight metrics to a flight-stage definition.
  function getFlightStage(metrics) {
    if (!metrics.hasData) return null;
    const altitudeFeet = metrics.latestAltitudeFt;
    if (metrics.burstDetected) {
      if (altitudeFeet > 60000) return FLIGHT_STAGES.burstAndThinAirFreefall;
      if (altitudeFeet > 5000) return FLIGHT_STAGES.parachuteDescent;
      return FLIGHT_STAGES.finalApproachLanding;
    }
    if (altitudeFeet < 600) return FLIGHT_STAGES.preLaunch;
    if (altitudeFeet < 10000) return FLIGHT_STAGES.initialAscent;
    if (altitudeFeet < 40000) return FLIGHT_STAGES.troposphericAscent;
    if (altitudeFeet < 95000) return FLIGHT_STAGES.stratosphericAscent;
    return FLIGHT_STAGES.nearPeakAltitude;
  }

  // Derive flight metrics (elapsed time, burst, rates) from records.
  function computeFlightMetrics() {
    const sorted = [...records]
      .filter((r) => Number.isFinite(Number(r.unix_time)) && Number.isFinite(Number(r.altitude_m)))
      .sort((a, b) => Number(a.unix_time) - Number(b.unix_time));

    if (!sorted.length) {
      return {
        hasData: false,
        flightSeconds: 0,
        burstDetected: false,
        burstUnix: null,
        latestAltitudeFt: null,
        lastRate: null,
        avgRate5: null
      };
    }

    const firstUnix = Number(sorted[0].unix_time);
    const lastUnix = Number(sorted[sorted.length - 1].unix_time);
    let burstUnix = null;
    let sawAscent = false;
    let consecutiveDescentIntervals = 0;

    for (let i = 1; i < sorted.length; i++) {
      const prevAlt = Number(sorted[i - 1].altitude_m);
      const currAlt = Number(sorted[i].altitude_m);
      const delta = currAlt - prevAlt;
      const trend = delta > 0 ? 1 : (delta < 0 ? -1 : 0);
      if (trend > 0) {
        sawAscent = true;
        consecutiveDescentIntervals = 0;
        continue;
      }
      if (trend < 0) {
        if (!sawAscent) continue;
        consecutiveDescentIntervals++;
        if (consecutiveDescentIntervals >= 3) {
          burstUnix = Number(sorted[i].unix_time);
          break;
        }
        continue;
      }
      consecutiveDescentIntervals = 0;
    }

    const rates = computeRecentVerticalRates(sorted);
    return {
      hasData: true,
      flightSeconds: Math.max(0, lastUnix - firstUnix),
      burstDetected: burstUnix !== null,
      burstUnix,
      latestAltitudeFt: metersToFeet(sorted[sorted.length - 1].altitude_m),
      lastRate: rates.lastRate,
      avgRate5: rates.avg5
    };
  }

  // Render altitude time-series chart.
  function drawAltitudePlot() {
    const container = document.getElementById('altitudePlot');
    const sorted = [...records].sort((a, b) => (a.unix_time || 0) - (b.unix_time || 0));

    if (!sorted.length) {
      container.innerHTML = '<div class="small" style="padding:12px;">No records yet.</div>';
      return;
    }

    const trace = {
      x: sorted.map((r) => formatTimeOnly(r.unix_time)),
      y: sorted.map((r) => altitudeInSelectedUnit(r.altitude_m)),
      type: 'scatter',
      mode: 'lines+markers',
      line: { color: '#0f766e', width: 3 },
      marker: { size: 5 },
      hovertemplate: `%{x}<br>Altitude: %{y:.1f} ${altitudeUnitLabel()}<extra></extra>`
    };

    const layout = {
      margin: { l: 52, r: 12, t: 16, b: 44 },
      xaxis: { title: `Time (${getTzLabel()})`, type: 'category' },
      yaxis: { title: `Altitude (${altitudeUnitLabel()})` },
      plot_bgcolor: '#ffffff',
      paper_bgcolor: '#ffffff'
    };

    Plotly.react(container, [trace], layout, { responsive: true, displaylogo: false });
  }

  // Render/update map path using records with valid coordinates.
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
      setTimeout(() => flightMap.invalidateSize(), 0);
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
    }).bindPopup(`Latest<br>${formatUnix(latest.unix_time)}`).addTo(flightLayer);

    const bounds = L.latLngBounds(points.map((p) => L.latLng(p[0], p[1])));
    if (bounds.isValid()) {
      if (points.length === 1) {
        flightMap.setView(points[0], 13);
      } else {
        flightMap.fitBounds(bounds, { padding: [20, 20], maxZoom: 13 });
      }
    }
    setTimeout(() => flightMap.invalidateSize(), 0);
  }

  // Render compact capture status text and simulation badge.
  function renderCaptureState() {
    if (!isCurrentLaunch) {
      captureState.textContent = `Viewing launch: ${selectedLaunchLabel}`;
      simulationBadge.classList.remove('on');
      return;
    }

    const enabled = !!state.capture_enabled;
    const browserPollingEnabled = !!state.browser_polling_enabled;
    const simulationMode = !!state.simulation_mode;
    const simPollSeconds = getSimulationPollSeconds();
    const lastOk = state.last_capture_success_unix ? formatUnix(state.last_capture_success_unix) : 'never';
    const err = state.last_error ? ` | last error: ${state.last_error}` : '';
    const pollingMode = browserPollingEnabled ? 'browser polling on' : 'cron mode';
    const sourceMode = simulationMode ? `simulation (${simPollSeconds}s)` : `live APRS (${LIVE_CAPTURE_INTERVAL_MS / 1000}s)`;
    captureState.textContent = `Capture ${enabled ? 'enabled' : 'disabled'} | ${sourceMode} | ${pollingMode} | last success: ${lastOk}${err}`;
    simulationBadge.classList.toggle('on', simulationMode);
  }

  // Render current-flight status cards from computed metrics.
  function renderCurrentLaunchStatus() {
    if (!isCurrentLaunch) {
      flightTimeValue.textContent = '--:--:--';
      flightRateLastValue.textContent = 'Rate (last 2): --';
      flightRateAvgValue.textContent = 'Rate (avg last 5): --';
      burstStatusValue.textContent = '--';
      burstStatusDetail.textContent = `Viewing launch: ${selectedLaunchLabel}`;
      flightStageValue.textContent = '--';
      flightStageRange.textContent = '';
      flightStageDescription.textContent = '';
      return;
    }

    const metrics = computeFlightMetrics();
    if (!metrics.hasData) {
      flightTimeValue.textContent = '--:--:--';
      flightRateLastValue.textContent = 'Rate (last 2): --';
      flightRateAvgValue.textContent = 'Rate (avg last 5): --';
      burstStatusValue.textContent = 'No data';
      burstStatusDetail.textContent = 'Waiting for datapoints.';
      flightStageValue.textContent = 'No data';
      flightStageRange.textContent = 'Waiting for altitude data.';
      flightStageDescription.textContent = '';
      return;
    }

    flightTimeValue.textContent = formatDuration(metrics.flightSeconds);
    flightRateLastValue.textContent = `Rate (last 2): ${formatVerticalRate(metrics.lastRate)}`;
    flightRateAvgValue.textContent = `Rate (avg last 5): ${formatVerticalRate(metrics.avgRate5)}`;

    if (metrics.burstDetected) {
      burstStatusValue.textContent = 'Yes';
      burstStatusDetail.textContent = `First detected at ${formatUnix(metrics.burstUnix)}.`;
    } else {
      burstStatusValue.textContent = 'No';
      burstStatusDetail.textContent = 'Altitude has not switched from rising to falling yet.';
    }

    const stage = getFlightStage(metrics);
    if (!stage) {
      flightStageValue.textContent = 'Unknown';
      flightStageRange.textContent = '';
      flightStageDescription.textContent = '';
      return;
    }
    flightStageValue.textContent = stage.title;
    flightStageRange.textContent = stage.range;
    flightStageDescription.textContent = stage.description || '';
  }

  // Send POST actions to API and normalize response/error handling.
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
    if (!payload) throw new Error(`API returned empty response (HTTP ${res.status}).`);
    if (!res.ok || !payload.ok) throw new Error(payload.error || payload.capture?.message || 'Request failed');
    return payload;
  }

  // Refresh state/records from status endpoint when polling is enabled.
  async function refreshStatus() {
    if (!isCurrentLaunch || !state.browser_polling_enabled) return;
    const res = await fetch('api.php?action=status');
    const raw = await res.text();
    let payload = null;
    try { payload = raw ? JSON.parse(raw) : null; } catch { return; }
    if (!payload || !payload.ok) return;
    state = payload.state || state;
    records = payload.records || records;
    renderAll();
  }

  // Trigger one capture cycle and refresh UI, fallback to status on errors.
  async function captureTick() {
    if (!isCurrentLaunch || !state.browser_polling_enabled || !state.capture_enabled) return;
    try {
      const payload = await postAction('capture_now', {});
      state = payload.state;
      records = payload.records;
      renderAll();
    } catch {
      await refreshStatus();
    }
  }

  // Start or stop recurring capture timer from current settings.
  function scheduleCapture() {
    if (captureTimer) {
      clearInterval(captureTimer);
      captureTimer = null;
    }
    if (isCurrentLaunch && state.capture_enabled && state.browser_polling_enabled) {
      const intervalMs = state.simulation_mode ? (getSimulationPollSeconds() * 1000) : LIVE_CAPTURE_INTERVAL_MS;
      captureTimer = setInterval(captureTick, intervalMs);
    }
  }

  // Re-render all dashboard panels from current state and record data.
  function renderAll() {
    renderCaptureState();
    renderCurrentLaunchStatus();
    drawAltitudePlot();
    drawLocationMap();
    scheduleCapture();
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

  renderAll();
</script>
</body>
</html>
