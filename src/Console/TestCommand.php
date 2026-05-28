<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Console;

use Armp\ErrorTracker\ErrorTracker;
use Illuminate\Console\Command;
use RuntimeException;

class TestCommand extends Command
{
    protected $signature = 'error-tracker:test {--message=Hello from error-tracker:test : Message used for the test event}';

    protected $description = 'Send a synthetic event to the configured error tracker.';

    public function handle(ErrorTracker $tracker): int
    {
        $url = (string) config('error-tracker.url');
        $key = (string) config('error-tracker.key');

        if ($url === '' || $key === '') {
            $this->error('error-tracker is not configured. Set ERROR_TRACKER_URL and ERROR_TRACKER_KEY in your .env.');

            return self::FAILURE;
        }

        $this->components->info('Posting test event to '.$url);

        $tracker
            ->tag('source', 'error-tracker:test')
            ->breadcrumb('cli', 'error-tracker:test invoked')
            ->capture(new RuntimeException((string) $this->option('message')));

        $this->components->success('Test event dispatched. Check your dashboard for the new issue.');

        return self::SUCCESS;
    }
}
