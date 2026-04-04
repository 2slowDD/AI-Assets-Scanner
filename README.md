# CU Scanner

WordPress admin plugin that scans your site's pages for CSS/JS assets and generates optimised unload rules for the [Code Unloader](https://wpservice.pro) plugin.

## What it does

1. **Discovers** all public URLs on your site (via sitemap or WP_Query fallback)
2. **Groups** URLs by post type (Pages, Posts, Other) so you can selectively include or exclude them
3. **Submits** the selected URLs to the wpservice.pro scanning API, which queues the job on the Railway analysis service
4. **Polls** for results and displays a per-page progress table with safe/aggressive rule counts
5. **Exports** a `.json` rule file you can download and import into Code Unloader, or push directly with one click

## How it works

The plugin is part of a three-component system:

```
CU Scanner plugin  ──→  wpservice.pro SaaS  ──→  Railway analysis service
(this repo)              (credit & auth API)       (Playwright crawler)
```

- **Credits** are validated at reservation time and deducted only after a successful scan completes
- **One active scan per user** — starting a second scan while one is in progress returns HTTP 409
- **Exported JSON** uses Code Unloader's native import format (`asset_handle`, `css`/`js` types, full normalized URL patterns with `exact` match) so both manual import and Push to CU work correctly

## Requirements

- WordPress 6.2+
- PHP 8.0+
- An API key from [wpservice.pro](https://wpservice.pro)
- Code Unloader v1.4.0+ (for Push to CU and JSON import)

## Installation

See [INSTALL.md](INSTALL.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Private repo

This is a private repository. Do not distribute.
