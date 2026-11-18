<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

class RateLimitedJob implements ShouldQueue
{
    use Queueable;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('telemetry-test')];
    }

    public function handle(): void
    {
        //
    }
}
