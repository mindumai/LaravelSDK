<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mindum API Credentials
    |--------------------------------------------------------------------------
    |
    | These credentials identify your account with the Mindum orchestration
    | API. Create an account at https://mindum.ai to obtain an API key.
    |
    */

    'api_key' => env('MINDUM_API_KEY'),

    'api_url' => env('MINDUM_API_URL', 'https://api.mindum.ai'),

    /*
    |--------------------------------------------------------------------------
    | MCP Server Endpoint
    |--------------------------------------------------------------------------
    |
    | The URL path (inside your Laravel app) where Mindum's generated MCP
    | server is registered via laravel/mcp. The orchestrator will call this
    | endpoint over HTTPS with a shared secret in the X-Mindum-Secret header.
    |
    */

    'mcp_endpoint' => env('MINDUM_MCP_ENDPOINT', '/mindum/mcp'),

    'mcp_secret' => env('MINDUM_MCP_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Codebase Scan Paths
    |--------------------------------------------------------------------------
    |
    | Directories (relative to your app's base path) that Mindum's scanner
    | will walk to extract structural metadata. Glob patterns like
    | "packages/Webkul/*\/src\/" are supported for modular apps.
    |
    */

    'scan_paths' => [
        'app/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Class Output
    |--------------------------------------------------------------------------
    |
    | When the Mindum API returns generated tool definitions, the SDK writes
    | them as real PHP tool classes to this directory. Files are overwritten
    | on every scan; orphan files (no longer in the manifest) are removed.
    |
    | By default, you should gitignore this directory — the tool classes are
    | derivative artifacts. Teams that want to review them in PR can commit
    | them instead.
    |
    */

    'tools_path' => app_path('Mindum/Tools'),

    'tools_namespace' => 'App\\Mindum\\Tools',

    /*
    |--------------------------------------------------------------------------
    | Exclusions
    |--------------------------------------------------------------------------
    |
    | Prevent specific models, services, controllers, methods, or fields from
    | being included in the codebase manifest. Use exact fully-qualified
    | class names (or glob patterns) for classes, and method names for
    | method-level exclusions.
    |
    */

    'exclusions' => [
        'classes' => [
            // 'App\\Models\\AuditLog',
            // 'App\\Http\\Controllers\\Internal\\*',
        ],
        'methods' => [
            // 'App\\Models\\User::password',
        ],
        'fields' => [
            // 'password',
            // 'remember_token',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Controls verbosity of SDK-emitted log lines. "debug" logs the full
    | manifest on every scan (helpful when troubleshooting); "info" only
    | logs scan summaries. Tool I/O is never logged, regardless of level.
    |
    */

    'log_level' => env('MINDUM_LOG_LEVEL', 'info'),

];
