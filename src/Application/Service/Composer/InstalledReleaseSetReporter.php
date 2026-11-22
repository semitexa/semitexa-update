<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Records the release vendor/ now holds ({@see InstalledReleaseSetRecorder})
 * and says it at the end of `bin/semitexa update`. Whatever the stages did: a
 * failed composer stage restores vendor/, and the record must follow it back.
 *
 * Quiet under --no-composer: the record is still brought in line with vendor/
 * (composer may have been run by hand), but a run that installed nothing does
 * not end on an "Installed release" line. test:run seeds its E2E database with
 * such a run, and that line read as the framework having been updated
 * (tk-e2e-seeding-reads-like-a-framework-update).
 */
final class InstalledReleaseSetReporter
{
    public static function record(SymfonyStyle $io, string $projectRoot, bool $quiet = false): void
    {
        try {
            $release = (new InstalledReleaseSetRecorder())->record($projectRoot);
        } catch (\Throwable $e) {
            if (!$quiet) {
                $io->text('  <comment>Installed release not recorded: ' . $e->getMessage() . '</comment>');
            }
            return;
        }
        if ($quiet) {
            return;
        }

        $io->text(match (true) {
            is_string($release) => '  Installed release: semitexa/ultimate ' . $release,
            $release === false => '  <comment>Installed release not recorded: Packagist could not be asked.</comment>',
            default => '  Installed release: none (vendor/ holds no whole semitexa/ultimate release).',
        });
    }
}
