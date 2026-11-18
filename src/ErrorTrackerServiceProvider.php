<?php

declare(strict_types=1);

namespace Armp\ErrorTracker;

use Armp\ErrorTracker\Console\SendTelemetryCommand;
use Armp\ErrorTracker\Console\TestCommand;
use Armp\ErrorTracker\Telemetry\ExecutionContext;
use Armp\ErrorTracker\Telemetry\FileTelemetryBuffer;
use Armp\ErrorTracker\Telemetry\JobRecorder;
use Armp\ErrorTracker\Telemetry\OutgoingRequestRecorder;
use Armp\ErrorTracker\Telemetry\QueryRecorder;
use Armp\ErrorTracker\Telemetry\RequestRecorder;
use Armp\ErrorTracker\Telemetry\RequestSpans;
use Armp\ErrorTracker\Telemetry\ScheduledTaskRecorder;
use Armp\ErrorTracker\Telemetry\TelemetryBuffer;
use Armp\ErrorTracker\Telemetry\TelemetrySwitch;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ErrorTrackerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/error-tracker.php', 'error-tracker');

        $this->app->singleton(ErrorTracker::class, fn ($app) => new ErrorTracker(
            $app->make(Config::class),
            $app->make(CurrentUser::class),
        ));

        $this->app->alias(ErrorTracker::class, 'error-tracker');

        $this->app->singleton(FileTelemetryBuffer::class, fn ($app) => new FileTelemetryBuffer(
            (string) ($app->make(Config::class)->get('error-tracker.telemetry.buffer_path') ?: storage_path('framework/cache/error-tracker')),
            (int) $app->make(Config::class)->get('error-tracker.telemetry.buffer_max_bytes', 10 * 1024 * 1024),
        ));
        $this->app->singletonIf(TelemetryBuffer::class, FileTelemetryBuffer::class);
        $this->app->singleton(TelemetrySwitch::class);
        $this->app->scoped(RequestRecorder::class);
        // A singleton, not scoped: workers forget scoped instances between
        // jobs, which would lose each attempt's start time.
        $this->app->singleton(JobRecorder::class);
        $this->app->singleton(ScheduledTaskRecorder::class);
        $this->app->singleton(ExecutionContext::class);
        $this->app->singleton(RequestSpans::class);
        $this->app->singleton(QueryRecorder::class);
        $this->app->singleton(OutgoingRequestRecorder::class);
    }

    public function boot(): void
    {
        $config = $this->app->make(Config::class);

        if (blank($config->get('error-tracker.release'))) {
            $config->set('error-tracker.release', ReleaseDetector::fromPath($this->app->basePath()));
        }

        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) {
                    $this->app->make(ErrorTracker::class)->capture($e, request());
                });
            }
        });

        $this->registerRequestTelemetry();
        $this->registerJobTelemetry();
        $this->registerScheduledTaskTelemetry();
        $this->registerExecutionContext();
        $this->registerQueryTelemetry();
        $this->registerOutgoingRequestTelemetry();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/error-tracker.php' => config_path('error-tracker.php'),
            ], 'error-tracker-config');

            $this->commands([
                TestCommand::class,
                SendTelemetryCommand::class,
            ]);
        }
    }

    /**
     * Note each handled request, then record it once the response has been
     * sent, so telemetry never adds to the visitor's wait. When telemetry is
     * on, schedule the buffer to be sent every minute.
     */
    protected function registerRequestTelemetry(): void
    {
        $this->app->make(Dispatcher::class)->listen(RequestHandled::class, function (RequestHandled $event): void {
            try {
                $this->app->make(RequestRecorder::class)->handled($event->request, $event->response);
            } catch (Throwable) {
                // Telemetry must never reach the host app.
            }
        });

        if ($this->app->make(Config::class)->get('error-tracker.telemetry.enabled', false)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command(SendTelemetryCommand::class)
                    ->everyMinute()
                    ->withoutOverlapping();
            });
        }

        $this->app->terminating(function (): void {
            try {
                $this->app->make(RequestRecorder::class)->flush();
            } catch (Throwable) {
                // Telemetry must never reach the host app.
            }
        });
    }

    /**
     * Record each queued job attempt: when it starts, and how it ends.
     */
    protected function registerJobTelemetry(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $record = function (callable $callback): callable {
            return function (object $event) use ($callback): void {
                try {
                    $callback($this->app->make(JobRecorder::class), $event);
                } catch (Throwable) {
                    // Telemetry must never reach the job.
                }
            };
        };

        $events->listen(JobProcessing::class, $record(
            fn (JobRecorder $recorder, JobProcessing $event) => $recorder->started($event->job),
        ));

        $events->listen(JobProcessed::class, $record(
            fn (JobRecorder $recorder, JobProcessed $event) => $recorder->finished($event->job, $recorder->outcomeOf($event->job)),
        ));

        $events->listen(JobReleasedAfterException::class, $record(
            fn (JobRecorder $recorder, JobReleasedAfterException $event) => $recorder->finished($event->job, 'released'),
        ));

        $events->listen(JobFailed::class, $record(
            fn (JobRecorder $recorder, JobFailed $event) => $recorder->finished($event->job, 'failed'),
        ));
    }

    /**
     * Record each scheduled task run: when it starts, and how it ends.
     */
    protected function registerScheduledTaskTelemetry(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $record = function (string $method): callable {
            return function (object $event) use ($method): void {
                try {
                    $this->app->make(ScheduledTaskRecorder::class)->{$method}($event->task);
                } catch (Throwable) {
                    // Telemetry must never reach the scheduler.
                }
            };
        };

        $events->listen(ScheduledTaskStarting::class, $record('starting'));
        $events->listen(ScheduledTaskFinished::class, $record('finished'));
        $events->listen(ScheduledTaskFailed::class, $record('failed'));
        $events->listen(ScheduledTaskSkipped::class, $record('skipped'));
        $events->listen(ScheduledBackgroundTaskFinished::class, $record('backgroundFinished'));
    }

    /**
     * Track what the app is doing (route, job or command), to label slow
     * queries and outgoing requests with where they came from.
     */
    protected function registerExecutionContext(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $context = function (callable $callback): callable {
            return function (object $event) use ($callback): void {
                try {
                    $callback($this->app->make(ExecutionContext::class), $event);
                } catch (Throwable) {
                    // Telemetry must never reach the host app.
                }
            };
        };

        $events->listen(RouteMatched::class, $context(
            fn (ExecutionContext $context, RouteMatched $event) => $context->enter(
                $event->request->getMethod().' /'.ltrim($event->route->uri(), '/'),
            ),
        ));

        $events->listen(JobProcessing::class, $context(function (ExecutionContext $context, JobProcessing $event): void {
            $context->enter($event->job->resolveName());
            $this->app->make(RequestSpans::class)->clear();
        }));

        foreach ([JobProcessed::class, JobFailed::class, JobReleasedAfterException::class] as $finished) {
            $events->listen($finished, $context(fn (ExecutionContext $context) => $context->leave()));
        }

        $events->listen(CommandStarting::class, $context(function (ExecutionContext $context, CommandStarting $event): void {
            $context->enter($event->command === null ? null : 'artisan '.$event->command);
            $this->app->make(RequestSpans::class)->clear();
        }));

        $events->listen(CommandFinished::class, $context(fn (ExecutionContext $context) => $context->leave()));
    }

    /**
     * Record database queries slower than the configured threshold.
     */
    protected function registerQueryTelemetry(): void
    {
        $this->app->make(Dispatcher::class)->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            try {
                $this->app->make(QueryRecorder::class)->executed($query);
            } catch (Throwable) {
                // Telemetry must never reach the host app.
            }
        });
    }

    /**
     * Record calls made with Laravel's HTTP client.
     */
    protected function registerOutgoingRequestTelemetry(): void
    {
        $events = $this->app->make(Dispatcher::class);

        $events->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            try {
                $this->app->make(OutgoingRequestRecorder::class)->received($event->request, $event->response);
            } catch (Throwable) {
                // Telemetry must never reach the host app.
            }
        });

        $events->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            try {
                $this->app->make(OutgoingRequestRecorder::class)->failed($event->request);
            } catch (Throwable) {
                // Telemetry must never reach the host app.
            }
        });
    }
}
