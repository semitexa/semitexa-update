<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

use Semitexa\Update\Application\Service\RequiredSettings\DoctorProbe;
use Semitexa\Update\Domain\Model\DeploymentPlan;
use Semitexa\Update\Exception\RequiredSettingsMissingException;

/**
 * The auto-deploy's gate on required settings: the release just installed may
 * need a setting .env has never had (APP_SECRET, 20.11: an hour of 500s).
 *
 * Three points of one deploy:
 *  - waiting(): before anything runs. A release rolled back for a missing
 *    setting is still the newest one; unmarked, every tick installed it,
 *    rolled back and restarted. Nothing is tried while the offer and .env are
 *    both what they were then ({@see RequiredSettingsBlock}).
 *  - assert(): after composer, before orm:sync, so the throw rolls back code
 *    alone and the database was not moved. The throw is an ordinary deploy
 *    failure: nothing is written to rejected.json (only a failed health check
 *    is), because the release is sound.
 *  - blocked(): after the rollback, remembers the block for the next ticks.
 *
 * Nothing clears the marker after a deploy that went in: waiting() has
 * already removed one that no longer held, before that deploy began.
 *
 * A doctor that could not be run or read stops nothing: it is said in the
 * result, and the health check still stands behind it.
 */
final class RequiredSettingsGate
{
    /**
     * @param DoctorProbe|null $probe null: nothing is asked (a deploy whose shell is faked spawns no doctor either)
     */
    public function __construct(
        private readonly ?DoctorProbe $probe,
    ) {
    }

    /**
     * The executor's wiring: the probe it was given, or the real one. Under a
     * faked shell (tests) there is no real one, and nothing is asked.
     */
    public static function with(?DoctorProbe $probe, bool $realByDefault): self
    {
        return new self($probe ?? ($realByDefault ? new DoctorProbe() : null));
    }

    /**
     * True when this release set was blocked and .env has not changed since:
     * the result then says which settings it waits for.
     *
     * @param array<string, mixed> $result
     */
    public function waiting(string $projectRoot, DeploymentPlan $plan, array &$result): bool
    {
        $stillMissing = RequiredSettingsBlock::holds($projectRoot, $plan);
        if ($stillMissing === null) {
            return false;
        }
        $result['required_settings'] = 'missing: ' . implode(', ', $stillMissing);
        $result['reason'] = sprintf(
            'These releases need %s %s, which this environment does not have: the last deploy of them was rolled back. '
            . 'Waiting for .env to change or for newer releases. Set in the process environment instead of .env? Delete var/update/rollback/blocked.json to try again.',
            count($stillMissing) === 1 ? 'the setting' : 'the settings',
            implode(', ', $stillMissing),
        );

        return true;
    }

    /**
     * Asks the doctor of the installed code; throws when a failing check names a setting.
     *
     * @param array<string, mixed> $result
     */
    public function assert(string $projectRoot, array &$result): void
    {
        if ($this->probe === null) {
            return;
        }

        $report = $this->probe->probe($projectRoot);
        $result['required_settings'] = match (true) {
            !$report->isSuccess() => 'missing: ' . implode(', ', $report->settingNames()),
            $report->probeError !== null => 'WARNING: not checked (APP_ENV=' . $report->appEnv . '): ' . $report->probeError,
            default => 'ok (APP_ENV=' . $report->appEnv . ')',
        };

        $blocked = $report->describe();
        if ($blocked !== null) {
            throw new RequiredSettingsMissingException(
                $blocked . '. The release is not rejected: it is deployed once the setting is there.',
                $report->settingNames(),
            );
        }
    }

    /**
     * After the rollback of a deploy assert() stopped: with the old code back,
     * the block is remembered, so the next tick waits for .env instead of
     * doing this again. Any other failure, or a rollback that FAILED, records
     * nothing.
     *
     * @param array<string, mixed> $result
     */
    public function blocked(string $projectRoot, DeploymentPlan $plan, \Throwable $failure, array &$result): void
    {
        $rollback = $result['rollback'] ?? null;
        if (!$failure instanceof RequiredSettingsMissingException || !is_string($rollback) || str_starts_with($rollback, 'FAILED')) {
            return;
        }
        $result['blocked'] = RequiredSettingsBlock::record($projectRoot, $plan, $failure->settings)
            ? 'recorded: no deploy of these releases until .env changes'
            : 'not recorded: the next tick may deploy the same releases again';
    }
}
