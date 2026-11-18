<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class SelfFailingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $this->fail(new RuntimeException('giving up'));
    }
}
