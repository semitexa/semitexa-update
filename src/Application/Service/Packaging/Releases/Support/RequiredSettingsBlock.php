<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

use Semitexa\Update\Application\Service\RequiredSettings\DoctorProbe;
use Semitexa\Update\Domain\Model\DeploymentPlan;

/**
 * Remembers that a release set was rolled back because .env lacks a setting
 * its code requires, so the next tick does not install it, restart and roll
 * back again: unrecorded, that is two restarts a tick until somebody reads
 * the log.
 *
 * Not a rejection: the release is sound. The marker holds only while both the
 * offered release set and .env are what they were. A newer release (its check
 * may differ) or any change of .env drops it, and the deploy is tried again.
 *
 * Limit: only the .env file is hashed. A setting supplied through the process
 * environment alone (a systemd Environment=, a Compose `environment:`) does
 * not change the hash, so the marker keeps holding: delete
 * var/update/rollback/blocked.json, or wait for the next release.
 */
final class RequiredSettingsBlock
{
    private const FILE = 'var/update/rollback/blocked.json';

    /**
     * @param list<string> $settings the settings the doctor found missing
     */
    public static function record(string $projectRoot, DeploymentPlan $plan, array $settings): bool
    {
        $path = $projectRoot . '/' . self::FILE;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $contents = (string) json_encode([
            'offered' => self::offered($plan),
            'settings' => $settings,
            'env_sha1' => self::envHash($projectRoot),
            'blocked_at' => gmdate(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        // Beside the file, then renamed over it: a reader never sees half a marker.
        $temp = $path . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $contents) !== strlen($contents) || !@rename($temp, $path)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    /**
     * The settings still missing, when this very release set was blocked and
     * .env has not changed since; null otherwise. A marker that no longer
     * holds is removed here.
     *
     * @return non-empty-list<string>|null
     */
    public static function holds(string $projectRoot, DeploymentPlan $plan): ?array
    {
        $path = $projectRoot . '/' . self::FILE;
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        // There, and not readable as a marker: neither "no marker" (the tick
        // would install, roll back and restart again) nor a reason to delete
        // what somebody may have to look at. The tick fails and says so.
        $raw = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
        $marker = $raw === false ? null : json_decode($raw, true);
        if (!is_array($marker)) {
            throw new \RuntimeException(sprintf(
                '%s exists but cannot be read%s: fix its permissions or delete it, and the next tick tries the deploy again.',
                $path,
                $raw === false ? '' : ' as a marker (not JSON)',
            ));
        }
        $settings = [];
        foreach (is_array($marker['settings'] ?? null) ? $marker['settings'] : [] as $setting) {
            if (is_string($setting) && $setting !== '') {
                $settings[] = $setting;
            }
        }
        if ($settings !== []
            && ($marker['offered'] ?? null) === self::offered($plan)
            && ($marker['env_sha1'] ?? null) === self::envHash($projectRoot)
        ) {
            return $settings;
        }
        self::clear($projectRoot);

        return null;
    }

    public static function clear(string $projectRoot): void
    {
        @unlink($projectRoot . '/' . self::FILE);
    }

    /**
     * What the plan offers, compared whole: any difference is a reason to try again.
     *
     * @return array<string, string>
     */
    private static function offered(DeploymentPlan $plan): array
    {
        $offered = [];
        foreach ($plan->packageUpdates as $update) {
            $offered[$update->packageName] = $update->latestVersion;
        }
        ksort($offered);
        if ($plan->selectedVersion !== null) {
            $offered['@selected'] = $plan->selectedVersion;
        }

        return $offered;
    }

    /**
     * 'absent' is a missing .env only. One that is there and unreadable has no
     * hash at all: taken for absent, it would match a marker recorded without
     * a .env, or record one that holds whatever the file says.
     *
     * @throws \RuntimeException naming the file
     */
    private static function envHash(string $projectRoot): string
    {
        $env = DoctorProbe::readEnvFile($projectRoot . '/.env');

        return $env === null ? 'absent' : sha1($env);
    }
}
