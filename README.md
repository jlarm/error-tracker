# armp/error-tracker

Laravel SDK that ships exceptions to your self-hosted error-tracking dashboard.

- Zero-config exception capture — the service provider wires itself in automatically.
- Rich payload: exception class, message, file/line, stack trace, request, environment, release, tags, breadcrumbs, and nested context blocks.
- Safe: the reporter never throws, has a short HTTP timeout, and respects scrub lists.
- Idiomatic Laravel: facade, config publish, package auto-discovery, artisan test command.

## Installation

```bash
composer require armp/error-tracker
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=error-tracker-config
```

## Configuration

Add your project key to `.env`:

```env
ERROR_TRACKER_KEY=your-project-api-key
ERROR_TRACKER_RELEASE=v1.0.0   # optional
```

Grab `ERROR_TRACKER_KEY` from the `[API_KEY]` card on the project page in the dashboard.

## Verify

```bash
php artisan error-tracker:test
```

A `RuntimeException` named "Hello from error-tracker:test" should appear on your dashboard within seconds.

## Usage

```php
use Armp\ErrorTracker\Facades\ErrorTracker;

// Capture an exception manually
try {
    riskyOperation();
} catch (\Throwable $e) {
    ErrorTracker::capture($e);
}

// Capture a free-form message
ErrorTracker::message('Background job took longer than expected', level: 'warning');

// Identify the current user (stored in the "user" context block)
ErrorTracker::user([
    'id'    => $user->id,
    'email' => $user->email,
]);

// Add tags and arbitrary context
ErrorTracker::tag('feature', 'checkout');
ErrorTracker::context('feature_flags', ['new_checkout' => true]);

// Record breadcrumbs leading up to an error
ErrorTracker::breadcrumb('db.sql.query', 'select * from `users`', ['duration_ms' => 1.2]);
```

## Configuration reference

| Key | Default | Description |
| --- | --- | --- |
| `url` | `https://error-tracking.test/api/errors` | Full ingestion URL. |
| `key` | — | Project API key. |
| `enabled` | `true` | Master switch. |
| `release` | `null` | Identifier for this build/deploy. |
| `environments` | `[]` | If non-empty, only report from these environments. |
| `dont_report` | `[]` | Exception classes to silently drop. |
| `stack_trace_frames` | `25` | Max frames per event. |
| `breadcrumb_limit` | `25` | Rolling buffer size for breadcrumbs. |
| `scrubbed_inputs` | `password`, `token`, … | Request input keys to redact. |
| `captured_headers` | `accept`, `user-agent`, … | Request headers to forward. |
| `timeout` | `2` | HTTP timeout in seconds. |

All settings can be overridden via env vars prefixed with `ERROR_TRACKER_`.

## Local development against this package

If you're working on both the SDK and a host app in the same repo, point Composer at the local path:

```json
// composer.json of the host app
"repositories": [
    { "type": "path", "url": "packages/error-tracker" }
],
"require": {
    "armp/error-tracker": "@dev"
}
```

## License

MIT
