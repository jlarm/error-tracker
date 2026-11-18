<?php

declare(strict_types=1);

namespace Armp\ErrorTracker;

use Armp\ErrorTracker\Console\SendTelemetryCommand;
use Armp\ErrorTracker\Console\TestCommand;
use Armp\ErrorTracker\Telemetry\FileTelemetryBuffer;
use Armp\ErrorTracker\Telemetry\RequestRecorder;
use Armp\ErrorTracker\Telemetry\TelemetryBuffer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ErrorTrackerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/error-tracker.php', 'error-tracker');

        $this->app->singleton(ErrorTracker::class, fn ($app) => new ErrorTracker(
            $app->make(Config::class),
        ));

        $this->app->alias(ErrorTracker::class, 'error-tracker');

        $this->app->singleton(FileTelemetryBuffer::class, fn ($app) => new FileTelemetryBuffer(
            (string) ($app->make(Config::class)->get('error-tracker.telemetry.buffer_path') ?: storage_path('framework/cache/error-tracker')),
            (int) $app->make(Config::class)->get('error-tracker.telemetry.buffer_max_bytes', 10 * 1024 * 1024),
        ));
        $this->app->singletonIf(TelemetryBuffer::class, FileTelemetryBuffer::class);
        $this->app->scoped(RequestRecorder::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) {
                    $this->app->make(ErrorTracker::class)->capture($e, request());
                });
            }
        });

        $this->registerRequestTelemetry();

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
}
