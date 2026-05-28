<?php

declare(strict_types=1);

namespace Armp\ErrorTracker;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class ErrorTracker
{
    /** @var array<string, scalar|null> */
    protected array $tags = [];

    /** @var array<string, array<string, mixed>> */
    protected array $context = [];

    /** @var array<int, array<string, mixed>> */
    protected array $breadcrumbs = [];

    /** @var array<string, mixed>|null */
    protected ?array $user = null;

    public function __construct(protected Config $config) {}

    /**
     * Send a Throwable to the tracker. Safe to call from anywhere — the
     * reporter never throws and never blocks the host request for long.
     */
    public function capture(Throwable $e, ?Request $request = null, ?string $level = null): void
    {
        if (! $this->shouldReport($e)) {
            return;
        }

        $request ??= $this->resolveRequest();

        $payload = (new PayloadBuilder($this->config))
            ->fromException($e, $request, $level ?? 'error')
            ->withTags($this->tags)
            ->withContext($this->context)
            ->withUser($this->user)
            ->withBreadcrumbs($this->breadcrumbs)
            ->build();

        $this->send($payload);
    }

    /**
     * Send a free-form message (no exception). Useful for "should never happen"
     * code paths or noteworthy events worth surfacing.
     */
    public function message(string $message, string $level = 'info', array $context = []): void
    {
        if (! $this->isConfigured() || ! $this->environmentAllowed()) {
            return;
        }

        $request = $this->resolveRequest();

        $payload = (new PayloadBuilder($this->config))
            ->fromMessage($message, $level, $request)
            ->withTags($this->tags)
            ->withContext(array_merge_recursive($this->context, $context))
            ->withUser($this->user)
            ->withBreadcrumbs($this->breadcrumbs)
            ->build();

        $this->send($payload);
    }

    /**
     * Attach a single tag to all subsequent events from this process.
     */
    public function tag(string $key, string|int|float|bool|null $value): self
    {
        $this->tags[$key] = $value;

        return $this;
    }

    /**
     * Attach a context block (e.g. user, runtime, feature flags).
     */
    public function context(string $name, array $values): self
    {
        $this->context[$name] = $values;

        return $this;
    }

    /**
     * Identify the current user. Stored as the "user" context block.
     */
    public function user(array $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Record a breadcrumb. The buffer keeps the most recent N events as
     * configured via `breadcrumb_limit`.
     */
    public function breadcrumb(string $category, string $message, array $data = [], string $level = 'info'): self
    {
        $this->breadcrumbs[] = [
            'category' => $category,
            'level' => $level,
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ];

        $limit = (int) $this->config->get('error-tracker.breadcrumb_limit', 25);
        if (count($this->breadcrumbs) > $limit) {
            $this->breadcrumbs = array_slice($this->breadcrumbs, -$limit);
        }

        return $this;
    }

    /**
     * Clear all per-process state. Useful between queue jobs.
     */
    public function reset(): self
    {
        $this->tags = [];
        $this->context = [];
        $this->breadcrumbs = [];
        $this->user = null;

        return $this;
    }

    protected function shouldReport(Throwable $e): bool
    {
        if (! $this->isConfigured() || ! $this->environmentAllowed()) {
            return false;
        }

        foreach ((array) $this->config->get('error-tracker.dont_report', []) as $class) {
            if ($e instanceof $class) {
                return false;
            }
        }

        return true;
    }

    protected function isConfigured(): bool
    {
        return $this->config->get('error-tracker.enabled', true)
            && filled($this->config->get('error-tracker.url'))
            && filled($this->config->get('error-tracker.key'));
    }

    protected function environmentAllowed(): bool
    {
        $allowed = array_filter((array) $this->config->get('error-tracker.environments', []));

        if (empty($allowed)) {
            return true;
        }

        return in_array(app()->environment(), $allowed, true);
    }

    protected function resolveRequest(): ?Request
    {
        try {
            $request = app('request');

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(array $payload): void
    {
        try {
            Http::withToken((string) $this->config->get('error-tracker.key'))
                ->asJson()
                ->acceptJson()
                ->timeout((int) $this->config->get('error-tracker.timeout', 2))
                ->post((string) $this->config->get('error-tracker.url'), $payload);
        } catch (Throwable) {
            // The reporter must never bring down the host request.
        }
    }
}
