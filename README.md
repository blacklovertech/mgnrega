# MGNREGA District Dashboard (Our Voice, Our Rights)

Simple, resilient dashboard for citizens to understand MGNREGA performance in their district. Designed for low-literacy users and large-scale use with offline fallbacks.

## Features

- District and Year selection with Apr→Mar (Indian financial year) ordering
- Compare with previous year (YoY) across charts and summary
- Auto-detect district using geolocation via `geo.php`
- JSON fallback when DB/API is unavailable (no user-visible errors)
- Charts: Families, People, Work Status, SC/ST, Performance, Monthly Wages, Cumulative Families
- Exports: Download chart PNGs and monthly CSV (respects compare mode)
- Plain-language captions and help tooltips; mobile-friendly layout

## Project Structure

- `index.php` — main dashboard (DB + JSON fallback, charts, UI)
- `geo.php` — server-side geolocation reverse-lookup API and demo page
- `mgnrega_tn_all_records.json` — local dataset fallback (large file)

## Data Sources

Primary data source is the Government of India open APIs (data.gov.in) for MGNREGA monthly performance. For reliability at scale, populate your database from the APIs asynchronously and let the dashboard read from the database with JSON fallback.

## Requirements

- PHP 8.0+
- PDO with `pgsql` extension (for Postgres)
- Web server (Apache/Nginx) or PHP’s built-in server for local use

## Configuration

Set database credentials as environment variables in your web server (recommended) or adjust `index.php` values for local testing.

Environment variables (recommended):

```bash
export DB_HOST=your_supabase_or_postgres_host
export DB_NAME=postgres
export DB_USER=postgres
export DB_PASS=yourpassword
```

Then in `index.php`, replace hardcoded values with `getenv('DB_HOST')` etc. (left hardcoded here for easy local trials).

Postgres table expected: `mgnrega` with at least the following columns:

- `district_name` (text)
- `fin_year` (text, e.g., "2023-24")
- `month` (text, one of: Apr, May, Jun, Jul, Aug, Sep, Oct, Nov, Dec, Jan, Feb, Mar)
- `total_households_worked` (int)
- `total_individuals_worked` (int)
- `women_persondays` (int)
- `wages` (numeric/float)
- `average_wage_rate_per_day_per_person` (numeric)
- `number_of_completed_works` (int)
- `number_of_ongoing_works` (int)
- `sc_persondays` (int)
- `st_persondays` (int)
- `total_no_of_hhs_completed_100_days_of_wage_employment` (int)

## Running Locally

Option A: PHP built-in server

```bash
php -S 127.0.0.1:8000 -t .
```

Visit `http://127.0.0.1:8000/index.php`.

Option B: Laragon/XAMPP/WAMP

- Place the project under the web root (e.g., `D:\laragon\www\MGNRE`)
- Start Apache and navigate to `http://localhost/MGNRE/`

## Geolocation (Bonus)

- In `index.php`, the “Use my location” button uses browser geolocation and POSTs to `geo.php`
- `geo.php` performs reverse geocoding via Nominatim (server-side) and returns a JSON with `district`
- The UI tries to match the detected district in the dropdown and auto-submits

Note: For production, enable SSL verification in `geo.php` and add a proper `User-Agent` per Nominatim’s policy. Rate-limit requests.

## Financial Year Ordering

All charts, tables, and exports use Apr→Mar ordering to reflect Indian financial years. The DB `ORDER BY` and JSON aggregation follow this sequence.

## Production Architecture Recommendations

1. Background ETL (cron/systemd timer):
   - Fetch new monthly data from data.gov.in APIs
   - Validate, normalize, and upsert into Postgres (`mgnrega` table)
   - Write/refresh `mgnrega_tn_all_records.json` for fallback

2. Caching:
   - Cache popular district/year combinations (e.g., Redis) for fast responses

3. Observability:
   - Access logs and minimal error logs (no PII)
   - Health endpoint: expose `/healthcheck.php`

4. Security:
   - Move secrets to environment variables or secret manager
   - Restrict outbound calls; rate-limit `geo.php`

## Accessibility & Low-Literacy Design

- Use plain language and short explanatory lines under each metric
- Employ icons and color coding consistently
- Keep controls large; avoid text input where possible
- Prepare for localization (placeholders added for future language packs)

## Known Limitations / Next Steps

- Translation text not yet wired; only placeholders present
- No admin UI for ETL; recommend a separate script or service
- District name mapping may differ slightly between Nominatim and official lists; consider a mapping table

## License

MIT (or your preferred license)


