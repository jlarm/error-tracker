<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records the calls the app makes with Laravel's HTTP client: method, host,
 * status (none when the connection failed) and duration, labelled with the
 * route, job or command that made them.
 *
 * Only the scheme, host and port leave the app; never the path, query
 * string, headers or bodies. Calls to the error tracker itself are skipped.
 */
class OutgoingRequestRecorder
{
    /**
     * Outgoing requests recorded per request, job or command.
     */
    private const PER_CONTEXT_CAP = 100;

    public function __construct(
        protected Config $config,
        protected TelemetryBuffer $buffer,
        protected TelemetrySwitch $switch,
        protected ExecutionContext $context,
    ) {}

    /**
     * Never throws.
     */
    public function received(Request $request, Response $response): void
    {
        $seconds = $response->transferStats?->getTransferTime();

        $this->record($request, $response->status(), is_numeric($seconds) ? (float) $seconds * 1000 : null);
    }

    /**
     * The connection failed: no response at all. Never throws.
     */
    public function failed(Request $request): void
    {
        $this->record($request, null, null);
    }

    protected function record(Request $request, ?int $status, ?float $durationMs): void
    {
        try {
            $host = self::hostOf($request->url());

            if ($host === null || ! $this->switch->isOn() || $this->shouldIgnore($host)) {
                return;
            }

            if (! $this->context->allow('outgoing_request', self::PER_CONTEXT_CAP)) {
                return;
            }

            $endedAt = microtime(true);

            $this->buffer->push([
                'type' => 'outgoing_request',
                'timestamp' => CarbonImmutable::createFromTimestampUTC($endedAt - ($durationMs ?? 0) / 1000)->format('Y-m-d\TH:i:s.uP'),
                'method' => strtoupper($request->method()),
                'host' => $host,
                'status' => $status,
                'duration_ms' => $durationMs === null ? null : round($durationMs, 2),
                'origin' => $this->context->origin(),
            ]);
        } catch (Throwable) {
            // Telemetry must never reach the host app.
        }
    }

    /**
     * "https://api.stripe.com", with the port only when it isn't the
     * scheme's default.
     */
    public static function hostOf(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? null;
        $defaultPort = ['http' => 80, 'https' => 443][$scheme] ?? null;

        return $scheme.'://'.strtolower($parts['host']).($port !== null && $port !== $defaultPort ? ':'.$port : '');
    }

    protected function shouldIgnore(string $host): bool
    {
        $trackerHosts = array_filter([
            self::hostOf((string) $this->config->get('error-tracker.url')),
            self::hostOf((string) $this->config->get('error-tracker.telemetry.url')),
        ]);

        if (in_array($host, $trackerHosts, true)) {
            return true;
        }

        $bareHost = (string) parse_url($host, PHP_URL_HOST);

        foreach ((array) $this->config->get('error-tracker.telemetry.ignore_hosts', []) as $pattern) {
            if (Str::is((string) $pattern, $bareHost) || Str::is((string) $pattern, $host)) {
                return true;
            }
        }

        return false;
    }
}
