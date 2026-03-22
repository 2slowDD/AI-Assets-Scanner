# Installation

## Requirements

- WordPress 6.2 or later
- PHP 8.0 or later
- An API key from [wpservice.pro](https://wpservice.pro)

## Steps

### 1. Upload the plugin

Upload the `cu-scanner` folder to your `/wp-content/plugins/` directory, or install it via the WordPress admin by uploading the plugin zip.

### 2. Activate

Go to **Plugins → Installed Plugins** and activate **CU Scanner**.

### 3. Enter your API key

Go to **CU Scanner → Settings** and paste your wpservice.pro API key into the **API Key** field. Click **Save Settings**.

Click **Refresh** next to Credit Balance to confirm your key is valid and your credit balance is shown correctly.

### 4. (Optional) HTTP Basic Auth

If your site is protected by server-level HTTP authentication (e.g. a staging environment), enter your credentials in the **HTTP Basic Auth** section. These are stored encrypted in `wp_options`.

### 5. Run a scan

Go to **CU Scanner** (the main scanner page).

1. Optionally add URLs to the exclusion textarea (one per line) — these are removed before discovery.
2. Click **Discover Pages**. The scanner will find all public URLs on your site and group them by post type.
3. Deselect any URLs you don't want to scan using the checkboxes, or use the filter pills to focus on a specific group.
4. Click **Start Scan →**.
5. Wait for the scan to complete. Results show per-page safe and aggressive rule counts.
6. Download the `.json` rule file or click **Push to Code Unloader** to apply the rules directly.

## Uninstalling

Deactivate and delete the plugin via **Plugins → Installed Plugins**. The plugin's database table (`wp_cu_scanner_history`) and options (`cu_scanner_*`) are not automatically removed — delete them manually if needed.
