<?php

use Armp\ErrorTracker\Telemetry\ExecutionContext;
use Armp\ErrorTracker\Telemetry\OutgoingRequestRecorder;
use Armp\ErrorTracker\Telemetry\TelemetryBuffer;
use Armp\ErrorTracker\Tests\Fixtures\ArrayTelemetryBuffer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['error-tracker.telemetry.enabled' => true]);

    $this->buffer = new ArrayTelemetryBuffer;
    $this->app->instance(TelemetryBuffer::class, $this->buffer);
    $this->app->forgetInstance(OutgoingRequestRecorder::class);
    $this->app->forgetInstance(ExecutionContext::class);
});

/**
 * @return list<array<string, mixed>>
 */
function outgoingEntries(): array
{
    return array_values(array_filter(test()->buffer->entries, fn (array $entry) => $entry['type'] === 'outgoing_request'));
}

test('records the method, host and status of a call, without its path or query', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['ok' => true], 402)]);
    $this->app->make(ExecutionContext::class)->enter('App\\Jobs\\ChargeCustomer');

    Http::post('https://API.stripe.com/v1/charges?customer=cus_123', ['amount' => 100]);

    expect(outgoingEntries())->toHaveCount(1)
        ->and(outgoingEntries()[0])->toMatchArray([
            'method' => 'POST',
            'host' => 'https://api.stripe.com',
            'status' => 402,
            'origin' => 'App\\Jobs\\ChargeCustomer',
        ]);
});

test('records a failed connection without a status', function () {
    Http::fake(['flaky.test*' => Http::failedConnection('Could not resolve host')]);

    try {
        Http::get('http://flaky.test:8080/health');
    } catch (ConnectionException) {
        //
    }

    expect(outgoingEntries())->toHaveCount(1)
        ->and(outgoingEntries()[0])->toMatchArray([
            'host' => 'http://flaky.test:8080',
            'status' => null,
            'duration_ms' => null,
        ]);
});

test('skips calls to the error tracker and to ignored hosts', function () {
    config(['error-tracker.telemetry.ignore_hosts' => ['*.amazonaws.com']]);
    Http::fake();

    Http::post('https://tracker.test/api/telemetry', []);
    Http::get('https://bucket.s3.amazonaws.com/file');
    Http::get('https://example.com/');

    expect(array_column(outgoingEntries(), 'host'))->toBe(['https://example.com']);
});

test('describes hosts with only non-default ports', function (string $url, ?string $host) {
    expect(OutgoingRequestRecorder::hostOf($url))->toBe($host);
})->with([
    ['https://api.example.com:443/a', 'https://api.example.com'],
    ['http://localhost:8000/a?b=c', 'http://localhost:8000'],
    ['not a url', null],
]);

test('records nothing while telemetry is off', function () {
    config(['error-tracker.telemetry.enabled' => false]);
    Http::fake();

    Http::get('https://example.com/');

    expect(outgoingEntries())->toBeEmpty();
});
