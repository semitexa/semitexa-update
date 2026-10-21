<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Service;

use Semitexa\Core\Environment;
use Semitexa\Update\Application\Service\HealthChecker;
use Semitexa\Update\Application\Service\PackageDriftInspector;
use Semitexa\Update\Domain\Enum\PackageDriftStatus;
use Semitexa\Update\Domain\Model\PackageDrift\PackageDriftEntry;
use Semitexa\Update\Application\Service\RunJournalRepository;
use Semitexa\Update\Application\Service\UpdateLock;
use Semitexa\Update\Domain\Enum\RunOutcome;
use Semitexa\Update\Domain\Model\DeploymentPlan;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\ComposerStateSnapshot;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\DeploymentLogWriter;

final class FrameworkDeploymentExecutor
{
    /**
     * $commandRunner overrides shell execution — tests inject a fake to
     * exercise partial-failure/rollback ordering without running composer.
     * Signature: fn (string $command, string $projectRoot): void, throw on
     * failure (same contract as run()).
     */
    public function __construct(
        private readonly DeploymentLogWriter $logWriter = new DeploymentLogWriter(),
        private readonly ?RunJournalRepository $runJournal = null,
        private readonly ?UpdateLock $lock = null,
        private readonly ?\Closure $commandRunner = null,
        private readonly HealthChecker $healthChecker = new HealthChecker(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $projectRoot, DeploymentPlan $plan): array
    {
        $startedAt = gmdate(DATE_ATOM);
        $result = [
            'started_at' => $startedAt,
            'finished_at' => null,
            'status' => 'skipped',
            'reason' => $plan->reason,
            'release_channel' => $plan->config->channel,
            'source_mode' => $plan->config->sourceMode,
            'selected_version' => $plan->selectedVersion,
            'private_latest_version' => $plan->privateLatestVersion,
            'installed_packages' => $plan->installedPackages,
            'updated_packages' => array_map(
                static fn($update) => [
                    'package' => $update->packageName,
                    'installed_version' => $update->installedVersion,
                    'latest_version' => $update->latestVersion,
                    'source' => $update->source,
                ],
                $plan->packageUpdates,
            ),
            'restart_required' => false,
        ];

        if ($plan->sourceWarnings !== []) {
            $result['source_warnings'] = $plan->sourceWarnings;
        }

        $runId = null;
        $lock = null;
        $snapshot = null;
        $composerUpdated = false;
        $composerStarted = false;
        $lockBefore = false;
        try {
            if (!$plan->config->enabled) {
                return $this->finalize($projectRoot, $result, 'skipped');
            }

            if (!$plan->updateAvailable) {
                return $this->finalize($projectRoot, $result, 'noop');
            }

            // The planner reads the newest releases, not composer.json. A
            // project made from semitexa/ultimate pins every semitexa/*
            // exactly: composer could move none of them, yet every tick ran
            // it and reported "updated" with versions that were never
            // installed. Said, and composer not run, when nothing can move.
            $pinned = self::pinnedWhereTheyAre($projectRoot, $plan);
            if ($pinned !== null) {
                $result['reason'] = sprintf(
                    'Newer releases exist, but composer.json pins %s at the installed version, so nothing can move. Take them with bin/semitexa update, which moves the pins.',
                    count($pinned) === 1 ? $pinned[0] : count($pinned) . ' packages',
                );

                return $this->finalize($projectRoot, $result, 'noop');
            }

            // Same lock as the manual `update` sweep: never race a running
            // operator update from the auto-deploy timer (or vice versa).
            $lock = $this->lock ?? new UpdateLock($projectRoot);
            if (!$lock->acquire('auto-deploy')) {
                throw new \RuntimeException(sprintf(
                    'Another update run is already in progress (%s); auto-deploy will retry on the next tick.',
                    $lock->holderDescription(),
                ));
            }

            // Only actual deployment attempts enter the run journal; the
            // periodic timer's skipped/noop ticks would drown real history.
            $runId = $this->beginRunRecord($result);

            $snapshot = ComposerStateSnapshot::capture($projectRoot);
            $lockBefore = @file_get_contents($projectRoot . '/composer.lock');

            $composerCommand = $this->composerUpdateCommand($projectRoot);
            $composerStarted = true;
            $this->run($composerCommand, $projectRoot);
            $composerUpdated = true;

            $this->assertVendorMatchesLock($projectRoot);

            // What was installed, not what was planned: a constraint can hold
            // a package back, and the journal said it moved.
            $installed = self::installedVersions($projectRoot);
            $moved = false;
            foreach ($result['updated_packages'] as $i => $update) {
                $deployed = $installed[$update['package']] ?? null;
                $result['updated_packages'][$i]['deployed_version'] = $deployed;
                $moved = $moved || ($deployed !== null && $deployed !== $update['installed_version']);
            }
            // Composer ran and installed none of them (a constraint held one
            // back, or a pinned package the newer one needs) and nothing else
            // in the lock changed: nothing to sync or restart. Taken for an
            // update, it restarted the app every tick.
            if (!$moved && is_string($lockBefore) && $lockBefore === @file_get_contents($projectRoot . '/composer.lock')) {
                $result['reason'] = 'Newer releases exist, but composer installed none of them: a constraint in composer.json holds them back. bin/semitexa update moves the pins to the newest release set.';

                return $this->finalize($projectRoot, $result, 'noop', $plan, $runId);
            }

            $this->run($this->projectCliCommand($projectRoot, 'orm:sync'), $projectRoot);
            $this->run($this->projectCliCommand($projectRoot, 'cache:clear'), $projectRoot);

            $restartStatus = $this->restartRuntime($projectRoot, $plan->config->restartCommand);
            $result['restart_required'] = !$restartStatus['performed'];
            $result['restart_status'] = $restartStatus['message'];

            // Mandatory when configured: a deploy that cannot prove the app
            // answers is a failed deploy, restart or not. Without a restart
            // (inside the app container) it asks workers cache:clear reloaded
            // on the new vendor/; it is the only check that can roll back.
            // After a restart the server is given a few seconds to come up.
            if ($plan->config->healthcheckUrl !== null) {
                $attempts = $restartStatus['performed'] ? 4 : 1;
                for ($attempt = 1; ; $attempt++) {
                    $check = $this->healthChecker->check($plan->config->healthcheckUrl);
                    if ($check->ok || $attempt >= $attempts) {
                        break;
                    }
                    sleep(3);
                }
                if (!$check->ok) {
                    throw new \RuntimeException($check->message);
                }
                $result['healthcheck'] = $check->message;
            } else {
                $result['healthcheck'] = 'skipped: SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL not configured';
            }

            return $this->finalize($projectRoot, $result, 'updated', $plan, $runId);
        } catch (\Throwable $e) {
            $result['status'] = 'failed';
            $result['reason'] = $e->getMessage();
            if ($composerStarted && !$composerUpdated && $lockBefore === @file_get_contents($projectRoot . '/composer.lock')) {
                // Composer failed before it changed the lock (Packagist
                // unreachable, a token expired): nothing to roll back, and no
                // restart every tick for as long as that lasts.
                $result['rollback'] = 'not needed: composer failed before it changed composer.lock.';
            } elseif ($composerStarted && $snapshot !== null) {
                // Also when composer failed half-way: it may already have
                // rewritten the lock or part of vendor/.
                $result['rollback'] = $this->rollback($projectRoot, $snapshot, $plan->config->restartCommand);
                // Rolled back: vendor/ no longer holds what was read after the update.
                foreach ($result['updated_packages'] as $i => $update) {
                    unset($result['updated_packages'][$i]['deployed_version']);
                }
            }
            return $this->finalize($projectRoot, $result, 'failed', $plan, $runId);
        } finally {
            $lock?->release();
        }
    }

    /**
     * composer can rewrite composer.lock and leave vendor/ exactly as it was:
     * a dist that 404s on a private repo, an expired token, a process killed
     * mid-install. The command still exits, the lock still advertises the new
     * release, and the workers keep loading the old one — which is how
     * semitexa.com served an eight-day-old framework while every run logged a
     * clean result.
     *
     * Only vendor/composer/installed.json settles it. A mixed release set is
     * not checked here: production legitimately spans several release dates,
     * and path repositories and dev constraints are somebody's working tree.
     */
    private function assertVendorMatchesLock(string $projectRoot): void
    {
        // No installed.json means Composer has never written this vendor tree.
        // The inspector would read that as every package missing, so the check
        // has nothing to say rather than everything.
        if (!is_file($projectRoot . '/vendor/composer/installed.json')) {
            return;
        }

        // The deploy installs with --no-dev, which takes require-dev packages
        // out of vendor/ while the lock keeps them: semitexa/testing, which
        // every project made from semitexa/ultimate requires for dev, was
        // "missing" after every update and every deploy was rolled back.
        $devOnly = self::devPackagesLeftOut($projectRoot);
        $stale = array_values(array_filter(
            (new PackageDriftInspector())->inspect($projectRoot)->entries,
            static fn (PackageDriftEntry $entry): bool => in_array($entry->status, [
                PackageDriftStatus::VendorStale,
                PackageDriftStatus::MissingFromVendor,
                PackageDriftStatus::VersionMismatch,
            ], true) && !($entry->status === PackageDriftStatus::MissingFromVendor && isset($devOnly[$entry->name])),
        ));

        if ($stale === []) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'composer finished but vendor/ still disagrees with composer.lock for %d package(s): %s',
            count($stale),
            implode('; ', array_map(
                static fn (PackageDriftEntry $entry): string => sprintf(
                    '%s (lock %s, vendor %s)',
                    $entry->name,
                    $entry->locked ?? 'absent',
                    $entry->installed ?? 'absent',
                ),
                array_slice($stale, 0, 5),
            )),
        ));
    }

    /**
     * The planned packages, when composer.json pins every one of them at the
     * version installed (`"2026.10.19.0811"`, `"==…"`, `"v…"`); null when any
     * could move.
     *
     * @return list<string>|null
     */
    private static function pinnedWhereTheyAre(string $projectRoot, DeploymentPlan $plan): ?array
    {
        $manifest = json_decode((string) @file_get_contents($projectRoot . '/composer.json'), true);
        if (!is_array($manifest) || $plan->packageUpdates === []) {
            return null;
        }
        $declared = array_merge(
            is_array($manifest['require-dev'] ?? null) ? $manifest['require-dev'] : [],
            is_array($manifest['require'] ?? null) ? $manifest['require'] : [],
        );
        $pinned = [];
        foreach ($plan->packageUpdates as $update) {
            $constraint = $declared[$update->packageName] ?? null;
            if (!is_string($constraint) || self::bareVersion(ltrim(trim($constraint), '=')) !== self::bareVersion($update->installedVersion)) {
                return null;
            }
            $pinned[] = $update->packageName;
        }

        return $pinned;
    }

    private static function bareVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    /** @return array<string, string> package => version, as vendor/composer/installed.json has it */
    private static function installedVersions(string $projectRoot): array
    {
        $installed = json_decode((string) @file_get_contents($projectRoot . '/vendor/composer/installed.json'), true);
        $versions = [];
        foreach ((is_array($installed) ? ($installed['packages'] ?? []) : []) as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $versions[$package['name']] = self::bareVersion($package['version']);
            }
        }

        return $versions;
    }

    /**
     * The lock's require-dev packages, when vendor/ was installed without
     * them (installed.json says "dev": false); none otherwise, so a dev
     * install that lost one still fails.
     *
     * @return array<string, true>
     */
    private static function devPackagesLeftOut(string $projectRoot): array
    {
        $installed = json_decode((string) @file_get_contents($projectRoot . '/vendor/composer/installed.json'), true);
        $lock = json_decode((string) @file_get_contents($projectRoot . '/composer.lock'), true);
        if (!is_array($installed) || ($installed['dev'] ?? true) !== false || !is_array($lock) || !is_array($lock['packages-dev'] ?? null)) {
            return [];
        }

        $names = [];
        foreach ($lock['packages-dev'] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $names[$package['name']] = true;
            }
        }
        foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                unset($names[$package['name']]);
            }
        }

        return $names;
    }

    /**
     * Best-effort revert to the pre-update dependency state after a failure
     * once composer had changed something (a later step failed, or composer
     * failed after rewriting the lock): restore composer files, reinstall
     * vendor from the restored lock, restart. The returned
     * string lands in the deployment log + run journal either way.
     */
    private function rollback(string $projectRoot, ComposerStateSnapshot $snapshot, ?string $restartCommand): string
    {
        if (!$snapshot->restoreFiles()) {
            return 'FAILED: could not restore composer.json/lock — system left on the new version; operator intervention required.';
        }

        try {
            $this->run($this->composerInstallCommand($projectRoot), $projectRoot);
        } catch (\Throwable $e) {
            return 'FAILED: composer files restored but composer install failed — vendor/ still on the new version: ' . $e->getMessage();
        }

        try {
            $this->restartRuntime($projectRoot, $restartCommand);
        } catch (\Throwable $e) {
            return 'partial: dependency state restored, but restart failed — restart manually: ' . $e->getMessage();
        }

        return 'performed: composer.json/lock and vendor/ restored to the pre-update state.';
    }

    private function composerInstallCommand(string $projectRoot): string
    {
        return sprintf(
            '%s install --prefer-dist --no-dev --no-interaction --optimize-autoloader --working-dir=%s',
            $this->composerBinary($projectRoot),
            escapeshellarg($projectRoot),
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function beginRunRecord(array &$result): ?string
    {
        if ($this->runJournal === null) {
            return null;
        }

        $actor = Environment::getEnvValue('SEMITEXA_UPDATE_ACTOR', null);
        $actor = is_string($actor) && trim($actor) !== '' ? trim($actor) : 'auto-deploy';

        try {
            return $this->runJournal->begin(RunJournalRepository::KIND_AUTO_DEPLOY, $actor, null);
        } catch (\Throwable $e) {
            $result['run_journal'] = 'unavailable: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function finishRunRecord(array &$result, string $status, DeploymentPlan $plan, ?string $runId): void
    {
        if ($this->runJournal === null || $runId === null) {
            return;
        }

        // An update journals what vendor/ now holds (nothing for a package it
        // cannot read there); a failed one what it attempted, rolled back; a
        // noop nothing.
        $deltas = [];
        foreach ($status === 'noop' ? [] : ($result['updated_packages'] ?? []) as $update) {
            $to = $status === 'updated' ? ($update['deployed_version'] ?? null) : $update['latest_version'];
            if ($to !== null && $to !== $update['installed_version']) {
                $deltas[$update['package']] = ['from' => $update['installed_version'], 'to' => $to];
            }
        }

        $outcome = match ($status) {
            'updated' => RunOutcome::Success,
            'noop' => RunOutcome::Noop,
            default => RunOutcome::Failed,
        };
        $stage = [
            'name'    => 'auto-deploy',
            'success' => $outcome !== RunOutcome::Failed,
            'message' => (string) ($outcome === RunOutcome::Noop ? $result['reason'] : ($result['restart_status'] ?? '')),
        ];
        if (isset($result['healthcheck'])) {
            $stage['healthcheck'] = (string) $result['healthcheck'];
        }
        if (isset($result['rollback'])) {
            $stage['rollback'] = (string) $result['rollback'];
        }
        $stages = [$stage];

        try {
            $this->runJournal->finish(
                $runId,
                $outcome,
                $outcome === RunOutcome::Failed ? 'auto-deploy' : null,
                $stages,
                $deltas,
                0,
                $outcome === RunOutcome::Failed ? (string) $result['reason'] : null,
            );
            $result['run_journal'] = 'recorded run ' . $runId;
        } catch (\Throwable $e) {
            $result['run_journal'] = 'unavailable: ' . $e->getMessage();
        }
    }

    private function composerUpdateCommand(string $projectRoot): string
    {
        // --prefer-dist: vendor/semitexa/* is shipped as dist (no .git/), but composer.json
        // declares vcs repositories, so without this flag composer will choose source mode
        // for some packages and fail with "GitDownloader.php line 155: The .git directory
        // is missing" the next time those packages upgrade.
        return sprintf(
            '%s update %s --with-all-dependencies --prefer-dist --no-dev --no-interaction --optimize-autoloader --working-dir=%s',
            $this->composerBinary($projectRoot),
            escapeshellarg('semitexa/*'),
            escapeshellarg($projectRoot),
        );
    }

    private function projectCliCommand(string $projectRoot, string $command): string
    {
        $cli = $projectRoot . '/vendor/bin/semitexa';
        if (!is_file($cli)) {
            throw new \RuntimeException('vendor/bin/semitexa not found. Run composer install before deployment.');
        }

        return sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($cli), escapeshellarg($command));
    }

    private function composerBinary(string $projectRoot): string
    {
        $globalComposer = trim((string) shell_exec('command -v composer 2>/dev/null'));
        if ($globalComposer !== '') {
            return escapeshellarg($globalComposer);
        }

        $localComposer = $projectRoot . '/vendor/bin/composer';
        if (is_file($localComposer)) {
            return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($localComposer);
        }

        throw new \RuntimeException('Composer not found.');
    }

    /**
     * @return array{performed: bool, message: string}
     */
    private function restartRuntime(string $projectRoot, ?string $restartCommand): array
    {
        if ($restartCommand !== null) {
            $this->run($restartCommand, $projectRoot);
            return ['performed' => true, 'message' => 'Restart command executed from configuration.'];
        }

        // Do not attempt host-level compose restarts from inside a container.
        if (is_file($projectRoot . '/docker-compose.yml') && trim((string) shell_exec('command -v docker 2>/dev/null')) !== '' && !is_file('/.dockerenv')) {
            $this->run('docker compose restart', $projectRoot);
            return ['performed' => true, 'message' => 'Restarted docker compose services.'];
        }

        return ['performed' => false, 'message' => 'No restart command available; operator restart required.'];
    }

    private function run(string $command, string $projectRoot): void
    {
        if ($this->commandRunner !== null) {
            ($this->commandRunner)($command, $projectRoot);
            return;
        }

        $fullCommand = sprintf(
            'cd %s && %s%s 2>&1',
            escapeshellarg($projectRoot),
            $this->shellEnvironmentPrefix(),
            $command,
        );
        $output = [];
        exec($fullCommand, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("Command failed: {$command}\n" . implode("\n", $output));
        }
    }

    private function shellEnvironmentPrefix(): string
    {
        $exports = [];
        $environmentMap = [
            'HOME' => Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_HOME', null),
            'COMPOSER_HOME' => Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_COMPOSER_HOME', null),
            'GIT_SSH_COMMAND' => Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_GIT_SSH_COMMAND', null),
        ];

        foreach ($environmentMap as $name => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $exports[] = sprintf('export %s=%s', $name, escapeshellarg($value));
        }

        if ($exports === []) {
            return '';
        }

        return implode(' && ', $exports) . ' && ';
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function finalize(string $projectRoot, array $result, string $status, ?DeploymentPlan $plan = null, ?string $runId = null): array
    {
        $result['status'] = $status;
        $result['finished_at'] = gmdate(DATE_ATOM);
        if ($plan !== null) {
            $this->finishRunRecord($result, $status, $plan, $runId);
        }
        // A log-write failure must never bubble into the deploy try/catch:
        // it would mark (and roll back) an otherwise healthy release. The
        // failure is still visible in the returned/printed result payload.
        try {
            $this->logWriter->write($projectRoot, $result);
        } catch (\Throwable $e) {
            $result['deployment_log'] = 'unavailable: ' . $e->getMessage();
        }
        return $result;
    }
}
