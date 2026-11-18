<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

/**
 * Where recorded telemetry goes before it is sent to the tracker.
 *
 * Implementations must be cheap and must never throw into the host app.
 */
interface TelemetryBuffer
{
    /**
     * @param  array<string, mixed>  $entry
     */
    public function push(array $entry): void;
}
