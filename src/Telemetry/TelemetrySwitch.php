<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Whether telemetry should be recorded in this app and environment: the
 * reporter must be enabled and configured, telemetry turned on, and the
 * current environment allowed.
 */
class TelemetrySwitch
{
    public function __construct(protected Config $config) {}

    public function isOn(): bool
    {
        if (! $this->config->get('error-tracker.enabled', true)
            || ! $this->config->get('error-tracker.telemetry.enabled', false)
            || blank($this->config->get('error-tracker.key'))) {
            return false;
        }

        $environments = array_filter((array) $this->config->get('error-tracker.environments', []));

        return $environments === [] || in_array(app()->environment(), $environments, true);
    }
}
