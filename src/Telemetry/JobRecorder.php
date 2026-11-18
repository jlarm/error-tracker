<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records one telemetry entry per queued job attempt: processed, failed,
 * or released back onto the queue (after an exception, or by middleware
 * such as RateLimited).
 *
 * The worker can raise more than one "finished" event for an attempt (a job
 * past its attempts is failed and then reported as processed), so each
 * attempt is recorded once, on the first event that finishes it.
 */
class JobRecorder
{
    /**
     * Attempts remembered as already recorded, to drop duplicate events.
     */
    private const RECORDED_MEMORY = 200;

    /** @var array<string, float> */
    protected array $startedAt = [];

    /** @var array<string, true> */
    protected array $recorded = [];

    public function __construct(
        protected Config $config,
        protected TelemetryBuffer $buffer,
        protected TelemetrySwitch $switch,
    ) {}

    /**
     * Note when an attempt starts. Never throws.
     */
    public function started(Job $job): void
    {
        try {
            $this->startedAt[$this->key($job)] = microtime(true);

            // Attempts that never finish (a killed worker) must not pile up.
            if (count($this->startedAt) > self::RECORDED_MEMORY) {
                $this->startedAt = array_slice($this->startedAt, -self::RECORDED_MEMORY, null, true);
            }
        } catch (Throwable) {
            // Telemetry must never reach the job.
        }
    }

    /**
     * Record how an attempt ended. Never throws.
     *
     * @param  'processed'|'failed'|'released'  $outcome
     */
    public function finished(Job $job, string $outcome): void
    {
        try {
            $key = $this->key($job);
            $finishedAt = microtime(true);
            $startedAt = $this->startedAt[$key] ?? $finishedAt;
            unset($this->startedAt[$key]);

            if (isset($this->recorded[$key])) {
                return;
            }

            $this->remember($key);

            if (! $this->switch->isOn() || $this->shouldIgnore($job->resolveName())) {
                return;
            }

            $this->buffer->push([
                'type' => 'job',
                'timestamp' => CarbonImmutable::createFromTimestampUTC($startedAt)->format('Y-m-d\TH:i:s.uP'),
                'job' => $job->resolveName(),
                'queue' => $job->getQueue(),
                'connection' => $job->getConnectionName(),
                'outcome' => $outcome,
                'attempt' => $job->attempts(),
                'duration_ms' => round(max(0.0, $finishedAt - $startedAt) * 1000, 2),
            ]);
        } catch (Throwable) {
            // Telemetry must never reach the job.
        }
    }

    /**
     * How a job that ran to the end of its attempt ended up.
     *
     * @return 'processed'|'failed'|'released'
     */
    public function outcomeOf(Job $job): string
    {
        return match (true) {
            $job->hasFailed() => 'failed',
            $job->isReleased() => 'released',
            default => 'processed',
        };
    }

    /**
     * One key per attempt of a job.
     */
    protected function key(Job $job): string
    {
        return ($job->uuid() ?? $job->getJobId()).':'.$job->attempts();
    }

    protected function remember(string $key): void
    {
        $this->recorded[$key] = true;

        if (count($this->recorded) > self::RECORDED_MEMORY) {
            $this->recorded = array_slice($this->recorded, -self::RECORDED_MEMORY, null, true);
        }
    }

    protected function shouldIgnore(string $jobClass): bool
    {
        foreach ((array) $this->config->get('error-tracker.telemetry.ignore_jobs', []) as $pattern) {
            if (Str::is((string) $pattern, $jobClass)) {
                return true;
            }
        }

        return false;
    }
}
