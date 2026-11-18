<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

use Armp\ErrorTracker\CurrentUser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records one telemetry entry per HTTP request.
 *
 * The request is only noted when it has been handled (a timestamp, nothing
 * more). The entry is built and buffered later, from the application's
 * terminating callback, after the response has been sent to the visitor.
 */
class RequestRecorder
{
    /**
     * The tracker stores routes up to this many characters.
     */
    private const MAX_ROUTE_LENGTH = 255;

    protected ?Request $request = null;

    protected ?Response $response = null;

    protected ?float $handledAt = null;

    public function __construct(
        protected Config $config,
        protected CurrentUser $currentUser,
        protected TelemetryBuffer $buffer,
        protected TelemetrySwitch $switch,
    ) {}

    /**
     * Note a handled request. Called before the response is sent, so it only
     * keeps references and the time.
     */
    public function handled(Request $request, Response $response): void
    {
        $this->request = $request;
        $this->response = $response;
        $this->handledAt = microtime(true);
    }

    /**
     * Build and buffer the entry for the noted request. Called after the
     * response is sent. Never throws.
     */
    public function flush(): void
    {
        try {
            if ($this->request === null || $this->response === null || $this->handledAt === null) {
                return;
            }

            if (! $this->switch->isOn() || $this->shouldIgnore($this->request)) {
                return;
            }

            $this->buffer->push($this->entryFor($this->request, $this->response, $this->handledAt));
        } catch (Throwable) {
            // Telemetry must never reach the host app.
        } finally {
            $this->request = null;
            $this->response = null;
            $this->handledAt = null;
        }
    }

    /**
     * @return array{type: string, timestamp: string, method: string, route: string|null, route_name: string|null, status: int, duration_ms: float, user_id: int|string|null, user_name: string|null, user_email: string|null}
     */
    protected function entryFor(Request $request, Response $response, float $handledAt): array
    {
        $startedAt = $this->startedAt($request, $handledAt);
        $route = $request->route();
        $user = $this->currentUser->resolve();

        return [
            'type' => 'request',
            'timestamp' => CarbonImmutable::createFromTimestampUTC($startedAt)->format('Y-m-d\TH:i:s.uP'),
            'method' => $request->getMethod(),
            'route' => $route instanceof Route ? $this->routeLabel($route, $request) : null,
            'route_name' => $route instanceof Route ? $route->getName() : null,
            'status' => $response->getStatusCode(),
            'duration_ms' => round(max(0.0, $handledAt - $startedAt) * 1000, 2),
            'user_id' => $user['id'] ?? null,
            'user_name' => $user['name'] ?? null,
            'user_email' => $user['email'] ?? null,
        ];
    }

    /**
     * The route's URI pattern. Inertia partial reloads (deferred props,
     * `router.reload({ only })`) hit the same URI as the full page but do
     * different work, so they are labelled with the props they asked for:
     * "/dashboard (partial: weeklyDigestSummary)".
     */
    protected function routeLabel(Route $route, Request $request): string
    {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! $request->headers->has('X-Inertia')) {
            return $uri;
        }

        $only = $this->propList($request->headers->get('X-Inertia-Partial-Data'));
        $except = $this->propList($request->headers->get('X-Inertia-Partial-Except'));

        $label = match (true) {
            $only !== [] => $uri.' (partial: '.implode(', ', $only).')',
            $except !== [] => $uri.' (partial: all except '.implode(', ', $except).')',
            default => $uri,
        };

        return mb_strlen($label) > self::MAX_ROUTE_LENGTH
            ? mb_substr($label, 0, self::MAX_ROUTE_LENGTH - 2).'…)'
            : $label;
    }

    /**
     * A comma-separated header as a sorted list, so the same props always
     * produce the same label.
     *
     * @return list<string>
     */
    protected function propList(?string $header): array
    {
        $props = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $header)))));

        sort($props, SORT_STRING);

        return $props;
    }

    /**
     * When the request started: the time PHP received it. Falls back to
     * Laravel's boot time, which is per request except under long-running
     * workers such as Octane.
     */
    protected function startedAt(Request $request, float $handledAt): float
    {
        $requestTime = $request->server('REQUEST_TIME_FLOAT');

        if (is_numeric($requestTime)) {
            return (float) $requestTime;
        }

        return defined('LARAVEL_START') ? (float) LARAVEL_START : $handledAt;
    }

    protected function shouldIgnore(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach ((array) $this->config->get('error-tracker.telemetry.ignore_paths', []) as $pattern) {
            if (Str::is(trim((string) $pattern, '/'), $path)) {
                return true;
            }
        }

        $routeName = $request->route() instanceof Route ? $request->route()->getName() : null;

        if ($routeName === null) {
            return false;
        }

        foreach ((array) $this->config->get('error-tracker.telemetry.ignore_routes', []) as $pattern) {
            if (Str::is((string) $pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }
}
