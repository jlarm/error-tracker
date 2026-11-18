<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
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
    protected ?Request $request = null;

    protected ?Response $response = null;

    protected ?float $handledAt = null;

    public function __construct(
        protected Config $config,
        protected AuthFactory $auth,
        protected TelemetryBuffer $buffer,
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

            if (! $this->isEnabled() || $this->shouldIgnore($this->request)) {
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
     * @return array{type: string, timestamp: string, method: string, route: string|null, route_name: string|null, status: int, duration_ms: float, user_id: int|string|null}
     */
    protected function entryFor(Request $request, Response $response, float $handledAt): array
    {
        $startedAt = $this->startedAt($request, $handledAt);
        $route = $request->route();

        return [
            'type' => 'request',
            'timestamp' => CarbonImmutable::createFromTimestampUTC($startedAt)->format('Y-m-d\TH:i:s.uP'),
            'method' => $request->getMethod(),
            'route' => $route instanceof Route ? '/'.ltrim($route->uri(), '/') : null,
            'route_name' => $route instanceof Route ? $route->getName() : null,
            'status' => $response->getStatusCode(),
            'duration_ms' => round(max(0.0, $handledAt - $startedAt) * 1000, 2),
            'user_id' => $this->userId(),
        ];
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

    /**
     * The logged-in user's ID, only if the guard already resolved the user
     * during the request. Never triggers a session or database lookup.
     */
    protected function userId(): int|string|null
    {
        $guard = $this->auth->guard();

        if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
            return null;
        }

        $id = $guard->id();

        return is_int($id) || is_string($id) ? $id : null;
    }

    protected function isEnabled(): bool
    {
        if (! $this->config->get('error-tracker.enabled', true)
            || ! $this->config->get('error-tracker.telemetry.enabled', false)
            || blank($this->config->get('error-tracker.key'))) {
            return false;
        }

        $environments = array_filter((array) $this->config->get('error-tracker.environments', []));

        return $environments === [] || in_array(app()->environment(), $environments, true);
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
