# Mindum for Laravel

**The agent layer for Laravel — auto-generated MCP tools from your codebase.**

Install Mindum into any Laravel app to give your end users a conversational interface to your app's business logic. Mindum scans your models, services, controllers, jobs, and repositories, and uses AI to automatically generate [MCP](https://modelcontextprotocol.io) tool definitions — no manual tool writing required.

> Status: **early access**. Active development — see [`CHANGELOG.md`](CHANGELOG.md) for what's shipped.

## Requirements

- PHP **8.2+**
- Laravel **12+**
- A Mindum account ([mindum.online](https://mindum.online)) for the API key
- An Anthropic API key (only required during local `mindum:chat` testing; the hosted orchestrator handles it in production)

## Install

```bash
composer require mindum/laravel
php artisan mindum:install
```

The install command scans your codebase, sends a structural manifest (metadata only — never raw source code) to the Mindum API, receives back AI-generated MCP tool definitions, and registers them with [`laravel/mcp`](https://laravel.com/docs/mcp).

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=mindum-config
```

Then set in your `.env`:

```env
MINDUM_API_KEY=your-mindum-api-key
ANTHROPIC_API_KEY=your-anthropic-key  # only needed for local mindum:chat testing
```

See `config/mindum.php` for all available options (scan paths, exclusions, tool output directory, MCP endpoint path).

## Commands

| Command | Purpose |
|---|---|
| `mindum:install` | Full first-time setup: scan, analyze, register tools |
| `mindum:rescan` | Force re-analysis of the entire codebase |
| `mindum:status` | Show tool count, sync status, connection health |
| `mindum:chat` | Interactive terminal chat for testing tools locally |

## What Mindum scans

The SDK extracts structural metadata (class names, method signatures, type hints, docblocks, validation rules) from:

- **Eloquent models** (fillable, casts, relationships, soft deletes, searchable traits)
- **Action classes** (`extends BaseService` pattern or similar)
- **Controllers** in `app/Http/Controllers/` (with form request resolution + inline `validate()` parsing)
- **Jobs** in `app/Jobs/` (constructor-based input, sync dispatch only)
- **Repositories** in `**/Repositories/` (Prettus L5-style inherited methods + concrete custom methods)

Modular apps (like Bagisto with `packages/Webkul/*/src/`) are supported via `scan_paths` config.

## Security

Mindum sends only **structural metadata** to its API — class names, method signatures, type hints, docblocks, and validation rules. Your actual source code **never leaves your server**. Tool execution always runs locally in your Laravel app.

See the [security notes in `SECURITY.md`](SECURITY.md) for details (coming soon).

## License

MIT. See [`LICENSE`](LICENSE).

---

Built with love for the Laravel community.
