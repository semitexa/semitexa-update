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

    /**
     * Write the captured composer.json/lock back. When the snapshot had no
     * lock file, a lock created by the failed update is removed — otherwise
     * the rollback composer install would keep the post-update dependency
     * set. Returns false when any step fails — the caller must surface that
     * as an unrecovered state.
     */
    public function restoreFiles(): bool
    {
        $ok = file_put_contents($this->projectRoot . '/composer.json', $this->composerJson) !== false;

        $lockPath = $this->projectRoot . '/composer.lock';
        if ($this->composerLock !== null) {
            $ok = file_put_contents($lockPath, $this->composerLock) !== false && $ok;
        } elseif (is_file($lockPath)) {
            $ok = @unlink($lockPath) && $ok;
        }

        return $ok;
    }
}
