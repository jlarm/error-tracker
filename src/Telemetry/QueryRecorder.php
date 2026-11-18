<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * Records database queries slower than `telemetry.slow_query_ms`.
 *
 * Literal values are stripped from the SQL before it is buffered, so only
 * the query's shape leaves the app, and queries that differ only in their
 * values group together on the dashboard.
 */
class QueryRecorder
{
    /**
     * Slow queries recorded per request, job or command.
     */
    private const PER_CONTEXT_CAP = 50;

    /**
     * The tracker stores SQL up to this many characters.
     */
    private const MAX_SQL_LENGTH = 4000;

    public function __construct(
        protected Config $config,
        protected TelemetryBuffer $buffer,
        protected TelemetrySwitch $switch,
        protected ExecutionContext $context,
        protected RequestSpans $spans,
    ) {}

    /**
     * Never throws.
     */
    public function executed(QueryExecuted $query): void
    {
        try {
            if (! $this->switch->isOn()) {
                return;
            }

            $endedAt = microtime(true);
            $this->spans->add('query', $query->sql, $endedAt - $query->time / 1000, (float) $query->time);

            $threshold = (float) $this->config->get('error-tracker.telemetry.slow_query_ms', 100);

            if ($threshold <= 0 || $query->time < $threshold) {
                return;
            }

            if (! $this->context->allow('query', self::PER_CONTEXT_CAP)) {
                return;
            }

            $this->buffer->push([
                'type' => 'query',
                'timestamp' => CarbonImmutable::createFromTimestampUTC($endedAt - $query->time / 1000)->format('Y-m-d\TH:i:s.uP'),
                'sql' => mb_substr(self::normalize($query->sql), 0, self::MAX_SQL_LENGTH),
                'connection' => $query->connectionName,
                'duration_ms' => round((float) $query->time, 2),
                'origin' => $this->context->origin(),
            ]);
        } catch (Throwable) {
            // Telemetry must never reach the host app.
        }
    }

    /**
     * The query's shape: quoted strings and numbers become `?`, lists of
     * placeholders collapse to one, and whitespace is squeezed.
     */
    public static function normalize(string $sql): string
    {
        $patterns = [
            "/'(?:[^'\\\\]|\\\\.|'')*'/s" => '?',              // 'strings'
            '/(?<![\w.$`"])-?\d+(?:\.\d+)?\b/' => '?',          // numbers not part of names
            '/\?(?:\s*,\s*\?)+/' => '?',                        // IN (?, ?, ?) → IN (?)
            '/\s+/' => ' ',
        ];

        return trim((string) preg_replace(array_keys($patterns), array_values($patterns), $sql));
    }
}
