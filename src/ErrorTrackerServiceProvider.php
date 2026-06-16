<?php

declare(strict_types=1);

namespace Armp\ErrorTracker;

use Armp\ErrorTracker\Console\TestCommand;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
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

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/error-tracker.php' => config_path('error-tracker.php'),
            ], 'error-tracker-config');

            $this->commands([
                TestCommand::class,
            ]);
        }
    }
}
