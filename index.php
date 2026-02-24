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
    .simBadge {
      display: none;
      font-size: 12px;
      font-weight: 700;
      color: #7f1d1d;
      background: #fee2e2;
      border: 1px solid #fecaca;
      border-radius: 999px;
      padding: 3px 8px;
    }
    .simBadge.on { display: inline-block; }
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
    .stageDescription {
      font-size: 12px;
      line-height: 1.45;
      color: var(--text);
      margin-top: 8px;
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
      <a class="linkbtn secondary" href="dashboard.php">Compact Dashboard</a>
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
      <span id="simulationBadge" class="simBadge">SIMULATION MODE ACTIVE</span>
    </div>
    <p class="small">APRS data source credit: <a href="https://aprs.fi" target="_blank" rel="noreferrer">aprs.fi</a>. This app fetches only when capture is enabled and uses short-term caching to reduce API load.</p>
  </div>

  <div id="currentLaunchPanel" class="panel">
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
        <p id="flightStageDescription" class="stageDescription"></p>
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
  const simulationBadge = document.getElementById('simulationBadge');
  const currentLaunchPanel = document.getElementById('currentLaunchPanel');
  const flightTimeValue = document.getElementById('flightTimeValue');
  const flightRateLastValue = document.getElementById('flightRateLastValue');
  const flightRateAvgValue = document.getElementById('flightRateAvgValue');
  const burstStatusValue = document.getElementById('burstStatusValue');
  const burstStatusDetail = document.getElementById('burstStatusDetail');
  const flightStageValue = document.getElementById('flightStageValue');
  const flightStageRange = document.getElementById('flightStageRange');
  const flightStageDescription = document.getElementById('flightStageDescription');
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
  const METERS_TO_FEET = 3.28084;
  const FLIGHT_STAGES = {
    preLaunch: {
      title: 'Pre-Launch',
      range: 'less than 600 feet',
      description: 'The payload is on the ground and undergoing final system checks. GPS lock, telemetry transmission, cameras, and environmental sensors are verified while the balloon is inflated and secured. The launch target ascent rate is approximately 5 feet per second (≈300 ft/min) to ensure a stable climb profile and predictable flight path. The mission transitions to ascent once sustained vertical movement is detected.'
    },
    initialAscent: {
      title: 'Initial Ascent',
      range: '600 to 10,000 feet',
      description: 'The balloon is climbing through the lowest portion of the atmosphere, where most weather and turbulence occur. Winds in this region strongly influence early horizontal drift. The ascent rate is monitored to maintain the target of ~5 ft/sec, supporting a projected burst altitude near 100,000 feet.'
    },
    troposphericAscent: {
      title: 'Tropospheric Ascent',
      range: '10,000 to 40,000 feet',
      description: 'The payload continues rising through the troposphere, where temperature generally decreases with altitude and large-scale weather systems are present. Jet stream winds, often found between 25,000 and 40,000 feet, can significantly affect the balloon’s ground track. The balloon steadily expands as outside air pressure decreases.'
    },
    stratosphericAscent: {
      title: 'Stratospheric Ascent',
      range: '40,000 to 95,000 feet',
      description: 'The balloon has entered the stratosphere, a more stable atmospheric layer with very low humidity and minimal turbulence. Temperatures begin increasing with altitude in this region. As air pressure drops, the balloon expands dramatically.'
    },
    nearPeakAltitude: {
      title: 'Near Peak Altitude',
      range: 'over 95,000 feet',
      description: 'The balloon is approaching its maximum altitude, typically near 100,000–101,000 feet. The latex envelope has expanded to many times its original size. As lift and drag approach equilibrium, the vertical speed decreases. The system is nearing the structural limits of the balloon material.'
    },
    burstAndThinAirFreefall: {
      title: 'Burst and Thin Air Freefall',
      range: 'from burst to 60,000 feet',
      description: 'The balloon has exceeded its expansion limit and ruptured. The payload is descending rapidly through very thin air. Because atmospheric density is low at this altitude, the parachute initially provides limited drag. Descent speeds during this phase can exceed 100 feet per second before gradually slowing as the air becomes denser.'
    },
    parachuteDescent: {
      title: 'Parachute Descent',
      range: '60,000 to 5,000 feet',
      description: 'As the payload enters denser layers of the atmosphere, the parachute becomes fully effective. The descent rate stabilizes and decreases significantly. Winds at various altitudes continue to influence horizontal drift toward the landing area.'
    },
    finalApproachLanding: {
      title: 'Final Approach & Landing',
      range: '5,000 feet to ground',
      description: 'The payload is descending steadily under parachute and approaching the surface. When altitude readings stabilize near ground level for a sustained period, the system is classified as landed. GPS coordinates are then used to guide the recovery team to the payload location.'
    }
  };
  const LIVE_CAPTURE_INTERVAL_MS = 60000;

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

  // Convert timezone selection to a friendly label used in UI text.
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

  // Return short unit label for headings and values.
  function altitudeUnitLabel() {
    return getSelectedUnit() === 'ft' ? 'ft' : 'm';
  }

  // Convert altitude from meters into the selected display unit.
  function altitudeInSelectedUnit(metersValue) {
    const meters = Number(metersValue);
    if (!Number.isFinite(meters)) return 0;
    return getSelectedUnit() === 'ft' ? (meters * 3.28084) : meters;
  }

  // Format altitude with one decimal place and unit suffix.
  function formatAltitude(metersValue) {
    return `${altitudeInSelectedUnit(metersValue).toFixed(1)} ${altitudeUnitLabel()}`;
  }

  // Update selection/ascent status text.
  function setAscentStatsText(msg) {
    ascentStats.textContent = msg;
  }

  // Reset selection/ascent status to default instructions.
  function resetAscentStats() {
    setAscentStatsText('Select 2+ points on the altitude plot to calculate ascent rate.');
  }

  // Compute ascent stats for selected chart points and render summary text.
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

  // Register plot selection handlers once so drag-selection updates ascent stats.
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

  // Format unix timestamp in selected timezone.
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

  // Format only time component for compact chart x-axis labels.
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

  // Render altitude plot and wire selection interactions.
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

  // Render/update map path from records that include coordinates.
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

  // Render records table with per-row and rolling vertical-rate metrics.
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

  // Render top capture state text and simulation badge visibility.
  function renderCaptureState() {
    if (!isCurrentLaunch) {
      captureState.textContent = `Viewing launch: ${selectedLaunchLabel}`;
      return;
    }

    const enabled = !!state.capture_enabled;
    const browserPollingEnabled = !!state.browser_polling_enabled;
    const simulationMode = !!state.simulation_mode;
    const simPollSeconds = getSimulationPollSeconds();
    const lastOk = state.last_capture_success_unix ? formatUnix(state.last_capture_success_unix) : 'never';
    const err = state.last_error ? ` | last error: ${state.last_error}` : '';
    const pollingMode = browserPollingEnabled ? 'browser polling on' : 'cron mode (browser polling off)';
    const sourceMode = simulationMode ? `simulation (${simPollSeconds}s cadence)` : `live APRS (${LIVE_CAPTURE_INTERVAL_MS / 1000}s cadence)`;
    captureState.textContent = `Capture ${enabled ? 'enabled' : 'disabled'} (${sourceMode}; ${pollingMode}; change in Settings) | last success: ${lastOk}${err}`;
    simulationBadge.classList.toggle('on', simulationMode);
  }

  // Format elapsed seconds as HH:MM:SS.
  function formatDuration(totalSeconds) {
    const sec = Math.max(0, Number(totalSeconds) || 0);
    const hours = Math.floor(sec / 3600);
    const minutes = Math.floor((sec % 3600) / 60);
    const seconds = sec % 60;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  // Convert meters to feet for stage/threshold calculations.
  function metersToFeet(metersValue) {
    const meters = Number(metersValue);
    if (!Number.isFinite(meters)) return 0;
    return meters * METERS_TO_FEET;
  }

  // Map current flight metrics to a named flight stage.
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

  // Format signed vertical-rate text with ascent/descent direction.
  function formatVerticalRate(ratePerSecond) {
    if (!Number.isFinite(ratePerSecond)) return '--';
    const direction = ratePerSecond > 0 ? 'ascent' : (ratePerSecond < 0 ? 'descent' : 'level');
    return `${ratePerSecond.toFixed(3)} ${altitudeUnitLabel()}/s (${direction})`;
  }

  // Compute most recent instantaneous and rolling-average vertical rates.
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

    if (!rates.length) {
      return { lastRate: null, avg5: null };
    }

    const lastRate = rates[rates.length - 1];
    const recent = rates.slice(-5);
    const avg5 = recent.reduce((sum, v) => sum + v, 0) / recent.length;
    return { lastRate, avg5 };
  }

  // Derive flight metrics (time, burst detection, rates) from current records.
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

  // Render current-launch status cards using computed flight metrics.
  function renderCurrentLaunchStatus() {
    if (!isCurrentLaunch) {
      currentLaunchPanel.style.display = 'none';
      return;
    }

    currentLaunchPanel.style.display = '';
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
    flightStageDescription.textContent = stage.description;
  }

  // Send a POST action to API and normalize error handling.
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

  // Refresh state/records from status endpoint when polling is enabled.
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

  // Execute one capture cycle and refresh UI; fall back to status refresh on failure.
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

  // Start/stop capture timer based on current launch and polling settings.
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

  // Re-render all dynamic sections from current state/records.
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

  clearSelectionBtn.addEventListener('click', () => {
    const container = document.getElementById('altitudePlot');
    Plotly.restyle(container, { selectedpoints: [null] }, [0]);
    resetAscentStats();
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
