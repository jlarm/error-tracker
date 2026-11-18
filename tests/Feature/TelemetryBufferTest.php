<?php

use Armp\ErrorTracker\Telemetry\FileTelemetryBuffer;
use Armp\ErrorTracker\Telemetry\TelemetryBuffer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/error-tracker-buffer-'.bin2hex(random_bytes(4));
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

test('appends each entry as a line of json, creating the directory', function () {
    $buffer = new FileTelemetryBuffer($this->directory, 1024 * 1024);

    $buffer->push(['route' => '/a']);
    $buffer->push(['route' => '/b']);

    expect(file($buffer->activePath(), FILE_IGNORE_NEW_LINES))
        ->toBe(['{"route":"/a"}', '{"route":"/b"}']);
});

test('drops new entries once the active file reaches the cap', function () {
    $buffer = new FileTelemetryBuffer($this->directory, 40);

    foreach (range(1, 10) as $i) {
        $buffer->push(['route' => "/r{$i}"]);
    }

    expect(filesize($buffer->activePath()))->toBeLessThanOrEqual(40 + strlen('{"route":"/r10"}') + 1)
        ->and(count(file($buffer->activePath())))->toBeLessThan(10);
});

test('never throws when the directory cannot be written', function () {
    $blocker = tempnam(sys_get_temp_dir(), 'not-a-dir');

    (new FileTelemetryBuffer($blocker.'/nested', 1024))->push(['route' => '/a']);

    unlink($blocker);
})->throwsNoExceptions();

test('rotating moves the active file aside so new entries start fresh', function () {
    $buffer = new FileTelemetryBuffer($this->directory, 1024 * 1024);
    $buffer->push(['route' => '/before']);

    $pending = $buffer->rotate();
    $buffer->push(['route' => '/after']);

    expect($buffer->pendingFiles())->toBe([$pending])
        ->and($buffer->read($pending))->toBe([['route' => '/before']])
        ->and(file($buffer->activePath(), FILE_IGNORE_NEW_LINES))->toBe(['{"route":"/after"}'])
        ->and($buffer->rotate())->not->toBeNull()
        ->and((new FileTelemetryBuffer($this->directory.'-empty', 1024))->rotate())->toBeNull();
});

test('enforcing the cap discards the oldest pending files first', function () {
    $buffer = new FileTelemetryBuffer($this->directory, 90);

    $files = [];

    foreach (['/old', '/mid', '/new'] as $route) {
        $buffer->push(['route' => $route, 'padding' => str_repeat('x', 10)]);
        $files[$route] = $buffer->rotate();
        usleep(1000);
    }

    expect($buffer->enforceCap())->toBe(1)
        ->and($buffer->pendingFiles())->toBe([$files['/mid'], $files['/new']]);
});

test('skips corrupt lines when reading', function () {
    $buffer = new FileTelemetryBuffer($this->directory, 1024);
    File::ensureDirectoryExists($this->directory);
    file_put_contents($buffer->activePath(), "{\"route\":\"/ok\"}\n{\"route\":\"/cut-sho\n\n");

    expect($buffer->read($buffer->activePath()))->toBe([['route' => '/ok']]);
});

test('is the default buffer, so recorded requests land on disk', function () {
    config([
        'error-tracker.telemetry.enabled' => true,
        'error-tracker.telemetry.buffer_path' => $this->directory,
    ]);
    $this->app->forgetInstance(FileTelemetryBuffer::class);
    $this->app->forgetInstance(TelemetryBuffer::class);

    Route::get('/tickets/{ticket}', fn () => 'ok');

    $this->get('/tickets/5')->assertOk();

    expect(app(TelemetryBuffer::class))->toBeInstanceOf(FileTelemetryBuffer::class)
        ->and(app(FileTelemetryBuffer::class)->read($this->directory.'/telemetry.ndjson'))
        ->toHaveCount(1)
        ->sequence(fn ($entry) => $entry->route->toBe('/tickets/{ticket}'));
});
