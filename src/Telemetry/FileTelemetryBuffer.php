<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Telemetry;

use Throwable;

/**
 * Buffers telemetry as newline-delimited JSON on local disk.
 *
 * Requests append to an active file. The sender periodically moves the
 * active file aside as a pending file, ships it, and deletes it. Pending
 * files survive a tracker outage and are retried; past the size cap, new
 * entries are dropped and the oldest pending files are discarded, so an
 * outage can never fill the disk.
 */
class FileTelemetryBuffer implements TelemetryBuffer
{
    private const ACTIVE_FILE = 'telemetry.ndjson';

    private const PENDING_PREFIX = 'pending-';

    public function __construct(
        protected string $directory,
        protected int $maxBytes,
    ) {}

    /**
     * Append an entry to the active file. Never throws.
     */
    public function push(array $entry): void
    {
        try {
            $path = $this->activePath();

            if (! is_dir($this->directory) && ! @mkdir($this->directory, 0755, true) && ! is_dir($this->directory)) {
                return;
            }

            clearstatcache(true, $path);

            if (is_file($path) && filesize($path) >= $this->maxBytes) {
                return;
            }

            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

            file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Telemetry must never reach the host app.
        }
    }

    /**
     * Move the active file aside so it can be sent while requests keep
     * appending to a fresh one. Returns the pending file, or null if there
     * was nothing to move.
     */
    public function rotate(): ?string
    {
        $active = $this->activePath();

        if (! is_file($active) || filesize($active) === 0) {
            return null;
        }

        $pending = $this->directory.DIRECTORY_SEPARATOR.self::PENDING_PREFIX
            .sprintf('%.6F', microtime(true)).'-'.bin2hex(random_bytes(4)).'.ndjson';

        if (! @rename($active, $pending)) {
            return null;
        }

        // Wait for any request that was mid-append when the file was moved.
        $handle = @fopen($pending, 'r');

        if ($handle !== false) {
            flock($handle, LOCK_EX);
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $pending;
    }

    /**
     * Pending files waiting to be sent, oldest first.
     *
     * @return array<int, string>
     */
    public function pendingFiles(): array
    {
        $files = glob($this->directory.DIRECTORY_SEPARATOR.self::PENDING_PREFIX.'*.ndjson') ?: [];

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Delete the oldest pending files until the pending total fits within
     * the size cap. Returns the number of files dropped.
     */
    public function enforceCap(): int
    {
        $files = $this->pendingFiles();
        $sizes = [];

        foreach ($files as $file) {
            clearstatcache(true, $file);
            $sizes[$file] = (int) @filesize($file);
        }

        $dropped = 0;

        while (array_sum($sizes) > $this->maxBytes && $sizes !== []) {
            $oldest = array_key_first($sizes);
            @unlink($oldest);
            unset($sizes[$oldest]);
            $dropped++;
        }

        return $dropped;
    }

    /**
     * Read the entries in a pending file, skipping any line that isn't valid
     * JSON (for example, one cut short by a full disk).
     *
     * @return array<int, array<string, mixed>>
     */
    public function read(string $file): array
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $entries = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Replace a pending file's contents with the given entries, or delete it
     * when none are left.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function rewrite(string $file, array $entries): void
    {
        if ($entries === []) {
            @unlink($file);

            return;
        }

        $contents = implode("\n", array_map(
            fn (array $entry): string => json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $entries,
        ))."\n";

        file_put_contents($file, $contents, LOCK_EX);
    }

    public function activePath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.self::ACTIVE_FILE;
    }
}
