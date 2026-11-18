<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Tests\Fixtures;

use Armp\ErrorTracker\Telemetry\TelemetryBuffer;

/**
 * Keeps pushed entries in memory so tests can inspect them.
 */
class ArrayTelemetryBuffer implements TelemetryBuffer
{
    /** @var array<int, array<string, mixed>> */
    public array $entries = [];

    public function push(array $entry): void
    {
        $this->entries[] = $entry;
    }
}
