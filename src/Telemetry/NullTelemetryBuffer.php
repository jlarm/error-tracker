<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

/**
 * Discards telemetry. The default until a buffer that ships batches to the
 * tracker is bound.
 */
class NullTelemetryBuffer implements TelemetryBuffer
{
    public function push(array $entry): void
    {
        //
    }
}
