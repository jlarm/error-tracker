<?php

declare(strict_types=1);

namespace Armp\ErrorTracker;

/**
 * Works out the deployed release from the app's path when none is
 * configured: zero-downtime deploys put each release in its own folder,
 * either `releases/<id>/` (Envoyer, Forge, Deployer) or a timestamped
 * folder (`03112026_195654/`, Ploi).
 */
class ReleaseDetector
{
    public static function fromPath(string $path): ?string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/').'/';

        if (! preg_match_all('#(?:^|(?<=/))(?:releases/([^/]+)|(\d{8}_\d{6}))(?=/)#', $path, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $last = end($matches);

        return ($last[2] ?? '') !== '' ? $last[2] : $last[1];
    }
}
