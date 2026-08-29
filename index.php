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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="assets/css/dashboard-theme.css"/>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
  <script src="https://cdn.plot.ly/plotly-2.35.2.min.js"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body class="wb-page">
<div class="wb-container">
  <div class="wb-panel">
    <div class="wb-topbar">
      <h1 class="h4 mb-0">SLUH Weather Balloon Altitude Tracker</h1>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-sm btn-outline-primary" href="dashboard.php">Compact Dashboard</a>
      </div>
    </div>
    <p class="wb-muted small mb-3">Tracks altitude vs. time from APRS station <strong><?= htmlspecialchars($config['aprs_station'] !== '' ? $config['aprs_station'] : '(not configured)', ENT_QUOTES) ?></strong>.</p>
    <div class="wb-controls">
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
      <span id="captureState" class="wb-small"></span>
      <span id="simulationBadge" class="wb-sim-badge">SIMULATION MODE ACTIVE</span>
    </div>
    <p class="wb-small mt-2 mb-0">APRS data source credit: <a href="https://aprs.fi" target="_blank" rel="noreferrer">aprs.fi</a>. This app fetches only when capture is enabled and uses short-term caching to reduce API load.</p>
  </div>

  <div id="currentLaunchPanel" class="wb-panel">
    <h2 class="h6">Current Flight Status</h2>
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
        <p id="flightStageDescription" class="wb-stage-description"></p>
      </div>
    </div>
  </div>

  <div class="wb-panel">
    <h2 class="h6">Flight Telemetry vs Time</h2>
    <div class="wb-dual-plot-grid">
      <div class="wb-plot-column">
        <div class="wb-plot-title">Altitude</div>
        <div id="altitudePlot" class="wb-chart"></div>
      </div>
      <div class="wb-plot-column">
        <div class="wb-plot-title">Temperature and Pressure</div>
        <div id="environmentPlot" class="wb-chart"></div>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center mt-2">
      <button id="clearSelectionBtn" class="btn btn-sm btn-outline-secondary" type="button">Clear selection</button>
      <span id="ascentStats" class="wb-small">Select 2+ points on the altitude plot to calculate ascent rate.</span>
    </div>
  </div>

  <div class="wb-panel">
    <h2 class="h6">Flight Path Map</h2>
    <div id="locationMap" class="wb-chart"></div>
    <p id="mapStatus" class="wb-small mb-0 mt-2"></p>
  </div>

  <div class="wb-panel">
    <h2 class="h6">Recorded Data</h2>
    <table class="wb-table">
      <thead>
      <tr>
        <th>Timestamp</th>
        <th id="altitudeColHeader">Altitude (m)</th>
        <th id="rateColHeader">Rate from previous (m/s)</th>
        <th id="rateAvgColHeader">Avg last 5 (m/s)</th>
        <th id="temperatureColHeader">Temperature (C)</th>
        <th id="pressureColHeader">Pressure (Pa)</th>
        <th>Voltage (V)</th>
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
  const deviceVoltageValue = document.getElementById('deviceVoltageValue');
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
  const temperatureColHeader = document.getElementById('temperatureColHeader');
  const pressureColHeader = document.getElementById('pressureColHeader');
  const ascentStats = document.getElementById('ascentStats');
  const clearSelectionBtn = document.getElementById('clearSelectionBtn');
  const environmentPlot = document.getElementById('environmentPlot');

  let records = Array.isArray(initialRecords) ? initialRecords : [];
  let state = initialState || {};
  let captureTimer = null;
  let flightMap = null;
  let flightLayer = null;
  let mapTiles = null;
  let mapTileTheme = null;
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

  function isDarkMode() {
    return window.WxTheme && window.WxTheme.isDark && window.WxTheme.isDark();
  }

  function getPlotPalette() {
    if (isDarkMode()) {
      return {
        text: '#e2e8f0',
        grid: '#334155',
        line: '#22d3ee',
        markerSelected: '#fb7185',
        plotBg: '#0f172a',
        paperBg: '#0f172a'
      };
    }
    return {
      text: '#0f172a',
      grid: '#cbd5e1',
      line: '#0ea5a3',
      markerSelected: '#b91c1c',
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

  // Return short unit label for temperature.
  function temperatureUnitLabel() {
    return getSelectedUnit() === 'ft' ? 'F' : 'C';
  }

  // Return short unit label for pressure.
  function pressureUnitLabel() {
    return getSelectedUnit() === 'ft' ? 'millibars' : 'Pa';
  }

  // Convert altitude from meters into the selected display unit.
  function altitudeInSelectedUnit(metersValue) {
    const meters = Number(metersValue);
    if (!Number.isFinite(meters)) return 0;
    return getSelectedUnit() === 'ft' ? (meters * 3.28084) : meters;
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

  // Format altitude with one decimal place and unit suffix.
  function formatAltitude(metersValue) {
    return `${altitudeInSelectedUnit(metersValue).toFixed(1)} ${altitudeUnitLabel()}`;
  }

  // Format voltage value from record telemetry.
  function formatVoltage(voltageValue) {
    const voltage = Number(voltageValue);
    return Number.isFinite(voltage) ? `${voltage.toFixed(2)} V` : '--';
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
      container.innerHTML = '<div class="wb-small wb-chart-empty">No records yet.</div>';
      resetAscentStats();
      return;
    }

    const palette = getPlotPalette();
    const x = sorted.map((r) => formatTimeOnly(r.unix_time));
    const y = sorted.map((r) => altitudeInSelectedUnit(r.altitude_m));

    const trace = {
      x,
      y,
      type: 'scatter',
      mode: 'lines+markers',
      line: { color: palette.line, width: 3 },
      marker: { size: 6 },
      selected: { marker: { color: palette.markerSelected, size: 8 } },
      unselected: { marker: { opacity: 0.45 } },
      hovertemplate: `%{x}<br>Altitude: %{y:.1f} ${altitudeUnitLabel()}<extra></extra>`
    };

    const layout = {
      margin: { l: 56, r: 18, t: 18, b: 60 },
      font: { color: palette.text },
      xaxis: { type: 'category', gridcolor: palette.grid, zerolinecolor: palette.grid },
      yaxis: { title: `Altitude (${altitudeUnitLabel()})`, gridcolor: palette.grid, zerolinecolor: palette.grid },
      dragmode: 'select',
      plot_bgcolor: palette.plotBg,
      paper_bgcolor: palette.paperBg
    };

    Plotly.react(container, [trace], layout, { responsive: true, displaylogo: false });
    wirePlotSelectionHandlers();
    resetAscentStats();
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
      margin: { l: 56, r: 62, t: 18, b: 60 },
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

  // Render/update map path from records that include coordinates.
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
      mapStatus.textContent = 'No latitude/longitude data yet.';
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
      dataRows.innerHTML = '<tr><td colspan="8">No records yet.</td></tr>';
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
        <td>${Number.isFinite(temperatureInSelectedUnit(row.record.temperature_c)) ? temperatureInSelectedUnit(row.record.temperature_c).toFixed(1) : '--'}</td>
        <td>${Number.isFinite(pressureInSelectedUnit(row.record.pressure_pa)) ? pressureInSelectedUnit(row.record.pressure_pa).toFixed(getSelectedUnit() === 'ft' ? 1 : 0) : '--'}</td>
        <td>${Number.isFinite(Number(row.record.voltage_v)) ? Number(row.record.voltage_v).toFixed(2) : '--'}</td>
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
    temperatureColHeader.textContent = `Temperature (${temperatureUnitLabel()})`;
    pressureColHeader.textContent = `Pressure (${pressureUnitLabel()})`;
    renderCaptureState();
    renderCurrentLaunchStatus();
    renderTable();
    drawAltitudePlot();
    drawEnvironmentalPlot();
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
