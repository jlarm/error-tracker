<?php

use Armp\ErrorTracker\Telemetry\ScheduledTaskRecorder;
use Armp\ErrorTracker\Telemetry\TelemetryBuffer;
use Armp\ErrorTracker\Tests\Fixtures\ArrayTelemetryBuffer;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    config(['error-tracker.telemetry.enabled' => true]);

    $this->buffer = new ArrayTelemetryBuffer;
    $this->app->instance(TelemetryBuffer::class, $this->buffer);
    $this->app->forgetInstance(ScheduledTaskRecorder::class);
});

/**
 * Entries for scheduled tasks only (the package also schedules its own
 * sender, which is never recorded).
 *
 * @return list<array<string, mixed>>
 */
function taskEntries(): array
{
    return array_values(array_filter(
        test()->buffer->entries,
        fn (array $entry) => $entry['type'] === 'scheduled_task',
    ));
}

test('records a finished closure task with its schedule and duration', function () {
    $this->app->make(Schedule::class)->call(fn () => usleep(2000))->everyMinute()->name('reports:refresh');

    $this->artisan('schedule:run')->assertSuccessful();

    expect(taskEntries())->toHaveCount(1);

    $entry = taskEntries()[0];

    expect($entry['task'])->toBe('reports:refresh')
        ->and($entry['expression'])->toBe('* * * * *')
        ->and($entry['outcome'])->toBe('finished')
        ->and($entry['exit_code'])->toBe(0)
        ->and($entry['duration_ms'])->toBeGreaterThan(1)
        ->and($entry['timestamp'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}\+00:00$/');
});

test('records a task that throws as failed, once', function () {
    $this->app->make(Schedule::class)->call(function () {
        throw new RuntimeException('Nope');
    })->everyMinute()->name('imports:pull');

    $this->artisan('schedule:run');

    expect(taskEntries())->toHaveCount(1)
        ->and(taskEntries()[0]['outcome'])->toBe('failed')
        ->and(taskEntries()[0]['task'])->toBe('imports:pull');
});

test('records a due task whose condition was not met as skipped', function () {
    $this->app->make(Schedule::class)->call(fn () => null)->everyMinute()->name('nightly:sync')->when(false);

    $this->artisan('schedule:run');

    expect(taskEntries())->toHaveCount(1)
        ->and(taskEntries()[0]['outcome'])->toBe('skipped')
        ->and(taskEntries()[0]['duration_ms'])->toBeNull()
        ->and(taskEntries()[0]['exit_code'])->toBeNull();
});

test('a command that exits non-zero is recorded as failed, once', function () {
    $recorder = $this->app->make(ScheduledTaskRecorder::class);
    $task = new Event($this->app->make(CacheEventMutex::class), "'/usr/bin/php8.4' 'artisan' reports:send --daily");
    $task->dailyAt('06:00');

    $recorder->starting($task);
    $task->exitCode = 1;
    $recorder->finished($task);
    $recorder->failed($task);

    expect(taskEntries())->toHaveCount(1)
        ->and(taskEntries()[0])->toMatchArray([
            'task' => 'reports:send --daily',
            'expression' => '0 6 * * *',
            'outcome' => 'failed',
            'exit_code' => 1,
        ]);
});

test('a background task is recorded when schedule:finish reports it', function () {
    $recorder = $this->app->make(ScheduledTaskRecorder::class);
    $task = (new Event($this->app->make(CacheEventMutex::class), "'php' 'artisan' backups:run"))->hourly()->runInBackground();

    $recorder->starting($task);
    $recorder->finished($task);
    expect(taskEntries())->toBeEmpty();

    $task->exitCode = 0;
    $recorder->backgroundFinished($task);

    expect(taskEntries())->toHaveCount(1)
        ->and(taskEntries()[0])->toMatchArray([
            'task' => 'backups:run',
            'outcome' => 'finished',
            'duration_ms' => null,
        ]);
});

test('ignored tasks and the telemetry sender are not recorded', function () {
    config(['error-tracker.telemetry.ignore_tasks' => ['backups:*']]);
    $recorder = $this->app->make(ScheduledTaskRecorder::class);

    foreach (['backups:run', 'error-tracker:send-telemetry'] as $command) {
        $task = new Event($this->app->make(CacheEventMutex::class), "'php' 'artisan' {$command}");
        $task->exitCode = 0;
        $recorder->starting($task);
        $recorder->finished($task);
    }

    expect(taskEntries())->toBeEmpty();
});

test('nothing is recorded while telemetry is off', function () {
    config(['error-tracker.telemetry.enabled' => false]);
    $this->app->make(Schedule::class)->call(fn () => null)->everyMinute()->name('reports:refresh');

    $this->artisan('schedule:run');

    expect(taskEntries())->toBeEmpty();
});
