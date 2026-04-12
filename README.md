# AI Assets Scanner

AI-powered CSS/JS asset scanner for WordPress, by [WPservice.pro](https://wpservice.pro).

AI Assets Scanner discovers all public URLs on your WordPress site, submits them to an AI analysis service, and returns per-page lists of safe and aggressive asset-unloading rules. Results can be downloaded as a `.json` file or pushed directly into [Code Unloader](https://wpservice.pro).

## Features

- **Automated page discovery** — finds all Pages, Posts, and custom post types via sitemap or WP_Query fallback
- **AI-powered analysis** — each URL is rendered headlessly and its CSS/JS assets are profiled
- **Safe + aggressive rules** — two tiers: safe (assets unused on the page) and aggressive (assets that may be needed conditionally)
- **Push to Code Unloader** — one-click rule push with snapshot backup and versioned group history
- **Credit system** — pay per scan via wpservice.pro credits
- **Optimization plugin auto-bypass** — automatically bypasses WP Rocket, Autoptimize, and Code Unloader caches during scanning
- **HTTP Basic Auth support** — scan password-protected staging environments
- **Scan history** — browse past scan results and re-download rule files at any time
- **Bot-protection notice** — contextual warning before scanning reminds users to disable Cloudflare / WordFence bot blocking for accurate results
- **Security plugin detection** — detects Wordfence, Wordfence Login Security, and Cloudflare for WordPress in Step 1 and shows a contextual warning with a "See Settings →" deep-link to the relevant mitigation section
- **Cloudflare WAF bypass** — auto-generated Scanner Secret can be used in a Cloudflare WAF Custom Rule so the scanner bypasses Bot Fight Mode without disabling site-wide protection

## How it works

The plugin is part of a three-component system:

```
AI Assets Scanner plugin  ──→  wpservice.pro SaaS  ──→  Railway analysis service
(this repo)                     (credit & auth API)       (Playwright crawler)
```

- **Credits** are validated at reservation time and deducted only after a successful scan completes
- **One active scan per user** — starting a second scan while one is in progress returns HTTP 409
- **Exported JSON** uses Code Unloader's native import format (`asset_handle`, `css`/`js` types, full normalized URL patterns with `exact` match) so both manual import and Push to Code Unloader work correctly
- **Rule versioning** — each push snapshots currently active rules, renames old scanner groups to versioned copies (e.g. "AI Assets Scanner — Safe v1"), and deactivates all previous rules so only the new scan results are active

## Requirements

- WordPress 6.2+
- PHP 8.0+
- An API key from [wpservice.pro](https://wpservice.pro)
- Code Unloader v1.4.0+ (for Push to Code Unloader and JSON import)

## Installation

See [INSTALL.md](INSTALL.md) for full installation and setup instructions.

## Quick start

1. Install and activate the plugin
2. Go to **AI Assets Scanner → Settings**, enter your wpservice.pro API key, and save
3. Go to **AI Assets Scanner**, click **Discover Pages**, select the URLs to scan, and click **Start Scan →**
4. Once complete, review results and click **Push to Code Unloader** or download the `.json` rule file

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full version history.

## License

Proprietary — all rights reserved. Requires a valid wpservice.pro API key to function.
