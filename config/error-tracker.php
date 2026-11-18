<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingest URL
    |--------------------------------------------------------------------------
    |
    | The full URL of your error tracking dashboard's ingestion endpoint.
    | Example: https://error-tracking.test/api/errors
    |
    */
    'url' => env('ERROR_TRACKER_URL', 'https://errors.armp.dev/api/errors'),

    /*
    |--------------------------------------------------------------------------
    | Project API Key
    |--------------------------------------------------------------------------
    |
    | The API key for the project that should receive errors. Grab this from
    | the [API_KEY] card on the project page in the dashboard.
    |
    */
    'key' => env('ERROR_TRACKER_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Toggle reporting without removing the integration. Errors are only sent
    | when this resolves truthy AND the url + key are configured.
    |
    */
    'enabled' => env('ERROR_TRACKER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    |
    | Identifies which version of your app produced an error. Common choices:
    | a git sha, a tag, or your deploy id. Stored in the issue's "release" pill.
    |
    */
    'release' => env('ERROR_TRACKER_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Environment Filter
    |--------------------------------------------------------------------------
    |
    | Only report when the current environment is in this list. Leave empty
    | to report from every environment.
    |
    */
    'environments' => array_filter(
        explode(',', (string) env('ERROR_TRACKER_ENVIRONMENTS', '')),
    ),

    /*
    |--------------------------------------------------------------------------
    | Don't Report
    |--------------------------------------------------------------------------
    |
    | Exception classes that should never be forwarded. Add framework noise
    | here (e.g. 404s) if you don't want it in the dashboard.
    |
    */
    'dont_report' => [
        // \Illuminate\Auth\AuthenticationException::class,
        // \Illuminate\Validation\ValidationException::class,
        // \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stack Trace Frames
    |--------------------------------------------------------------------------
    |
    | Maximum number of frames captured per exception. Lower values keep the
    | payload small; higher values give you more debugging surface area.
    |
    */
    'stack_trace_frames' => 25,

    /*
    |--------------------------------------------------------------------------
    | Breadcrumb Buffer
    |--------------------------------------------------------------------------
    |
    | Maximum number of breadcrumbs retained per request. The buffer is a
    | rolling window — when full, the oldest crumbs are dropped.
    |
    */
    'breadcrumb_limit' => 25,

    /*
    |--------------------------------------------------------------------------
    | Scrubbed Inputs
    |--------------------------------------------------------------------------
    |
    | Request input keys whose values are redacted from captured payloads.
    | Sensitive headers and inputs are always omitted in addition to these.
    |
    */
    'scrubbed_inputs' => [
        'password',
        'password_confirmation',
        '_token',
        'token',
        'api_key',
        'secret',
        'credit_card',
    ],

    /*
    |--------------------------------------------------------------------------
    | Captured Request Headers
    |--------------------------------------------------------------------------
    |
    | Which incoming request headers should be forwarded with each event.
    |
    */
    'captured_headers' => [
        'accept',
        'user-agent',
        'host',
        'referer',
        'x-forwarded-for',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for the dashboard to acknowledge an event before giving
    | up. Kept intentionally short so a slow tracker can't slow your app.
    |
    */
    'timeout' => env('ERROR_TRACKER_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Send User Details
    |--------------------------------------------------------------------------
    |
    | The logged-in user's id is always sent with exceptions and request
    | telemetry. Turn this on to also send their name and email, so the
    | dashboard can show who was affected. Off by default: names and emails
    | are personal data.
    |
    */
    'send_user_details' => env('ERROR_TRACKER_SEND_USER_DETAILS', false),

    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | Records one entry per HTTP request (route, method, status, duration and
    | user id) after the response has been sent, and one per queued job
    | attempt (class, queue, outcome, attempt and duration), and one per
    | scheduled task run (command, schedule, outcome and duration), plus slow
    | database queries and outgoing HTTP client calls, for the dashboard's
    | monitoring panels. Off by default.
    |
    | Entries are buffered on local disk and sent in batches every minute by
    | the `error-tracker:send-telemetry` command, which this package schedules
    | automatically. Your app only needs the scheduler running.
    |
    | url:                 Telemetry endpoint. Derived from `url` above
    |                      (…/api/errors → …/api/telemetry) when empty.
    | ignore_paths:        URL paths to skip, with * wildcards (e.g. "up").
    | ignore_routes:       Route names to skip, with * wildcards ("horizon.*").
    | ignore_jobs:         Job classes to skip, with * wildcards.
    | ignore_tasks:        Scheduled tasks to skip, with * wildcards, matched
    |                      against the command ("backup:*") or description.
    | slow_query_ms:       Database queries at least this slow are recorded,
    |                      with literal values stripped. 0 turns it off.
    | timeline_threshold_ms: Requests at least this slow carry their timeline
    |                      (the queries and outgoing calls they made, with
    |                      literal values stripped). 0 turns it off.
    | ignore_hosts:        Outgoing HTTP client calls to skip, by host with *
    |                      wildcards ("*.amazonaws.com"). Calls to the error
    |                      tracker itself are always skipped.
    | buffer_path:         Where the buffer lives. Defaults to
    |                      storage/framework/cache/error-tracker.
    | buffer_max_bytes:    Cap on the buffer. Past it, new entries are dropped
    |                      and the oldest unsent ones are discarded.
    | max_batches_per_run: Batches (1,000 entries each) sent per minute while
    |                      catching up after an outage.
    |
    */
    'telemetry' => [
        'enabled' => env('ERROR_TRACKER_TELEMETRY', false),

        'url' => env('ERROR_TRACKER_TELEMETRY_URL'),

        'ignore_paths' => [
            'up',
        ],

        'ignore_routes' => [
            //
        ],

        'ignore_jobs' => [
            //
        ],

        'ignore_tasks' => [
            //
        ],

        'slow_query_ms' => (float) env('ERROR_TRACKER_SLOW_QUERY_MS', 100),

        'timeline_threshold_ms' => (float) env('ERROR_TRACKER_TIMELINE_THRESHOLD_MS', 1000),

        'ignore_hosts' => [
            //
        ],

        'buffer_path' => env('ERROR_TRACKER_TELEMETRY_BUFFER_PATH'),

        'buffer_max_bytes' => (int) env('ERROR_TRACKER_TELEMETRY_BUFFER_MAX_BYTES', 10 * 1024 * 1024),

        'max_batches_per_run' => 10,
    ],

];
