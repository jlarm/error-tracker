<?php

use Armp\ErrorTracker\Telemetry\RequestRecorder;
use Armp\ErrorTracker\Telemetry\TelemetryBuffer;
use Armp\ErrorTracker\Tests\Fixtures\ArrayTelemetryBuffer;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config(['error-tracker.telemetry.enabled' => true]);

    $this->buffer = new ArrayTelemetryBuffer;
    $this->app->instance(TelemetryBuffer::class, $this->buffer);

    Route::get('/tickets/{ticket}', fn () => 'ok')->name('tickets.show');
    Route::post('/api/help-desk/ping', fn () => response('created', 201))->name('help-desk.ping');
    Route::get('/up', fn () => 'up');
    Route::get('/horizon/dashboard', fn () => 'horizon')->name('horizon.index');
});

test('records the route pattern, method, status and duration of a request', function () {
    $this->get('/tickets/42')->assertOk();

    expect($this->buffer->entries)->toHaveCount(1);

    $entry = $this->buffer->entries[0];

    expect($entry)
        ->type->toBe('request')
        ->method->toBe('GET')
        ->route->toBe('/tickets/{ticket}')
        ->route_name->toBe('tickets.show')
        ->status->toBe(200)
        ->user_id->toBeNull()
        ->and($entry['duration_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0)
        ->and($entry['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/');
});

test('records the status the response actually returned', function () {
    $this->post('/api/help-desk/ping')->assertCreated();

    expect($this->buffer->entries[0])
        ->method->toBe('POST')
        ->route->toBe('/api/help-desk/ping')
        ->status->toBe(201);
});

test('records the id of a user the request already authenticated', function () {
    $this->actingAs(new GenericUser(['id' => 7]))->get('/tickets/1');

    expect($this->buffer->entries[0]['user_id'])->toBe(7);
});

test('records unmatched requests without a route, so raw urls never become routes', function () {
    $this->get('/no/such/page/123')->assertNotFound();

    expect($this->buffer->entries[0])
        ->route->toBeNull()
        ->status->toBe(404);
});

test('records nothing until the response has been sent', function () {
    $recorder = $this->app->make(RequestRecorder::class);

    $recorder->handled(Request::create('/tickets/1'), new Response('ok'));

    expect($this->buffer->entries)->toBeEmpty();

    $recorder->flush();

    expect($this->buffer->entries)->toHaveCount(1);
});

test('is off by default', function () {
    config(['error-tracker.telemetry.enabled' => false]);

    $this->get('/tickets/1');

    expect($this->buffer->entries)->toBeEmpty();
});

test('records nothing when reporting is disabled or no key is set', function (array $config) {
    config($config);

    $this->get('/tickets/1');

    expect($this->buffer->entries)->toBeEmpty();
})->with([
    'reporting disabled' => [['error-tracker.enabled' => false]],
    'no project key' => [['error-tracker.key' => null]],
]);

test('respects the environment filter', function () {
    config(['error-tracker.environments' => ['production']]);

    $this->get('/tickets/1');

    expect($this->buffer->entries)->toBeEmpty();
});

test('skips ignored paths, including the default health check', function () {
    config(['error-tracker.telemetry.ignore_paths' => ['up', 'tickets/*']]);

    $this->get('/up')->assertOk();
    $this->get('/tickets/9')->assertOk();
    $this->post('/api/help-desk/ping');

    expect($this->buffer->entries)->toHaveCount(1)
        ->and($this->buffer->entries[0]['route'])->toBe('/api/help-desk/ping');
});

test('skips ignored route names', function () {
    config(['error-tracker.telemetry.ignore_routes' => ['horizon.*']]);

    $this->get('/horizon/dashboard')->assertOk();
    $this->get('/tickets/1')->assertOk();

    expect($this->buffer->entries)->toHaveCount(1)
        ->and($this->buffer->entries[0]['route_name'])->toBe('tickets.show');
});

test('a failing buffer never reaches the app', function () {
    $this->app->instance(TelemetryBuffer::class, new class implements TelemetryBuffer
    {
        public function push(array $entry): void
        {
            throw new RuntimeException('buffer is broken');
        }
    });

    $this->get('/tickets/1')->assertOk()->assertSee('ok');
});

test('each request is recorded once', function () {
    $this->get('/tickets/1');
    $this->get('/tickets/2');

    expect($this->buffer->entries)->toHaveCount(2);
});

test('labels Inertia partial reloads with the props they asked for', function () {
    Route::get('/dashboard', fn () => 'ok')->name('dashboard');

    $this->get('/dashboard', ['X-Inertia' => 'true', 'X-Inertia-Partial-Data' => 'weeklyDigestSummary'])->assertOk();

    expect($this->buffer->entries[0])
        ->route->toBe('/dashboard (partial: weeklyDigestSummary)')
        ->route_name->toBe('dashboard');
});

test('lists partial props sorted and once, so the label is stable', function () {
    Route::get('/dashboard', fn () => 'ok');

    $this->get('/dashboard', ['X-Inertia' => 'true', 'X-Inertia-Partial-Data' => 'pipeline, activity,pipeline'])->assertOk();

    expect($this->buffer->entries[0]['route'])->toBe('/dashboard (partial: activity, pipeline)');
});

test('labels partial reloads that exclude props', function () {
    Route::get('/dashboard', fn () => 'ok');

    $this->get('/dashboard', ['X-Inertia' => 'true', 'X-Inertia-Partial-Except' => 'dealerships'])->assertOk();

    expect($this->buffer->entries[0]['route'])->toBe('/dashboard (partial: all except dealerships)');
});

test('leaves full page loads and non-Inertia requests unlabelled', function (array $headers) {
    Route::get('/dashboard', fn () => 'ok');

    $this->get('/dashboard', $headers)->assertOk();

    expect($this->buffer->entries[0]['route'])->toBe('/dashboard');
})->with([
    'browser page load' => [[]],
    'full Inertia visit' => [['X-Inertia' => 'true']],
    'partial header without Inertia' => [['X-Inertia-Partial-Data' => 'weeklyDigestSummary']],
]);

test('keeps long partial labels within the tracker route limit', function () {
    Route::get('/dashboard', fn () => 'ok');

    $props = implode(',', array_map(fn (int $i): string => "someVeryLongDeferredPropName{$i}", range(1, 20)));

    $this->get('/dashboard', ['X-Inertia' => 'true', 'X-Inertia-Partial-Data' => $props])->assertOk();

    $route = $this->buffer->entries[0]['route'];

    expect(mb_strlen($route))->toBe(255)
        ->and($route)->toStartWith('/dashboard (partial: someVeryLongDeferredPropName1, ')
        ->and($route)->toEndWith('…)');
});
