<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

use Semitexa\Update\Domain\Model\DeploymentPlan;

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

    /** Where a deploy keeps the state it replaced, for update:packages:rollback. */
    private const KEPT_DIR = 'var/update/rollback';

    /**
     * Keep this pre-update state on disk, with the hash of the composer.lock
     * the update installed: the systemd wrapper's health check, after its
     * restart, is the one a broken release fails, and by then this process
     * (and the in-memory snapshot) is gone. Nothing could roll it back.
     */
    public function keep(string $installedLockHash): bool
    {
        $dir = $this->projectRoot . '/' . self::KEPT_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        // The marker first: half a new state must never pass for the old one.
        @unlink($dir . '/kept.json');
        @unlink($dir . '/composer.lock');

        return self::writeAtomically($dir . '/composer.json', $this->composerJson)
            && ($this->composerLock === null || self::writeAtomically($dir . '/composer.lock', $this->composerLock))
            && self::writeAtomically($dir . '/kept.json', (string) json_encode([
                'installed_lock_sha1' => $installedLockHash,
                'had_lock' => $this->composerLock !== null,
                // What vendor/ holds, not what the plan listed: a lookup that
                // timed out leaves a package off the plan, not off the install.
                'installed' => (new InstalledSemitexaPackageReader())->read($this->projectRoot),
                'kept_at' => gmdate(DATE_ATOM),
            ]));
    }

    /**
     * The state the last deploy replaced, if it is kept and the project's
     * composer.lock is still the one that deploy installed: after anything
     * else changed it, putting the old files back would undo that too.
     */
    public static function kept(string $projectRoot): ?self
    {
        $dir = $projectRoot . '/' . self::KEPT_DIR;
        $meta = json_decode((string) @file_get_contents($dir . '/kept.json'), true);
        $json = @file_get_contents($dir . '/composer.json');
        if (!is_array($meta) || !is_string($meta['installed_lock_sha1'] ?? null) || $json === false) {
            return null;
        }
        $current = @file_get_contents($projectRoot . '/composer.lock');
        if ($current === false || !hash_equals($meta['installed_lock_sha1'], sha1($current))) {
            return null;
        }
        // Without a kept lock restoreFiles() deletes the project's, and
        // composer install resolves afresh, maybe onto the same releases: a
        // deploy that started with no lock, or a kept one that went missing.
        $lock = ($meta['had_lock'] ?? false) === true ? @file_get_contents($dir . '/composer.lock') : false;
        if ($lock === false) {
            return null;
        }

        return new self($projectRoot, $json, $lock);
    }

    /**
     * After a rollback: the kept files go, and what that deploy installed
     * is remembered as rejected, so the next tick does not deploy it again.
     */
    public static function rejectKept(string $projectRoot): bool
    {
        $dir = $projectRoot . '/' . self::KEPT_DIR;
        $recorded = @rename($dir . '/kept.json', $dir . '/rejected.json');
        if (!$recorded) {
            @unlink($dir . '/kept.json');
        }
        @unlink($dir . '/composer.json');
        @unlink($dir . '/composer.lock');

        return $recorded;
    }

    /**
     * Whether a plan offers nothing newer than the releases the last
     * rollback backed out: with vendor/ back on the older ones, those look
     * new again. Per package, by version, not the plan's list as a whole: a
     * Packagist lookup that timed out drops a package from the plan, and
     * composer still installs it.
     */
    public static function rejects(string $projectRoot, DeploymentPlan $plan): bool
    {
        $meta = json_decode((string) @file_get_contents($projectRoot . '/' . self::KEPT_DIR . '/rejected.json'), true);
        $rejected = is_array($meta) && is_array($meta['installed'] ?? null) ? array_filter($meta['installed'], 'is_string') : [];
        if ($rejected === []) {
            return false;
        }
        $offered = [];
        foreach ($plan->packageUpdates as $update) {
            $offered[$update->packageName] = $update->latestVersion;
        }
        // Private tags only: one version for the whole set.
        if ($offered === [] && $plan->selectedVersion !== null) {
            $offered = array_fill_keys(array_keys($rejected), $plan->selectedVersion);
        }
        foreach ($offered as $package => $version) {
            if (!isset($rejected[$package]) || SemitexaReleaseVersion::compare($version, $rejected[$package]) > 0) {
                return false;
            }
        }

        return $offered !== [];
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
