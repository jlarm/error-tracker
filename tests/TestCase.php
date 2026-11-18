<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Tests;

use Armp\ErrorTracker\ErrorTrackerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ErrorTrackerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('error-tracker.key', 'test-project-key');
        $app['config']->set('error-tracker.url', 'https://tracker.test/api/errors');
    }
}
