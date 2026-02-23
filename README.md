# SLUH Weather Balloon Liveplot

Simple PHP site for weather balloon altitude tracking.

## Setup

1. Configure APRS values in `.env` (see `.env.example`).
   - For web-based launch imports, also set `APP_ADMIN_PASSWORD`.
2. Run the server:

```bash
php -S 127.0.0.1:8080
```

3. Open `http://127.0.0.1:8080/index.php`.
4. Open `http://127.0.0.1:8080/editor.php` for manual data entry and deleting datapoints (admin-only).
5. Open `http://127.0.0.1:8080/settings.php` for APRS station/key and capture settings (admin-only).
6. Open `http://127.0.0.1:8080/admin_import.php` for admin-only APRS paste import.
7. Open `http://127.0.0.1:8080/admin_csv_import.php` for admin-only CSV upload import.

## Import APRS Paste As A Launch

You can import copied APRS raw text into a separate launch JSON file:

```bash
cat rawpackets.txt | php scripts/import_aprs_launch.php --name "Launch 2026-02-23"
```

Or from a file:

```bash
php scripts/import_aprs_launch.php --name "Launch 2026-02-23" --input rawpackets.txt
```

Imported launches are saved under `data/launches/*.json` and appear in the launch dropdown on `index.php`.

## Import CSV Launch Data

For CSV files in the format used by `data/raw/*.csv` (`time,lasttime,lat,lng,speed,course,altitude,comment`):

```bash
php scripts/import_csv_launch.php --file data/raw/2025-02-21.csv --name "Launch 2025-02-21"
```

Options:
- `--station` to override station value in imported records.
- `--tz` to parse timestamps (default: `UTC`).

### Web Import (Admin-only)

`admin_import.php` requires login using `APP_ADMIN_PASSWORD` from `.env`. After logging in, paste APRS raw lines into the form and submit to create a new launch file.

`admin_csv_import.php` requires the same login and allows CSV file uploads using the `data/raw/*.csv` format.

## Notes

- APRS config is loaded from `.env` (`APRS_STATION`, `APRS_API_KEY`, `APP_ADMIN_PASSWORD`).
- Captured data is stored in `data/records.json`.
- Imported launch data is stored in `data/launches/*.json`.
- Timestamp display defaults to Central Time and can be toggled to UTC or browser local time.
- The main page uses Plotly for altitude and Leaflet + OpenStreetMap for the flight-path map (CDN/network access required).
- Altitude display can be toggled between meters and feet on both main and editor pages.

## Server-Side Auto Capture (Cron)

If you want capture to run without keeping `index.php` open in a browser, use cron with:

```bash
php scripts/capture_once.php
```

This script:
- Reads the existing `capture_enabled` toggle from `data/state.json`.
- Reads `browser_polling_enabled` to let you disable browser-side polling while using cron.
- Skips cleanly if capture is disabled.
- Fetches APRS once and appends new datapoints when enabled.

### 1) Test manually first

From project root:

```bash
php scripts/capture_once.php
```

### 2) Add a crontab entry

Open crontab:

```bash
crontab -e
```

Example (run every minute):

```cron
* * * * * cd /Users/falvear/code/wxballoon-liveplot && /usr/bin/php scripts/capture_once.php >> /Users/falvear/code/wxballoon-liveplot/data/cron_capture.log 2>&1
```

### 3) Verify cron is installed

```bash
crontab -l
```

### Notes

- Use full paths in cron (`/usr/bin/php`, absolute project path).
- If your PHP binary is elsewhere, find it with `which php`.
- Keep the web capture toggle enabled in Settings for cron captures to run.
- In Settings, disable `Allow browser polling API calls` when cron is handling capture, so public browser sessions do not trigger API polling.
