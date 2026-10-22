<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

/**
 * In-memory snapshot of composer.json + composer.lock taken before
 * `composer update`, so a failure in the downstream deploy steps
 * (orm:sync / cache:clear / restart / health check) can restore the exact
 * pre-update dependency state. Restoring vendor/ itself is then a plain
 * `composer install` against the restored lock.
 */
final class ComposerStateSnapshot
{
    private function __construct(
        private readonly string $projectRoot,
        private readonly string $composerJson,
        private readonly ?string $composerLock,
    ) {
    }

    public static function capture(string $projectRoot): ?self
    {
        $jsonPath = $projectRoot . '/composer.json';
        $json = @file_get_contents($jsonPath);
        if ($json === false) {
            return null;
        }

        $lockPath = $projectRoot . '/composer.lock';
        $lock = is_file($lockPath) ? @file_get_contents($lockPath) : null;

        return new self($projectRoot, $json, $lock === false ? null : $lock);
    }

    /** Whether composer.lock existed when the snapshot was taken. */
    public function hasLock(): bool
    {
        return $this->composerLock !== null;
    }

    /**
     * Write the captured composer.json/lock back. When the snapshot had no
     * lock file, a lock created by the failed update is removed — otherwise
     * the rollback composer install would keep the post-update dependency
     * set. Returns false when any step fails — the caller must surface that
     * as an unrecovered state.
     */
    public function restoreFiles(): bool
    {
        $ok = self::writeAtomically($this->projectRoot . '/composer.json', $this->composerJson);

        $lockPath = $this->projectRoot . '/composer.lock';
        if ($this->composerLock !== null) {
            $ok = self::writeAtomically($lockPath, $this->composerLock) && $ok;
        } elseif (is_file($lockPath)) {
            $ok = @unlink($lockPath) && $ok;
        }

        return $ok;
    }

    /**
     * Written beside the file, then renamed over it: a full disk truncated
     * composer.lock in place, and the rollback left a project with no lock.
     */
    private static function writeAtomically(string $path, string $contents): bool
    {
        $temp = $path . '.rollback-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $contents) !== strlen($contents)) {
            @unlink($temp);

            return false;
        }
        $mode = @fileperms($path);
        if ($mode !== false) {
            @chmod($temp, $mode & 0777);
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);

            return false;
        }

        return true;
    }
}
