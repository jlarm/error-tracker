<?php

use Armp\ErrorTracker\Telemetry\JobRecorder;
use Armp\ErrorTracker\Telemetry\TelemetryBuffer;
use Armp\ErrorTracker\Tests\Fixtures\ArrayTelemetryBuffer;
use Armp\ErrorTracker\Tests\Fixtures\Jobs\ExpiredJob;
use Armp\ErrorTracker\Tests\Fixtures\Jobs\RateLimitedJob;
use Armp\ErrorTracker\Tests\Fixtures\Jobs\ReleasingJob;
use Armp\ErrorTracker\Tests\Fixtures\Jobs\SelfFailingJob;
use Armp\ErrorTracker\Tests\Fixtures\Jobs\SucceedingJob;
use Armp\ErrorTracker\Tests\Fixtures\Jobs\ThrowingJob;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config([
        'error-tracker.telemetry.enabled' => true,
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
        'queue.failed' => [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ],
    ]);

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    $this->buffer = new ArrayTelemetryBuffer;
    $this->app->instance(TelemetryBuffer::class, $this->buffer);
    $this->app->forgetInstance(JobRecorder::class);
});

/**
 * Run the next job the way a real worker does.
 */
function workOnce(): void
{
    test()->artisan('queue:work', ['--once' => true, '--sleep' => 0]);
}

/**
 * Make a released job available again straight away.
 */
function makeReleasedJobsAvailable(): void
{
    DB::table('jobs')->update(['available_at' => now()->subSecond()->getTimestamp()]);
}

test('records a processed job with its class, queue, attempt and duration', function () {
    dispatch(new SucceedingJob);

    workOnce();

    expect($this->buffer->entries)->toHaveCount(1);

    $entry = $this->buffer->entries[0];

    expect($entry)
        ->type->toBe('job')
        ->job->toBe(SucceedingJob::class)
        ->queue->toBe('default')
        ->connection->toBe('database')
        ->outcome->toBe('processed')
        ->attempt->toBe(1)
        ->and($entry['duration_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0)
        ->and($entry['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/');
});

test('records a released attempt after an exception, then the final failure', function () {
    dispatch(new ThrowingJob);

    workOnce();
    makeReleasedJobsAvailable();
    workOnce();

    expect(array_map(fn (array $entry): array => [$entry['outcome'], $entry['attempt']], $this->buffer->entries))
        ->toBe([['released', 1], ['failed', 2]]);
});

test('records a job that releases itself', function () {
    dispatch(new ReleasingJob);

    workOnce();

    expect($this->buffer->entries)->toHaveCount(1)
        ->and($this->buffer->entries[0]['outcome'])->toBe('released');
});

test('records a RateLimited release as released, not processed', function () {
    RateLimiter::for('telemetry-test', fn () => Limit::perMinute(1));
    RateLimiter::hit(md5('telemetry-test'));

    dispatch(new RateLimitedJob);

    workOnce();

    expect($this->buffer->entries)->toHaveCount(1)
        ->and($this->buffer->entries[0])
        ->job->toBe(RateLimitedJob::class)
        ->outcome->toBe('released');

    expect(DB::table('jobs')->count())->toBe(1);
});

test('records a job that fails itself once, not as failed and processed', function () {
    dispatch(new SelfFailingJob);

    workOnce();

    expect($this->buffer->entries)->toHaveCount(1)
        ->and($this->buffer->entries[0]['outcome'])->toBe('failed');
});

test('records a job past its retry deadline once, as failed', function () {
    dispatch(new ExpiredJob);

    workOnce();

    expect($this->buffer->entries)->toHaveCount(1)
        ->and($this->buffer->entries[0]['outcome'])->toBe('failed');
});

test('skips ignored job classes', function () {
    config(['error-tracker.telemetry.ignore_jobs' => ['*\\SucceedingJob']]);

    dispatch(new SucceedingJob);
    dispatch(new ReleasingJob);

    workOnce();
    workOnce();

    expect($this->buffer->entries)->toHaveCount(1)
        ->and($this->buffer->entries[0]['job'])->toBe(ReleasingJob::class);
});

test('records nothing when telemetry is off', function () {
    config(['error-tracker.telemetry.enabled' => false]);

    dispatch(new SucceedingJob);

    workOnce();

    expect($this->buffer->entries)->toBeEmpty();
});

test('a failing buffer never affects the job', function () {
    $this->app->instance(TelemetryBuffer::class, new class implements TelemetryBuffer
    {
        public function push(array $entry): void
        {
            throw new RuntimeException('buffer is broken');
        }
    });
    $this->app->forgetInstance(JobRecorder::class);

    dispatch(new SucceedingJob);

    workOnce();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
