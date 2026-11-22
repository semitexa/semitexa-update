<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

/**
 * Whether this deploy runs under the systemd wrapper on the host
 * (tools/run-auto-deploy-systemd.sh), which restarts the stack itself when
 * the result says restart_required. The report is written inside the app
 * container, which cannot see the wrapper, and told the operator to make a
 * restart the wrapper had already made.
 *
 * The wrapper holds a shared flock on var/lock/semitexa-auto-deploy-wrapper.lock
 * while update:packages:auto runs; the project directory is the same files on
 * both sides, as for UpdateLock. A lock, not a marker file or an environment
 * variable: the OS drops it when the wrapper dies, so a killed run leaves
 * nothing that says "the wrapper restarts" to a later manual one, and
 * bin/semitexa passes none of the caller's environment into the container.
 * Anything unknown (no file, unreadable, a host without flock) answers false:
 * the report then asks the operator, as it always did.
 */
final class WrapperRun
{
    public const RELATIVE_PATH = 'var/lock/semitexa-auto-deploy-wrapper.lock';

    public const RESTARTS = 'the wrapper that started this run restarts the stack.';

    public static function holds(string $projectRoot): bool
    {
        $path = rtrim($projectRoot, '/') . '/' . self::RELATIVE_PATH;
        // Read-only, never created: only the wrapper makes the file.
        $handle = is_file($path) ? @fopen($path, 'r') : false;
        if ($handle === false) {
            return false;
        }

        try {
            $wouldBlock = 0;
            if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                flock($handle, LOCK_UN);

                return false;
            }

            // Refused because somebody holds it, not because locks do not work here.
            return $wouldBlock === 1;
        } finally {
            fclose($handle);
        }
    }
}
