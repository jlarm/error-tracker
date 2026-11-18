<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

/**
 * The queries and outgoing HTTP calls made while handling the current
 * request, kept so a slow request can carry its timeline.
 *
 * Spans are cheap to collect (a start time, a duration and the raw label);
 * labels are only cleaned up if the request turns out slow. The list is
 * capped, and cleared after each request and whenever a job or command
 * starts, so long-running workers never grow it.
 */
class RequestSpans
{
    /**
     * Spans kept per request.
     */
    public const LIMIT = 200;

    /** @var list<array{type: string, label: string, started_at: float, duration_ms: float}> */
    protected array $spans = [];

    protected int $dropped = 0;

    public function add(string $type, string $label, float $startedAt, float $durationMs): void
    {
        if (count($this->spans) >= self::LIMIT) {
            $this->dropped++;

            return;
        }

        $this->spans[] = [
            'type' => $type,
            'label' => $label,
            'started_at' => $startedAt,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * The spans collected since the last clear, as offsets from the
     * request's start, in the order they started.
     *
     * @return list<array{type: string, label: string, offset_ms: float, duration_ms: float}>
     */
    public function relativeTo(float $requestStartedAt): array
    {
        $spans = array_map(fn (array $span): array => [
            'type' => $span['type'],
            'label' => $span['type'] === 'query'
                ? mb_substr(QueryRecorder::normalize($span['label']), 0, 4000)
                : mb_substr($span['label'], 0, 4000),
            'offset_ms' => round(max(0.0, $span['started_at'] - $requestStartedAt) * 1000, 2),
            'duration_ms' => round($span['duration_ms'], 2),
        ], $this->spans);

        usort($spans, fn (array $a, array $b): int => $a['offset_ms'] <=> $b['offset_ms']);

        return $spans;
    }

    /**
     * Spans not kept because the request passed the limit.
     */
    public function dropped(): int
    {
        return $this->dropped;
    }

    public function clear(): void
    {
        $this->spans = [];
        $this->dropped = 0;
    }
}
