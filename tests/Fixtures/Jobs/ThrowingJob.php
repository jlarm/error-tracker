<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class ThrowingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}
