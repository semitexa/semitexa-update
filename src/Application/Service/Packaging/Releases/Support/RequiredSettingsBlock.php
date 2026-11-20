<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

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
        if (!is_file($path)) {
            return null;
        }
        $marker = json_decode((string) @file_get_contents($path), true);
        $settings = [];
        foreach (is_array($marker) && is_array($marker['settings'] ?? null) ? $marker['settings'] : [] as $setting) {
            if (is_string($setting) && $setting !== '') {
                $settings[] = $setting;
            }
        }
        if (is_array($marker)
            && $settings !== []
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

    private static function envHash(string $projectRoot): string
    {
        $env = @file_get_contents($projectRoot . '/.env');

        return is_string($env) ? sha1($env) : 'absent';
    }
}
