<?php

use Armp\ErrorTracker\ErrorTracker;
use Armp\ErrorTracker\Telemetry\TelemetryBuffer;
use Armp\ErrorTracker\Tests\Fixtures\ArrayTelemetryBuffer;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['error-tracker.telemetry.enabled' => true]);

    $this->buffer = new ArrayTelemetryBuffer;
    $this->app->instance(TelemetryBuffer::class, $this->buffer);

    $this->user = new GenericUser(['id' => 7, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test']);

    Route::get('/tickets', fn () => 'ok');
    Route::get('/boom', fn () => throw new RuntimeException('boom'));

    Http::fake(['*' => Http::response(['id' => 1], 202)]);
});

/**
 * The user block of the exception report sent to the tracker.
 *
 * @return array<string, mixed>|null
 */
function reportedUser(): ?array
{
    $sent = collect(Http::recorded())
        ->map(fn (array $pair): ClientRequest => $pair[0])
        ->first(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/api/errors'));

    expect($sent)->not->toBeNull();

    return $sent['context']['user'] ?? null;
}

test('requests from guests carry no user', function () {
    $this->get('/tickets')->assertOk();

    expect($this->buffer->entries[0])
        ->user_id->toBeNull()
        ->user_name->toBeNull()
        ->user_email->toBeNull();
});

test('requests carry only the user id by default', function () {
    $this->actingAs($this->user)->get('/tickets')->assertOk();

    expect($this->buffer->entries[0])
        ->user_id->toBe(7)
        ->user_name->toBeNull()
        ->user_email->toBeNull();
});

test('requests carry the user name and email when details are turned on', function () {
    config(['error-tracker.send_user_details' => true]);

    $this->actingAs($this->user)->get('/tickets')->assertOk();

    expect($this->buffer->entries[0])
        ->user_id->toBe(7)
        ->user_name->toBe('Ada Lovelace')
        ->user_email->toBe('ada@example.test');
});

test('exceptions from guests carry no user', function () {
    $this->get('/boom')->assertServerError();

    expect(reportedUser())->toBeNull();
});

test('exceptions carry only the user id by default', function () {
    $this->actingAs($this->user)->get('/boom')->assertServerError();

    expect(reportedUser())->toBe(['id' => 7]);
});

test('exceptions carry the user name and email when details are turned on', function () {
    config(['error-tracker.send_user_details' => true]);

    $this->actingAs($this->user)->get('/boom')->assertServerError();

    expect(reportedUser())->toBe(['id' => 7, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
});

test('a user set by the app takes precedence over the logged-in user', function () {
    $this->actingAs($this->user);

    app(ErrorTracker::class)
        ->user(['id' => 'customer-42', 'plan' => 'pro'])
        ->capture(new RuntimeException('boom'));

    expect(reportedUser())->toBe(['id' => 'customer-42', 'plan' => 'pro']);
});

test('reporting never looks up a user the request had not loaded', function () {
    $this->app['auth']->guard()->setUser($this->user);
    $this->app['auth']->forgetGuards();

    app(ErrorTracker::class)->capture(new RuntimeException('boom'));

    expect(reportedUser())->toBeNull();
});
