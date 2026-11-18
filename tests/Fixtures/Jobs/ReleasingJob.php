<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReleasingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $this->release(30);
    }
}
