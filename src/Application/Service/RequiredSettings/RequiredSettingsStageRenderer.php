<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\RequiredSettings;

use Semitexa\Update\Domain\Model\RequiredSettings\RequiredSettingsReport;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders the `required-settings` stage of `bin/semitexa update`: each missing
 * setting with the check that judged it, the APP_ENV it was judged under and
 * the check's own fix. The operator has to be able to act on this output alone.
 */
final class RequiredSettingsStageRenderer
{
    /** Writes nothing when the stage is not this one (no report). */
    public static function render(SymfonyStyle $io, ?RequiredSettingsReport $report): void
    {
        if ($report === null) {
            return;
        }
        $escape = OutputFormatter::escape(...);

        if ($report->probeError !== null) {
            $io->writeln('  <comment>WARNING: required settings were NOT checked (APP_ENV=' . $escape($report->appEnv) . '): '
                . $escape($report->probeError) . '</comment>');
            $io->writeln('  <comment>The update goes on. Run `bin/semitexa system:doctor` yourself before a restart.</comment>');

            return;
        }

        if ($report->isSuccess()) {
            $io->writeln('  APP_ENV=' . $escape($report->appEnv) . ': no required setting is missing.');

            return;
        }

        $io->writeln('  <error>The installed code needs settings this environment (APP_ENV=' . $escape($report->appEnv) . ') does not have:</error>');
        foreach ($report->missing as $missing) {
            $io->writeln(sprintf(
                '    <error>%s</error>  (check %s%s)',
                $escape(implode(', ', $missing->settings)),
                $escape($missing->check),
                $missing->package !== '' ? ', ' . $escape($missing->package) : '',
            ));
            if ($missing->message !== '') {
                $io->writeln('      ' . $escape($missing->message));
            }
            if ($missing->hint !== null) {
                $io->writeln('      fix: ' . $escape($missing->hint));
            }
        }
        $io->writeln('  The scaffold, the schema and the data patches were not touched. Set '
            . (count($report->settingNames()) === 1 ? 'it' : 'them')
            . ' in .env (or the process environment) and run `bin/semitexa update` again.');
    }
}
