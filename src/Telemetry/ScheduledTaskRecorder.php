<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records one telemetry entry per scheduled task run: finished, failed or
 * skipped, with its cron expression so the dashboard can spot missed runs.
 *
 * A command that exits non-zero raises "finished" and then "failed", so each
 * run is recorded once, on the first event that ends it. Tasks run in the
 * background are recorded when `schedule:finish` reports their exit code.
 */
class ScheduledTaskRecorder
{
    /** @var array<int, float> */
    protected array $startedAt = [];

    /** @var array<int, true> */
    protected array $recorded = [];

    public function __construct(
        protected Config $config,
        protected TelemetryBuffer $buffer,
        protected TelemetrySwitch $switch,
    ) {}

    /**
     * Note when a run starts. Never throws.
     */
    public function starting(Event $task): void
    {
        try {
            $this->startedAt[spl_object_id($task)] = microtime(true);
            unset($this->recorded[spl_object_id($task)]);
        } catch (Throwable) {
            // Telemetry must never reach the scheduler.
        }
    }

    /**
     * A run ended in the scheduler's process. Background runs are only
     * spawned here; their result arrives through backgroundFinished().
     */
    public function finished(Event $task): void
    {
        if ($task->runInBackground) {
            return;
        }

        $this->record($task, (int) $task->exitCode === 0 ? 'finished' : 'failed');
    }

    /**
     * A run threw, or exited non-zero (in which case it is already recorded).
     */
    public function failed(Event $task): void
    {
        $this->record($task, 'failed');
    }

    /**
     * A due run did not go ahead: paused, its condition not met, or it ran
     * on another server.
     */
    public function skipped(Event $task): void
    {
        $this->record($task, 'skipped');
    }

    /**
     * A background run finished; `schedule:finish` set its exit code. Its
     * start time lives in another process, so no duration is known.
     */
    public function backgroundFinished(Event $task): void
    {
        $this->record($task, (int) $task->exitCode === 0 ? 'finished' : 'failed', withDuration: false);
    }

    /**
     * @param  'finished'|'failed'|'skipped'  $outcome
     */
    protected function record(Event $task, string $outcome, bool $withDuration = true): void
    {
        try {
            $key = spl_object_id($task);

            if (isset($this->recorded[$key])) {
                return;
            }

            $this->recorded[$key] = true;
            $endedAt = microtime(true);
            $startedAt = $this->startedAt[$key] ?? $endedAt;
            unset($this->startedAt[$key]);

            $name = $this->nameOf($task);

            if (! $this->switch->isOn() || $this->shouldIgnore($name)) {
                return;
            }

            $this->buffer->push([
                'type' => 'scheduled_task',
                'timestamp' => CarbonImmutable::createFromTimestampUTC($startedAt)->format('Y-m-d\TH:i:s.uP'),
                'task' => $name,
                'expression' => $task->expression,
                'timezone' => $this->timezoneOf($task),
                'outcome' => $outcome,
                'exit_code' => $outcome === 'skipped' || $task->exitCode === null ? null : (int) $task->exitCode,
                'duration_ms' => $withDuration && $outcome !== 'skipped'
                    ? round(max(0.0, $endedAt - $startedAt) * 1000, 2)
                    : null,
            ]);
        } catch (Throwable) {
            // Telemetry must never reach the scheduler.
        }
    }

    /**
     * How a task is named on the dashboard: the artisan command and its
     * arguments ("reports:send --daily"), a shell command, or the task's
     * description for closures and jobs.
     */
    public function nameOf(Event $task): string
    {
        if ($task instanceof CallbackEvent || ! is_string($task->command) || $task->command === '') {
            return Str::limit((string) ($task->description ?: 'Closure'), 250, '');
        }

        $command = preg_replace("#^.*?['\"]?artisan['\"]?\\s+#", '', $task->command) ?? $task->command;

        return Str::limit(trim($command), 250, '');
    }

    protected function timezoneOf(Event $task): ?string
    {
        $timezone = $task->timezone;

        if ($timezone instanceof DateTimeZone) {
            return $timezone->getName();
        }

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    protected function shouldIgnore(string $task): bool
    {
        $patterns = [
            'error-tracker:send-telemetry*',
            ...(array) $this->config->get('error-tracker.telemetry.ignore_tasks', []),
        ];

        foreach ($patterns as $pattern) {
            if (Str::is((string) $pattern, $task)) {
                return true;
            }
        }

        return false;
    }
}
