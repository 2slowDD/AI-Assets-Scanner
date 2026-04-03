# CU Scanner

WordPress admin plugin that scans your site's pages for CSS/JS assets and generates optimised unload rules for the [Code Unloader](https://wpservice.pro) plugin.

## What it does

1. **Discovers** all public URLs on your site (via sitemap or WP_Query fallback)
2. **Groups** URLs by post type (Pages, Posts, Other) so you can selectively include or exclude them
3. **Submits** the selected URLs to the wpservice.pro scanning API, including your site's domain for key binding
4. **Polls** for results and displays per-page safe/aggressive rule counts
5. **Exports** a `.json` rule file you can download or push directly to Code Unloader

## Requirements

- WordPress 6.2+
- PHP 8.0+
- An API key from [wpservice.pro](https://wpservice.pro)

## Installation

See [INSTALL.md](INSTALL.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Private repo

This is a private repository. Do not distribute.
