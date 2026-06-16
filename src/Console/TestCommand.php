<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Console;

use Armp\ErrorTracker\PayloadBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'error-tracker:test {--message=Hello from error-tracker:test : Message used for the test event}';

    protected $description = 'Send a synthetic event to the configured error tracker.';

    public function handle(Config $config): int
    {
        $url = (string) $config->get('error-tracker.url');
        $key = (string) $config->get('error-tracker.key');

        if ($url === '' || $key === '') {
            $this->error('error-tracker is not configured. Set ERROR_TRACKER_KEY in your .env.');

            return self::FAILURE;
        }

        $this->components->info('Posting test event to '.$url);

        $e = new RuntimeException((string) $this->option('message'));

        $payload = (new PayloadBuilder($config))
            ->fromException($e, null, 'error')
            ->withTags(['source' => 'error-tracker:test'])
            ->withBreadcrumbs([[
                'category' => 'cli',
                'level' => 'info',
                'message' => 'error-tracker:test invoked',
                'timestamp' => now()->toIso8601String(),
                'data' => [],
            ]])
            ->build();

        try {
            $response = Http::withToken($key)
                ->asJson()
                ->acceptJson()
                ->timeout((int) $config->get('error-tracker.timeout', 2))
                ->post($url, $payload);

            if ($response->successful()) {
                $this->components->success('Test event dispatched ('.$response->status().'). Check your dashboard for the new issue.');

                return self::SUCCESS;
            }

            $this->components->error('Server responded '.$response->status().': '.$response->body());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Could not reach '.$url.': '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
