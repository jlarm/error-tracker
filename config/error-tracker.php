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
    'url' => env('ERROR_TRACKER_URL', 'https://error-tracking.test/api/errors'),

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

];
