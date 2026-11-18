<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

/**
 * What the app is doing right now, used to label slow queries and outgoing
 * requests: the matched route ("GET /orders/{order}"), the queued job class,
 * or the artisan command.
 *
 * Each change of context also starts a fresh count, so one runaway request
 * or job cannot flood the buffer.
 */
class ExecutionContext
{
    protected ?string $origin = null;

    /** @var array<string, int> */
    protected array $counts = [];

    public function enter(?string $origin): void
    {
        $this->origin = $origin === null ? null : mb_substr($origin, 0, 255);
        $this->counts = [];
    }

    public function leave(): void
    {
        $this->enter(null);
    }

    public function origin(): ?string
    {
        return $this->origin;
    }

    /**
     * Count one more entry of a kind in this context, and whether it is
     * still under the cap.
     */
    public function allow(string $kind, int $cap): bool
    {
        $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;

        return $this->counts[$kind] <= $cap;
    }
}
