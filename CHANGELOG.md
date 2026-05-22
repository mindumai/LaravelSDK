# Changelog

All notable changes to `mindum/laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] — 2026-05-22

First public release.

### Added

- **Codebase scanner** — extracts structural metadata from Eloquent models, action classes, controllers, jobs, and repositories. Handles modular layouts (e.g. Bagisto-style `packages/Webkul/*/src/`) via configurable `scan_paths`. Source code never leaves the customer's server; only class names, method signatures, type hints, docblocks, and validation rules are uploaded.
- **Tool generation** — uploads a manifest to the Mindum API and writes the returned MCP tool definitions to `app/Mindum/Tools/`. Header-marker rescan strategy preserves user-owned files in the same directory while removing stale generated tools.
- **MCP server endpoint** — mounts `POST {mindum.mcp_endpoint}` (default `/mindum/mcp`) via `laravel/mcp`, gated by an `X-Mindum-Secret` shared-header middleware.
- **Widget Blade component** — `<x-mindum::widget />` emits the JS bootstrap. A `POST /mindum/widget/token` proxy forwards browser mint requests to the orchestrator using the customer's API key; the API key never reaches the browser.
- **Welcome message + suggested prompts** — configurable via `mindum.widget.welcome.{message,prompts}` or the Blade component attributes.
- **Artisan commands** — `mindum:install`, `mindum:rescan`, `mindum:status`, `mindum:chat`.

### Requirements

- PHP 8.2+
- Laravel 12+
- `laravel/mcp` ^0.7

[Unreleased]: https://github.com/mindumai/LaravelSDK/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/mindumai/LaravelSDK/releases/tag/v0.1.0
