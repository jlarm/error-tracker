<?php

use Armp\ErrorTracker\ReleaseDetector;
use Armp\ErrorTracker\Telemetry\FileTelemetryBuffer;
use Illuminate\Support\Facades\Http;

test('detects the release folder of a zero-downtime deploy', function (string $path, ?string $release) {
    expect(ReleaseDetector::fromPath($path))->toBe($release);
})->with([
    'ploi' => ['/home/ploi/crm.example.com-deploy/03112026_195654', '03112026_195654'],
    'envoyer' => ['/home/forge/app.com/releases/20261118120000/', '20261118120000'],
    'nested releases' => ['/srv/releases/old/releases/42', '42'],
    'plain checkout' => ['/home/forge/app.com', null],
]);

test('sends the release with each telemetry batch', function () {
    config([
        'error-tracker.telemetry.enabled' => true,
        'error-tracker.release' => 'v2.4.0',
        'error-tracker.telemetry.buffer_path' => $path = sys_get_temp_dir().'/error-tracker-release-'.uniqid(),
    ]);
    $this->app->forgetInstance(FileTelemetryBuffer::class);
    $this->app->singleton(FileTelemetryBuffer::class, fn () => new FileTelemetryBuffer($path, 1024 * 1024));
    $this->app->make(FileTelemetryBuffer::class)->push(['type' => 'request', 'timestamp' => now()->toIso8601String()]);
    Http::fake(['tracker.test/*' => Http::response(['accepted' => 1], 202)]);

    $this->artisan('error-tracker:send-telemetry')->assertSuccessful();

    Http::assertSent(fn ($request) => $request['release'] === 'v2.4.0' && count($request['entries']) === 1);
});
