# WP Login History

A lightweight WordPress plugin that logs successful and failed login attempts and displays them in a searchable, sortable, paginated admin screen using native WordPress UI components.

## Features

- Logs every successful login with date/time, IP address, browser, device type, and country (if GeoIP is available locally).
- Tracks failed login attempts with timestamp and IP address.
- Admin page with a searchable, sortable, paginated login history table (built on `WP_List_Table`, matching core WordPress screens).
- Filter logs by user, date range, and success/failed status.
- Manual "Clear All Logs" option and bulk delete for selected entries.
- Export login history to CSV, respecting any active filters.
- Automatic daily cleanup that deletes logs older than a configurable retention period (default: 90 days).
- No external API calls. GeoIP lookups only run if a local resolver (PHP `geoip` extension or a locally installed GeoIP plugin) is already present on the server.
- Built with prepared SQL statements, nonces, capability checks, and sanitized/escaped output throughout.
- Translation-ready (text domain: `wp-login-history`).
- Single-file plugin, clean object-oriented PHP, no premium features or upsells.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later

## Installation

1. Download `wp-login-history.php`.
2. Upload it to your `wp-content/plugins/` directory (either as a single file, or inside its own folder, e.g. `wp-content/plugins/wp-login-history/wp-login-history.php`).
3. Activate **WP Login History** from the Plugins screen in wp-admin.
4. A new **Login History** menu item will appear in the WordPress admin sidebar.

## Usage

### Viewing login history

Go to **Login History** in the admin menu to see a table of all recorded logins and failed attempts. You can:

- Search by username or IP address.
- Filter by user, status (success/failed), and date range.
- Sort by date, user, or status.
- Adjust how many entries are shown per page via Screen Options (top right of the page).

### Clearing logs

Click **Clear All Logs** on the Login History page to permanently delete all recorded entries, or select individual rows and use the **Delete** bulk action.

### Exporting logs

Click **Export CSV** to download the current filtered view (or the full log set if no filters are applied) as a CSV file.

### Settings

Go to **Login History > Settings** to configure:

- **Log Retention Period** — number of days to keep records before they are automatically deleted (default: 90).
- **On Uninstall** — optionally delete all plugin data and settings when the plugin is removed via the Plugins screen.

## How data is collected

- **IP address** is read from standard request headers (`X-Forwarded-For`, `X-Client-IP`, `REMOTE_ADDR`) and validated before storage.
- **Browser** and **device type** are determined by parsing the request's `User-Agent` string locally; no third-party service is contacted.
- **Country** is only populated if a GeoIP resolver already exists on the server (e.g. the PHP `geoip` extension, or a locally installed GeoIP plugin using a local database). If none is available, the field is left blank. You can hook into the `wplh_lookup_country` filter to supply your own local resolver.

## Security

- All database queries use `$wpdb->prepare()`.
- All state-changing actions (clear logs, delete selected, export, settings) require a valid nonce and the `manage_options` capability.
- All output is escaped using WordPress escaping functions.

## Uninstalling

By default, deactivating or deleting the plugin leaves your data intact. If you want all login history data and plugin settings removed automatically when the plugin is deleted, enable **Delete all login history data and settings when this plugin is uninstalled** under **Login History > Settings** before removing it.

## License

GPL v2 See https://www.gnu.org/licenses/gpl-2.0.html

## Author

NRW India
https://wp.nrwone.in
