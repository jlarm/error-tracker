<?php

declare(strict_types=1);

namespace Armp\ErrorTracker;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Throwable;

class PayloadBuilder
{
    /** @var array<string, mixed> */
    protected array $payload = [];

    public function __construct(protected Config $config) {}

    public function fromException(Throwable $e, ?Request $request, string $level): self
    {
        $this->payload = [
            'exception_class' => $e::class,
            'message' => $e->getMessage() !== '' ? $e->getMessage() : $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'url' => $request?->fullUrl(),
            'environment' => app()->environment(),
            'level' => $level,
            'release' => $this->config->get('error-tracker.release'),
            'stack_trace' => $this->formatStackTrace($e),
            'request_payload' => $this->formatRequest($request),
            'tags' => $this->baseTags(),
            'context' => $this->baseContext(),
        ];

        return $this;
    }

    public function fromMessage(string $message, string $level, ?Request $request): self
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $caller = $trace[2] ?? $trace[1] ?? $trace[0];

        $this->payload = [
            'exception_class' => 'Armp\\ErrorTracker\\Message',
            'message' => $message,
            'file' => $caller['file'] ?? 'unknown',
            'line' => $caller['line'] ?? 0,
            'url' => $request?->fullUrl(),
            'environment' => app()->environment(),
            'level' => $level,
            'release' => $this->config->get('error-tracker.release'),
            'stack_trace' => [],
            'request_payload' => $this->formatRequest($request),
            'tags' => $this->baseTags(),
            'context' => $this->baseContext(),
        ];

        return $this;
    }

    /**
     * @param  array<string, scalar|null>  $tags
     */
    public function withTags(array $tags): self
    {
        $this->payload['tags'] = array_merge($this->payload['tags'] ?? [], $tags);

        return $this;
    }

    /**
     * @param  array<string, array<string, mixed>>  $context
     */
    public function withContext(array $context): self
    {
        $this->payload['context'] = array_merge_recursive($this->payload['context'] ?? [], $context);

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $user
     */
    public function withUser(?array $user): self
    {
        if ($user) {
            $this->payload['context']['user'] = array_merge(
                $this->payload['context']['user'] ?? [],
                $user,
            );
        }

        return $this;
    }

    /**
     * @param  array<int, array<string, mixed>>  $breadcrumbs
     */
    public function withBreadcrumbs(array $breadcrumbs): self
    {
        if (! empty($breadcrumbs)) {
            $this->payload['breadcrumbs'] = $breadcrumbs;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return $this->payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function formatStackTrace(Throwable $e): array
    {
        $max = (int) $this->config->get('error-tracker.stack_trace_frames', 25);
        $frames = [
            ['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => '<throw>'],
        ];

        foreach ($e->getTrace() as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
                'function' => $frame['function'] ?? null,
            ];

            if (count($frames) >= $max) {
                break;
            }
        }

        return $frames;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function formatRequest(?Request $request): ?array
    {
        if (! $request) {
            return null;
        }

        $scrub = array_map('strtolower', (array) $this->config->get('error-tracker.scrubbed_inputs', []));
        $input = $request->except($scrub);

        $captured = array_map('strtolower', (array) $this->config->get('error-tracker.captured_headers', []));
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (in_array(strtolower($name), $captured, true)) {
                $headers[$name] = is_array($values) ? implode(', ', $values) : $values;
            }
        }

        return [
            'method' => $request->method(),
            'input' => $input,
            'headers' => $headers,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function baseTags(): array
    {
        return array_filter([
            'environment' => app()->environment(),
            'release' => $this->config->get('error-tracker.release'),
            'server_name' => gethostname() ?: null,
            'runtime' => 'php',
            'php_version' => PHP_VERSION,
        ], fn ($v) => $v !== null && $v !== false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function baseContext(): array
    {
        return [
            'runtime' => [
                'name' => 'php',
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],
            'server' => array_filter([
                'name' => gethostname() ?: null,
                'environment' => app()->environment(),
            ], fn ($v) => $v !== null),
        ];
    }
}
