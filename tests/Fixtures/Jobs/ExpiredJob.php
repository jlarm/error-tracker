<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpiredJob implements ShouldQueue
{
    use Queueable;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->subMinute();
    }

    public function handle(): void
    {
        //
    }
}
