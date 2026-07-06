<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Update\Exception\UpdateException;

/**
 * Cross-process mutual exclusion for update runs, shared by the manual
 * `update` sweep and the auto-deploy pipeline. Backed by flock() on
 * var/lock/semitexa-update.lock: the OS releases the lock automatically when
 * the holding process dies, so a crashed run never leaves a stale lock —
 * the file content is diagnostics only (who holds it since when).
 */
final class UpdateLock
{
    private const RELATIVE_PATH = 'var/lock/semitexa-update.lock';

    /** @var resource|null */
    private $handle = null;

    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * Try to take the lock without blocking. False means another update run
     * holds it right now.
     */
    public function acquire(string $owner): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        $dir = dirname($this->path());
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UpdateException(sprintf('Cannot create lock directory: %s', $dir));
        }

        $handle = @fopen($this->path(), 'c+');
        if ($handle === false) {
            throw new UpdateException(sprintf('Cannot open update lock file: %s', $this->path()));
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, json_encode([
            'owner'       => $owner,
            'pid'         => getmypid(),
            'acquired_at' => gmdate(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES) ?: '');
        fflush($handle);

        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * Human description of the current holder, for the "already running"
     * error message. Best-effort: reads the diagnostics the holder wrote.
     */
    public function holderDescription(): string
    {
        $content = @file_get_contents($this->path());
        if (!is_string($content) || trim($content) === '') {
            return 'holder unknown';
        }

        $info = json_decode($content, true);
        if (!is_array($info)) {
            return 'holder unknown';
        }

        return sprintf(
            'held by %s, pid %s, since %s',
            (string) ($info['owner'] ?? 'unknown'),
            (string) ($info['pid'] ?? '?'),
            (string) ($info['acquired_at'] ?? '?'),
        );
    }

    public function path(): string
    {
        return $this->projectRoot . '/' . self::RELATIVE_PATH;
    }
}
