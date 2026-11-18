<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Console;

use Armp\ErrorTracker\Telemetry\FileTelemetryBuffer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ships buffered telemetry to the tracker in batches. Scheduled every minute
 * by the service provider when telemetry is enabled.
 */
class SendTelemetryCommand extends Command
{
    /**
     * The most entries in one request; matches the tracker's batch limit.
     */
    public const BATCH_SIZE = 1000;

    protected $signature = 'error-tracker:send-telemetry';

    protected $description = 'Send buffered request telemetry to the error tracker.';

    public function handle(Config $config, FileTelemetryBuffer $buffer): int
    {
        $url = $this->telemetryUrl($config);
        $key = (string) $config->get('error-tracker.key');

        if (! $config->get('error-tracker.enabled', true)
            || ! $config->get('error-tracker.telemetry.enabled', false)
            || $url === null
            || $key === '') {
            return self::SUCCESS;
        }

        $buffer->rotate();

        $dropped = $buffer->enforceCap();

        if ($dropped > 0) {
            $this->components->warn("Dropped {$dropped} pending telemetry file(s) over the buffer cap.");
        }

        $batchesLeft = max(1, (int) $config->get('error-tracker.telemetry.max_batches_per_run', 10));

        foreach ($buffer->pendingFiles() as $file) {
            $entries = $buffer->read($file);

            while ($entries !== [] && $batchesLeft > 0) {
                $batch = array_slice($entries, 0, self::BATCH_SIZE);
                $outcome = $this->send($url, $key, $batch, (int) $config->get('error-tracker.timeout', 2));

                if ($outcome === 'retry') {
                    $buffer->rewrite($file, $entries);
                    $this->components->warn('The tracker did not accept telemetry; it will be retried next run.');

                    return self::SUCCESS;
                }

                // Sent, or refused for good (malformed batch): either way it is done.
                $entries = array_slice($entries, self::BATCH_SIZE);
                $batchesLeft--;
            }

            $buffer->rewrite($file, $entries);

            if ($batchesLeft === 0) {
                break;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Post one batch. Returns "sent", "dropped" when the tracker refuses the
     * batch as malformed (retrying would never succeed), or "retry".
     *
     * @param  array<int, array<string, mixed>>  $batch
     */
    protected function send(string $url, string $key, array $batch, int $timeout): string
    {
        try {
            $response = Http::withToken($key)
                ->asJson()
                ->acceptJson()
                ->timeout($timeout)
                ->post($url, ['entries' => $batch]);
        } catch (Throwable) {
            return 'retry';
        }

        if ($response->successful()) {
            return 'sent';
        }

        return in_array($response->status(), [400, 413, 422], true) ? 'dropped' : 'retry';
    }

    /**
     * The telemetry endpoint: configured explicitly, or derived from the
     * error ingest URL (…/api/errors → …/api/telemetry).
     */
    protected function telemetryUrl(Config $config): ?string
    {
        $configured = $config->get('error-tracker.telemetry.url');

        if (filled($configured)) {
            return (string) $configured;
        }

        $errorsUrl = (string) $config->get('error-tracker.url');

        if (! preg_match('#/api/errors/?$#', $errorsUrl)) {
            return null;
        }

        return (string) preg_replace('#/api/errors/?$#', '/api/telemetry', $errorsUrl);
    }
}
