# armp/error-tracker

Laravel SDK that ships exceptions to your self-hosted error-tracking dashboard.

- Zero-config exception capture — the service provider wires itself in automatically.
- Rich payload: exception class, message, file/line, stack trace, request, environment, release, tags, breadcrumbs, and nested context blocks.
- Safe: the reporter never throws, has a short HTTP timeout, and respects scrub lists.
- Idiomatic Laravel: facade, config publish, package auto-discovery, artisan test command.

## Installation

This package isn't published on Packagist, so point Composer at the Git repository. Add it to the `repositories` array in your app's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/jlarm/error-tracker.git"
    }
]
```

Then require the package by name:

```bash
composer require armp/error-tracker:dev-main
```

Use `dev-main` to track the default branch, or a tagged constraint (e.g. `^0.1.0`) once releases are published. For a private repository, Composer will use your GitHub token or SSH key — swap the URL for the `git@github.com:jlarm/error-tracker.git` form to authenticate over SSH.

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
| `url` | `https://errors.armp.dev/api/errors` | Full ingestion URL. |
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
| `send_user_details` | `false` | Also send the logged-in user's name and email, not just their id (`ERROR_TRACKER_SEND_USER_DETAILS`). |
| `telemetry.enabled` | `false` | Record and send request telemetry (`ERROR_TRACKER_TELEMETRY`). |
| `telemetry.url` | derived | Telemetry endpoint; defaults to `url` with `/api/errors` → `/api/telemetry`. |
| `telemetry.ignore_paths` | `['up']` | URL paths to skip, with `*` wildcards. |
| `telemetry.ignore_routes` | `[]` | Route names to skip, with `*` wildcards. |
| `telemetry.ignore_jobs` | `[]` | Job classes to skip, with `*` wildcards. |
| `telemetry.ignore_tasks` | `[]` | Scheduled tasks to skip, with `*` wildcards, matched against the command (`backup:*`) or description. |
| `telemetry.buffer_path` | `storage/framework/cache/error-tracker` | Where unsent telemetry is buffered. |
| `telemetry.buffer_max_bytes` | `10485760` (10 MB) | Buffer cap; past it, new entries are dropped and the oldest unsent ones discarded. |
| `telemetry.max_batches_per_run` | `10` | Batches of 1,000 sent per minute while catching up. |

All settings can be overridden via env vars prefixed with `ERROR_TRACKER_`.

## Users

Exceptions and request telemetry include the logged-in user's id automatically, using the user the request already authenticated (reporting never triggers a session or database lookup). Set `ERROR_TRACKER_SEND_USER_DETAILS=true` to also send their name and email. Anything set with `ErrorTracker::user([...])` takes precedence over the automatic values.

## Request telemetry

When `telemetry.enabled` is on, the package records one entry per HTTP request: the route pattern (e.g. `/tickets/{ticket}`, never the raw URL), method, status code, duration in milliseconds, start time and the logged-in user (see Users above).

- The entry is built after the response has been sent, so visitors never wait on it.
- Inertia partial reloads (deferred props, `router.reload({ only })`) are recorded as their own route, labelled with the props requested, e.g. `/dashboard (partial: weeklyDigestSummary)`. Slow deferred props then show up separately instead of being blended into the page's numbers.
- The user ID is only read if the request already authenticated the user; telemetry never triggers a session or database lookup.
- Any error while recording is swallowed and never reaches your app.

### Jobs

Each queued job attempt is recorded too: the job class, queue, connection, attempt number, duration and how it ended:

- `processed`: it ran to completion.
- `released`: it was put back on the queue, after an exception with tries left or by middleware such as `RateLimited`.
- `failed`: it threw on its last try, called `$this->fail()`, or ran past its attempts or `retryUntil()`.

Each attempt is recorded once, and recording errors never reach the job.

### Scheduled tasks

Each scheduled task run is recorded with its command (or description, for closures and jobs), cron expression, timezone, outcome (`finished`, `failed` or `skipped`), exit code and duration. The dashboard uses the cron expression to flag runs that never happened. Tasks run in the background are recorded when `schedule:finish` reports their exit code, without a duration. The package's own `error-tracker:send-telemetry` task is never recorded.

### Sending

Entries are appended to a small file buffer under `storage/framework/cache/error-tracker/`. Every minute, `php artisan error-tracker:send-telemetry` sends them to the dashboard in batches of up to 1,000 and clears what was sent.

The package schedules that command itself when telemetry is enabled, so there is nothing to add to your app's schedule. Your server only needs Laravel's scheduler running, which it already does if you use scheduled commands:

```
* * * * * cd /path-to-your-app && php artisan schedule:run >> /dev/null 2>&1
```

If the dashboard is unreachable, unsent entries are kept and retried on the next run. A batch the dashboard rejects as malformed is dropped rather than retried forever. The buffer is capped (`telemetry.buffer_max_bytes`), so a long outage drops the oldest telemetry instead of filling the disk. Your app is never affected either way.

### Enabling

```env
ERROR_TRACKER_TELEMETRY=true
```

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
