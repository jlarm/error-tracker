<?php

use Armp\ErrorTracker\Console\SendTelemetryCommand;
use Armp\ErrorTracker\ErrorTrackerServiceProvider;
use Armp\ErrorTracker\Telemetry\FileTelemetryBuffer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/error-tracker-send-'.bin2hex(random_bytes(4));

    config([
        'error-tracker.telemetry.enabled' => true,
        'error-tracker.telemetry.buffer_path' => $this->directory,
    ]);

    $this->app->forgetInstance(FileTelemetryBuffer::class);
    $this->buffer = app(FileTelemetryBuffer::class);
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

function bufferEntries(FileTelemetryBuffer $buffer, int $count): void
{
    foreach (range(1, $count) as $i) {
        $buffer->push(['type' => 'request', 'route' => "/r{$i}", 'status' => 200]);
    }
}

test('sends buffered entries to the telemetry endpoint and clears them', function () {
    Http::fake(['*' => Http::response(['accepted' => 3, 'rejected' => 0], 202)]);
    bufferEntries($this->buffer, 3);

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tracker.test/api/telemetry'
        && $request->hasHeader('Authorization', 'Bearer test-project-key')
        && count($request['entries']) === 3
        && $request['entries'][0]['route'] === '/r1');

    expect($this->buffer->pendingFiles())->toBeEmpty()
        ->and(file_exists($this->buffer->activePath()))->toBeFalse();
});

test('sends nothing when the buffer is empty', function () {
    Http::fake();

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    Http::assertNothingSent();
});

test('splits a backlog into batches of at most 1,000', function () {
    Http::fake(['*' => Http::response([], 202)]);
    bufferEntries($this->buffer, SendTelemetryCommand::BATCH_SIZE + 5);

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    $sizes = collect(Http::recorded())->map(fn (array $pair): int => count($pair[0]['entries']))->all();

    expect($sizes)->toBe([SendTelemetryCommand::BATCH_SIZE, 5])
        ->and($this->buffer->pendingFiles())->toBeEmpty();
});

test('keeps entries for the next run when the tracker is down', function (Closure $trackerDown) {
    $trackerIsUp = false;
    Http::fake(['*' => function () use (&$trackerIsUp, $trackerDown) {
        return $trackerIsUp ? Http::response([], 202) : $trackerDown();
    }]);
    bufferEntries($this->buffer, 3);

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    $pending = $this->buffer->pendingFiles();

    expect($pending)->toHaveCount(1)
        ->and($this->buffer->read($pending[0]))->toHaveCount(3);

    $trackerIsUp = true;

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    expect($this->buffer->pendingFiles())->toBeEmpty();
})->with([
    'server error' => [fn () => Http::response('down', 503)],
    'connection refused' => [fn () => throw new ConnectionException('Connection refused')],
    'wrong api key' => [fn () => Http::response('unauthorized', 401)],
]);

test('keeps only the unsent remainder when a later batch fails', function () {
    $calls = 0;
    Http::fake(['*' => function () use (&$calls) {
        return ++$calls === 1 ? Http::response([], 202) : Http::response('down', 503);
    }]);
    bufferEntries($this->buffer, SendTelemetryCommand::BATCH_SIZE + 5);

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    $remaining = $this->buffer->read($this->buffer->pendingFiles()[0]);

    expect($remaining)->toHaveCount(5)
        ->and($remaining[0]['route'])->toBe('/r'.(SendTelemetryCommand::BATCH_SIZE + 1));
});

test('drops a batch the tracker refuses as malformed instead of retrying it forever', function () {
    Http::fake(['*' => Http::response(['message' => 'invalid'], 422)]);
    bufferEntries($this->buffer, 2);

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    expect($this->buffer->pendingFiles())->toBeEmpty();
});

test('sends at most the configured number of batches per run', function () {
    config(['error-tracker.telemetry.max_batches_per_run' => 1]);
    Http::fake(['*' => Http::response([], 202)]);
    bufferEntries($this->buffer, SendTelemetryCommand::BATCH_SIZE + 5);

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    Http::assertSentCount(1);
    expect($this->buffer->read($this->buffer->pendingFiles()[0]))->toHaveCount(5);
});

test('discards the oldest unsent entries once the buffer cap is reached', function () {
    config(['error-tracker.telemetry.buffer_max_bytes' => 200]);
    $this->app->forgetInstance(FileTelemetryBuffer::class);
    $buffer = app(FileTelemetryBuffer::class);
    Http::fake(['*' => Http::response('down', 503)]);

    foreach (range(1, 6) as $run) {
        bufferEntries($buffer, 3);
        $this->artisan('error-tracker:send-telemetry')->assertSuccessful();
        usleep(1000);
    }

    $pendingBytes = array_sum(array_map('filesize', $buffer->pendingFiles()));

    expect($pendingBytes)->toBeLessThanOrEqual(200)
        ->and($buffer->pendingFiles())->not->toBeEmpty();
});

test('does nothing when telemetry or reporting is off', function (array $config) {
    config($config);
    Http::fake();
    bufferEntries($this->buffer, 2);

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    Http::assertNothingSent();
})->with([
    'telemetry off' => [['error-tracker.telemetry.enabled' => false]],
    'reporting off' => [['error-tracker.enabled' => false]],
    'no api key' => [['error-tracker.key' => null]],
    'no endpoint can be derived' => [['error-tracker.url' => 'https://tracker.test/custom-ingest']],
]);

test('uses an explicitly configured telemetry url', function () {
    config(['error-tracker.telemetry.url' => 'https://elsewhere.test/collect']);
    Http::fake(['*' => Http::response([], 202)]);
    bufferEntries($this->buffer, 1);

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://elsewhere.test/collect');
});

test('is scheduled every minute only when telemetry is enabled', function () {
    $sendEvents = fn () => collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'error-tracker:send-telemetry'));

    // Booted with telemetry off (the default): nothing scheduled.
    expect($sendEvents())->toBeEmpty();

    // The provider reads the setting at boot, so boot it again with it on.
    $this->app->register(ErrorTrackerServiceProvider::class, force: true);

    expect($sendEvents())->toHaveCount(1)
        ->and($sendEvents()->first()->expression)->toBe('* * * * *');
});
