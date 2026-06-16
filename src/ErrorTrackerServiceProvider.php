<?php

declare(strict_types=1);

namespace Armp\ErrorTracker;

use Armp\ErrorTracker\Console\TestCommand;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;

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
