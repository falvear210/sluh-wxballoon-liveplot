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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="assets/css/dashboard-theme.css"/>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
  <script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body class="wb-page wb-page-compact">
<div class="wb-shell">
  <aside class="wb-left-col">
    <div class="wb-panel wb-compact-panel">
      <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
        <h1 class="h5 mb-0">Compact Live Dashboard</h1>
      </div>
      <p class="wb-small mt-2 mb-2">Station: <strong><?= htmlspecialchars($config['aprs_station'] !== '' ? $config['aprs_station'] : '(not configured)', ENT_QUOTES) ?></strong></p>
      <div class="wb-controls-grid">
        <label>Launch
          <select id="launchSelect" class="form-select form-select-sm">
            <option value="current" <?= $selectedLaunch === 'current' ? 'selected' : '' ?>>Current (live)</option>
            <?php foreach ($launches as $launch): ?>
              <option value="<?= htmlspecialchars((string)$launch['id'], ENT_QUOTES) ?>" <?= $selectedLaunch === (string)$launch['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars(ucwords((string)$launch['label']) . ' (' . (int)$launch['record_count'] . ')', ENT_QUOTES) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Display timezone
          <select id="tzSelect" class="form-select form-select-sm">
            <option value="America/Chicago">Central Time</option>
            <option value="UTC">UTC</option>
            <option value="local">Browser Local</option>
          </select>
        </label>
        <label>Altitude unit
          <select id="unitSelect" class="form-select form-select-sm">
            <option value="m">Meters</option>
            <option value="ft">Feet</option>
          </select>
        </label>
        <div id="captureState" class="wb-small wb-span-2"></div>
        <span id="simulationBadge" class="wb-sim-badge wb-span-2">SIMULATION MODE ACTIVE</span>
        <div class="wb-button-row wb-span-2">
          <a class="btn btn-sm btn-outline-primary" href="index.php">Classic View</a>
        </div>
      </div>
    </div>

    <div class="wb-panel wb-compact-panel wb-overflow-auto">
      <h2 class="h6 mb-2">Current Flight Status</h2>
      <div class="wb-status-grid">
        <div class="wb-stat-card">
          <div class="wb-stat-label">Flight time (from first datapoint)</div>
          <div id="flightTimeValue" class="wb-stat-value">--:--:--</div>
          <div id="deviceVoltageValue" class="wb-stat-subtle">Device voltage: --</div>
          <div id="flightRateLastValue" class="wb-stat-subtle">Rate (last 2): --</div>
          <div id="flightRateAvgValue" class="wb-stat-subtle">Rate (avg last 5): --</div>
        </div>
        <div class="wb-stat-card">
          <div class="wb-stat-label">Detected burst</div>
          <div id="burstStatusValue" class="wb-stat-value">No</div>
          <div id="burstStatusDetail" class="wb-stat-subtle"></div>
        </div>
        <div class="wb-stat-card">
          <div class="wb-stat-label">Altitude-based stage</div>
          <div id="flightStageValue" class="wb-stat-value">--</div>
          <div id="flightStageRange" class="wb-stat-subtle"></div>
          <p id="flightStageDescription" class="wb-stat-subtle"></p>
        </div>
      </div>
    </div>
  </aside>

  <main class="wb-right-col">
    <section class="wb-panel wb-compact-panel wb-chart-wrap">
      <div id="locationMap" class="wb-chart wb-chart-compact"></div>
    </section>
    <section class="wb-panel wb-compact-panel wb-chart-wrap">
      <div class="wb-dual-plot-grid">
        <div class="wb-plot-column">
          <div id="altitudePlot" class="wb-chart wb-chart-compact"></div>
        </div>
        <div class="wb-plot-column">
          <div id="environmentPlot" class="wb-chart wb-chart-compact"></div>
        </div>
      </div>
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
  const deviceVoltageValue = document.getElementById('deviceVoltageValue');
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
  let mapTiles = null;
  let mapTileTheme = null;
  const environmentPlot = document.getElementById('environmentPlot');

  function isDarkMode() {
    return window.WxTheme && window.WxTheme.isDark && window.WxTheme.isDark();
  }

  function getPlotPalette() {
    if (isDarkMode()) {
      return {
        text: '#e2e8f0',
        grid: '#334155',
        line: '#22d3ee',
        plotBg: '#0f172a',
        paperBg: '#0f172a'
      };
    }
    return {
      text: '#0f172a',
      grid: '#cbd5e1',
      line: '#0f766e',
      plotBg: '#ffffff',
      paperBg: '#ffffff'
    };
  }

  function getTileConfig() {
    if (isDarkMode()) {
      return {
        theme: 'dark',
        url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
        options: {
          maxZoom: 20,
          subdomains: 'abcd',
          attribution: '&copy; OpenStreetMap contributors &copy; CARTO'
        }
      };
    }
    return {
      theme: 'light',
      url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
      options: {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }
    };
  }

  function ensureMapTiles() {
    if (!flightMap) return;
    const config = getTileConfig();
    if (mapTiles && mapTileTheme === config.theme) return;
    if (mapTiles) {
      flightMap.removeLayer(mapTiles);
    }
    mapTiles = L.tileLayer(config.url, config.options).addTo(flightMap);
    mapTileTheme = config.theme;
  }

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

  // Return short unit label for temperature.
  function temperatureUnitLabel() {
    return getSelectedUnit() === 'ft' ? 'F' : 'C';
  }

  // Return short unit label for pressure.
  function pressureUnitLabel() {
    return getSelectedUnit() === 'ft' ? 'mbar' : 'Pa';
  }

  // Convert altitude from meters into the selected display unit.
  function altitudeInSelectedUnit(metersValue) {
    const meters = Number(metersValue);
    if (!Number.isFinite(meters)) return 0;
    return getSelectedUnit() === 'ft' ? (meters * METERS_TO_FEET) : meters;
  }

  // Convert temperature from C into the selected display unit.
  function temperatureInSelectedUnit(celsiusValue) {
    const celsius = Number(celsiusValue);
    if (!Number.isFinite(celsius)) return null;
    return getSelectedUnit() === 'ft' ? ((celsius * 9) / 5) + 32 : celsius;
  }

  // Convert pressure from Pa into the selected display unit.
  function pressureInSelectedUnit(pascalsValue) {
    const pascals = Number(pascalsValue);
    if (!Number.isFinite(pascals)) return null;
    return getSelectedUnit() === 'ft' ? (pascals / 100) : pascals;
  }

  // Format voltage value from record telemetry.
  function formatVoltage(voltageValue) {
    const voltage = Number(voltageValue);
    return Number.isFinite(voltage) ? `${voltage.toFixed(2)} V` : '--';
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
        latestVoltageV: null,
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
      latestVoltageV: Number.isFinite(Number(sorted[sorted.length - 1].voltage_v)) ? Number(sorted[sorted.length - 1].voltage_v) : null,
      lastRate: rates.lastRate,
      avgRate5: rates.avg5
    };
  }

  // Render altitude time-series chart.
  function drawAltitudePlot() {
    const container = document.getElementById('altitudePlot');
    const sorted = [...records].sort((a, b) => (a.unix_time || 0) - (b.unix_time || 0));

    if (!sorted.length) {
      container.innerHTML = '<div class="wb-small wb-chart-empty">No records yet.</div>';
      return;
    }

    const palette = getPlotPalette();
    const trace = {
      x: sorted.map((r) => formatTimeOnly(r.unix_time)),
      y: sorted.map((r) => altitudeInSelectedUnit(r.altitude_m)),
      type: 'scatter',
      mode: 'lines+markers',
      line: { color: palette.line, width: 3 },
      marker: { size: 5 },
      hovertemplate: `%{x}<br>Altitude: %{y:.1f} ${altitudeUnitLabel()}<extra></extra>`
    };

    const layout = {
      margin: { l: 52, r: 12, t: 16, b: 44 },
      font: { color: palette.text },
      xaxis: { type: 'category', gridcolor: palette.grid, zerolinecolor: palette.grid },
      yaxis: { title: `Altitude (${altitudeUnitLabel()})`, gridcolor: palette.grid, zerolinecolor: palette.grid },
      plot_bgcolor: palette.plotBg,
      paper_bgcolor: palette.paperBg
    };

    Plotly.react(container, [trace], layout, { responsive: true, displaylogo: false });
  }

  // Render temperature/pressure telemetry plot.
  function drawEnvironmentalPlot() {
    const container = environmentPlot;
    const sorted = [...records]
      .filter((r) => Number.isFinite(Number(r.unix_time)))
      .filter((r) => Number.isFinite(Number(r.temperature_c)) || Number.isFinite(Number(r.pressure_pa)))
      .sort((a, b) => (a.unix_time || 0) - (b.unix_time || 0));

    if (!sorted.length) {
      container.innerHTML = '<div class="wb-small wb-chart-empty">No temperature/pressure records yet.</div>';
      return;
    }

    const palette = getPlotPalette();
    const x = sorted.map((r) => formatTimeOnly(r.unix_time));
    const temperature = sorted.map((r) => temperatureInSelectedUnit(r.temperature_c));
    const pressure = sorted.map((r) => pressureInSelectedUnit(r.pressure_pa));

    const traces = [
      {
        x,
        y: temperature,
        type: 'scatter',
        mode: 'lines+markers',
        name: `Temp (${temperatureUnitLabel()})`,
        line: { color: '#f97316', width: 2 },
        marker: { size: 5 },
        yaxis: 'y'
      },
      {
        x,
        y: pressure,
        type: 'scatter',
        mode: 'lines+markers',
        name: `Pressure (${pressureUnitLabel()})`,
        line: { color: '#6366f1', width: 2 },
        marker: { size: 5 },
        yaxis: 'y2'
      }
    ];

    const layout = {
      margin: { l: 52, r: 62, t: 16, b: 44 },
      font: { color: palette.text },
      legend: { orientation: 'h', y: 1.12, yanchor: 'bottom' },
      xaxis: { type: 'category', gridcolor: palette.grid, zerolinecolor: palette.grid },
      yaxis: { title: `Temp (${temperatureUnitLabel()})`, gridcolor: palette.grid, zerolinecolor: palette.grid },
      yaxis2: { title: `Pressure (${pressureUnitLabel()})`, overlaying: 'y', side: 'right', showgrid: false },
      plot_bgcolor: palette.plotBg,
      paper_bgcolor: palette.paperBg
    };

    Plotly.react(container, traces, layout, { responsive: true, displaylogo: false });
  }

  // Render/update map path using records with valid coordinates.
  function drawLocationMap() {
    const container = document.getElementById('locationMap');
    const withCoords = [...records]
      .filter((r) => Number.isFinite(Number(r.latitude)) && Number.isFinite(Number(r.longitude)))
      .sort((a, b) => (a.unix_time || 0) - (b.unix_time || 0));

    if (!flightMap) {
      flightMap = L.map(container, { preferCanvas: true });
      ensureMapTiles();
      flightLayer = L.layerGroup().addTo(flightMap);
      flightMap.setView([38.62, -90.27], 8);
    }
    ensureMapTiles();

    flightLayer.clearLayers();
    if (!withCoords.length) {
      setTimeout(() => flightMap.invalidateSize(), 0);
      return;
    }

    const points = withCoords.map((r) => [Number(r.latitude), Number(r.longitude)]);
    const first = withCoords[0];
    const latest = withCoords[withCoords.length - 1];

    L.polyline(points, { color: isDarkMode() ? '#2dd4bf' : '#0f766e', weight: 3, opacity: 0.9 }).addTo(flightLayer);
    L.circleMarker([Number(first.latitude), Number(first.longitude)], {
      radius: 5,
      color: isDarkMode() ? '#60a5fa' : '#1d4ed8',
      fillColor: isDarkMode() ? '#3b82f6' : '#2563eb',
      fillOpacity: 0.9
    }).bindPopup(`Start<br>${formatUnix(first.unix_time)}`).addTo(flightLayer);
    L.circleMarker([Number(latest.latitude), Number(latest.longitude)], {
      radius: 6,
      color: isDarkMode() ? '#fda4af' : '#b91c1c',
      fillColor: isDarkMode() ? '#fb7185' : '#ef4444',
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
      deviceVoltageValue.textContent = 'Device voltage: --';
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
      deviceVoltageValue.textContent = 'Device voltage: --';
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
    deviceVoltageValue.textContent = `Device voltage: ${formatVoltage(metrics.latestVoltageV)}`;
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
    drawEnvironmentalPlot();
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
