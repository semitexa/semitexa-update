<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

use Semitexa\Update\Application\Service\Packaging\Releases\Support\ComposerStateSnapshot;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\SemitexaReleaseVersion;
use Semitexa\Update\Domain\Enum\ComposerUpdateOutcome;
use Semitexa\Update\Domain\Model\Composer\ComposerUpdatePlan;
use Semitexa\Update\Domain\Model\Composer\ComposerUpdatePlanEntry;
use Semitexa\Update\Domain\Model\Composer\ComposerUpdateResult;

/**
 * The Composer-update phase of `bin/semitexa update`.
 *
 * Order of operations:
 *
 *   1. Plan (ComposerUpdatePlanner): classify every semitexa/* pin and
 *      target each exact pin at the release set — the latest
 *      `semitexa/ultimate` release's pins — never lower than declared.
 *
 *   2. Dry run: ask composer itself whether the plan resolves, against a
 *      scratch copy of composer.json. Nothing of the project's is touched.
 *
 *   3. Real run: snapshot composer.json + composer.lock, rewrite the pins, run
 *      `composer update "semitexa/*" -W`. If composer fails, both files are
 *      put back byte for byte (and vendor/ reinstalled from them if composer
 *      had already moved it) — a failed run leaves the project as it found it.
 *
 *   4. Report the installed semitexa/* versions that actually moved. If
 *      semitexa/update itself moved, return UpdaterChanged so the next stages
 *      run on fresh code.
 *
 * Path-repo, @dev, dev-* and "*" pins are reported in the plan but never
 * mutated. They are still WATCHED — see step 4 — because composer moves them
 * even when we do not.
 *
 * The phase is skipped entirely when there is nothing to do: no pins to bump,
 * lock and vendor coherent, and no wildcard package installed behind what the
 * release set offers.
 */
final class ComposerUpdateRunner
{
    private const PREFIX = 'semitexa/';
    private const UPDATER_PACKAGE = 'semitexa/update';

    /** Composer's exit code for "could not resolve": it fails before touching vendor/. */
    private const COMPOSER_RESOLUTION_FAILED = 2;

    /**
     * Scratch composer file the dry run rehearses against; the lock follows its
     * name. Each run gets its own: dry runs take no update lock, and a shared
     * name let one run delete the other's files mid-rehearsal.
     */
    private const REHEARSAL_FILE = 'composer.semitexa-update-plan-%s.json';

    private readonly ComposerUpdatePlanner $planner;

    public function __construct(
        private readonly ComposerExecutorInterface $executor,
        UpstreamVersionResolverInterface $resolver,
        private readonly ComposerProjectState $state = new ComposerProjectState(),
    ) {
        $this->planner = new ComposerUpdatePlanner($resolver, $state);
    }

    public function plan(string $projectRoot): ComposerUpdatePlan
    {
        return $this->planner->plan($projectRoot, $this->executor->isAvailable(), $this->executor->containerError());
    }

    public function execute(
        string $projectRoot,
        bool $dryRun = false,
        bool $skip = false,
        bool $allowPartial = false,
        bool $force = false,
    ): ComposerUpdateResult {
        if ($skip) {
            return new ComposerUpdateResult(
                outcome: ComposerUpdateOutcome::Skipped,
                bumpedPackages: [],
                installedBefore: $this->state->installedVersion($projectRoot, self::UPDATER_PACKAGE),
                installedAfter: $this->state->installedVersion($projectRoot, self::UPDATER_PACKAGE),
                composerExitCode: 0,
                composerOutput: '',
                message: 'Composer phase skipped via --no-composer.',
            );
        }

        $plan = $this->plan($projectRoot);

        if (!$plan->inContainer) {
            return new ComposerUpdateResult(
                outcome: ComposerUpdateOutcome::Failed,
                bumpedPackages: [],
                installedBefore: $this->state->installedVersion($projectRoot, self::UPDATER_PACKAGE),
                installedAfter: $this->state->installedVersion($projectRoot, self::UPDATER_PACKAGE),
                composerExitCode: 1,
                composerOutput: $plan->containerError,
                message: 'Composer phase refused to run: ' . $plan->containerError,
            );
        }

        // Unresolved upstream is a blocking failure by default. The operator
        // must explicitly opt in with --allow-partial-composer-update to
        // proceed with whatever bumps we could resolve.
        $unresolved = array_map(static fn ($e) => $e->name, $plan->unresolvedEntries());
        if ($plan->releaseSetUnreachable) {
            array_unshift($unresolved, 'semitexa/ultimate (the release set)');
        }
        if ($unresolved !== [] && !$allowPartial) {
            $names = $unresolved;
            $installedBefore = $this->state->installedVersion($projectRoot, self::UPDATER_PACKAGE);
            return new ComposerUpdateResult(
                outcome: ComposerUpdateOutcome::Failed,
                bumpedPackages: [],
                installedBefore: $installedBefore,
                installedAfter: $installedBefore,
                composerExitCode: 1,
                composerOutput: '',
                message: sprintf(
                    'Composer-update phase blocked: upstream metadata could not be resolved for %d semitexa/* package(s): %s — the registry could not be reached. '
                    . 'Retry with network access, or rerun with --allow-partial-composer-update to proceed with only the packages that could be resolved.',
                    count($unresolved),
                    implode(', ', $names),
                ),
            );
        }

        $bumps = $plan->entriesToBump();
        $installedBefore = $this->state->installedVersion($projectRoot, self::UPDATER_PACKAGE);

        // No bumps, no lock/vendor drift, no explicit force → skip the
        // composer call entirely. Invoking `composer update` in this state
        // is a no-op for versions but still rewrites composer.lock's
        // content-hash field, producing noisy git diffs on every plain
        // `bin/semitexa update`. The `$force` flag (wired to --composer-only)
        // is the explicit operator override.
        $driftReason = $this->lockOrVendorDriftReason($projectRoot)
            ?? $this->wildcardBehindReason($plan);
        if ($bumps === [] && $driftReason === null && !$force) {
            return new ComposerUpdateResult(
                outcome: ComposerUpdateOutcome::Clean,
                bumpedPackages: [],
                installedBefore: $installedBefore,
                installedAfter: $installedBefore,
                composerExitCode: 0,
                composerOutput: '',
                message: 'Composer phase skipped: no pin needs bumping and composer.lock/vendor are coherent. '
                    . 'Use --composer-only to force a composer invocation anyway.',
            );
        }

        // Past the early return above, unresolved entries mean --allow-partial.
        $degradedTail = $unresolved !== []
            ? sprintf(
                ' DEGRADED: upstream metadata could not be read for %d package(s); their pins were left alone: %s.',
                count($unresolved),
                implode(', ', $unresolved),
            )
            : '';

        if ($dryRun) {
            return $this->rehearse($projectRoot, $plan, $bumps, $installedBefore, $driftReason, $force, $degradedTail);
        }

        $snapshot = ComposerStateSnapshot::capture($projectRoot);
        if ($snapshot === null) {
            return new ComposerUpdateResult(
                outcome: ComposerUpdateOutcome::Failed,
                bumpedPackages: [],
                installedBefore: $installedBefore,
                installedAfter: $installedBefore,
                composerExitCode: 1,
                composerOutput: '',
                message: 'Refusing to run composer — composer.json could not be read to take a restore point.',
            );
        }

        // 1. Rewrite pins
        if ($bumps !== []) {
            try {
                $this->rewriteComposerJsonPins($projectRoot . '/composer.json', $bumps);
            } catch (\Throwable $e) {
                $restored = $snapshot->restoreFiles();
                return new ComposerUpdateResult(
                    outcome: ComposerUpdateOutcome::Failed,
                    bumpedPackages: [],
                    installedBefore: $installedBefore,
                    installedAfter: $installedBefore,
                    composerExitCode: 1,
                    composerOutput: $e->getMessage(),
                    message: 'Refusing to run composer — pin rewrite failed: ' . $e->getMessage()
                        . ($restored ? '' : ' composer.json could NOT be restored — check it before rerunning.'),
                );
            }
        }

        // 2. Run composer
        //
        // Snapshot the WHOLE semitexa/* set on both sides of the call. Counting
        // only the pins we rewrote answers "what did this runner change", which
        // is not the question an operator is asking. A project that declares its
        // packages as wildcards has no pins to rewrite, so composer resolves the
        // new versions on its own and the runner used to report "No
        // release-pinned semitexa/* package needed a bump" over an update that
        // had just moved seven packages — and journal it as a noop. Reported that
        // way, a real update is indistinguishable from nothing happening.
        $versionsBefore = $this->state->semitexaVersions($projectRoot);

        $exec = $this->executor->run(
            [...['update', self::PREFIX . '*', '-W', '--no-interaction'], ...$this->devMode($projectRoot)],
            $projectRoot,
        );

        if ($exec['exitCode'] !== 0) {
            return $this->rollBack($projectRoot, $snapshot, $exec, $versionsBefore, $installedBefore);
        }

        $bumpedSummary = $this->moves($versionsBefore, $this->state->semitexaVersions($projectRoot));

        $installedAfter = $this->state->installedVersion($projectRoot, self::UPDATER_PACKAGE);
        $updaterChanged = $installedBefore !== $installedAfter
            && $installedBefore !== null
            && $installedAfter !== null;

        $outcome = $updaterChanged
            ? ComposerUpdateOutcome::UpdaterChanged
            : ($bumpedSummary !== [] ? ComposerUpdateOutcome::Updated : ComposerUpdateOutcome::Clean);

        $message = match ($outcome) {
            // The degraded tail rides along here too: a run that could not resolve
            // part of the set is degraded whether or not the updater happened to
            // move, and dropping the warning because of an unrelated coincidence
            // is how a partial update comes to look like a complete one.
            ComposerUpdateOutcome::UpdaterChanged => sprintf(
                'semitexa/update was upgraded (%s → %s). The remaining stages must run on the new code, '
                . 'so this process stops here and `bin/semitexa update` continues in a fresh one.%s',
                $installedBefore,
                $installedAfter,
                $degradedTail,
            ),
            ComposerUpdateOutcome::Updated => sprintf(
                'composer update moved %d semitexa/* package(s): %s.%s',
                count($bumpedSummary),
                $this->describeMoves($bumpedSummary),
                $degradedTail,
            ),
            default => 'composer update ran; no semitexa/* package changed version.' . $degradedTail,
        };

        return new ComposerUpdateResult(
            outcome: $outcome,
            bumpedPackages: $bumpedSummary,
            installedBefore: $installedBefore,
            installedAfter: $installedAfter,
            composerExitCode: 0,
            composerOutput: $this->tail($exec['output'], 4096),
            message: $message,
        );
    }

    /**
     * Ask composer whether the plan resolves, without touching the project.
     *
     * The dry run used to print the plan and call it WouldRun without ever
     * asking — so it announced success for runs that could not resolve, and
     * the operator learned otherwise from a real run that had already
     * rewritten composer.json. Composer is the only authority on what
     * resolves (it alone knows the project's repositories, path packages and
     * platform), so it is asked, against a scratch copy of composer.json and
     * composer.lock named through `COMPOSER=`: the project's own files are
     * never written, and relative paths still resolve from the project root.
     *
     * @param list<ComposerUpdatePlanEntry> $bumps
     */
    private function rehearse(
        string $projectRoot,
        ComposerUpdatePlan $plan,
        array $bumps,
        ?string $installedBefore,
        ?string $driftReason,
        bool $force,
        string $degradedTail,
    ): ComposerUpdateResult {
        $bumpedSummary = [];
        foreach ($bumps as $b) {
            $bumpedSummary[$b->name] = ['from' => $b->declared, 'to' => (string) $b->targetVersion];
        }

        $scratchName = sprintf(self::REHEARSAL_FILE, bin2hex(random_bytes(6)));
        $scratchJson = $projectRoot . '/' . $scratchName;
        $scratchLock = substr($scratchJson, 0, -strlen('.json')) . '.lock';
        try {
            if (!@copy($projectRoot . '/composer.json', $scratchJson)) {
                throw new \RuntimeException('could not write ' . $scratchName);
            }
            if (is_file($projectRoot . '/composer.lock') && !@copy($projectRoot . '/composer.lock', $scratchLock)) {
                throw new \RuntimeException('could not write the scratch lock');
            }
            if ($bumps !== []) {
                $this->rewriteComposerJsonPins($scratchJson, $bumps);
            }
            $exec = $this->executor->run(
                [...['update', self::PREFIX . '*', '-W', '--no-interaction', '--dry-run', '--no-scripts'], ...$this->devMode($projectRoot)],
                $projectRoot,
                ['COMPOSER' => $scratchName],
            );
        } catch (\Throwable $e) {
            $exec = ['exitCode' => 1, 'output' => 'Could not rehearse the plan: ' . $e->getMessage()];
        } finally {
            @unlink($scratchJson);
            @unlink($scratchLock);
        }

        if ($exec['exitCode'] !== 0) {
            return new ComposerUpdateResult(
                outcome: ComposerUpdateOutcome::Failed,
                bumpedPackages: $bumpedSummary,
                installedBefore: $installedBefore,
                installedAfter: $installedBefore,
                composerExitCode: $exec['exitCode'],
                composerOutput: $this->tail($exec['output'], 4096),
                message: 'The planned package set does not resolve — a real run would stop at composer. '
                    . 'Nothing was changed. Composer says: ' . $this->reasonFrom($exec['output']),
            );
        }

        $reasonTail = $driftReason !== null
            ? ' Reason: ' . $driftReason . '.'
            : ($force ? ' Reason: --composer-only forces a composer run.' : '');

        return new ComposerUpdateResult(
            outcome: ComposerUpdateOutcome::WouldRun,
            bumpedPackages: $bumpedSummary,
            installedBefore: $installedBefore,
            installedAfter: $installedBefore,
            composerExitCode: 0,
            composerOutput: $this->tail($exec['output'], 4096),
            message: ($bumps === []
                ? 'No release-pinned semitexa/* package needs a bump.'
                : sprintf('Would bump %d pin(s) and run: %s.', count($bumps), $plan->composerCommand))
                . ' Composer confirmed the set resolves.'
                . $reasonTail
                . $degradedTail,
        );
    }

    /**
     * Composer failed: put composer.json and composer.lock back as they were.
     *
     * A resolver failure (composer exit code 2) never reaches vendor/. Any
     * other failure may have: a download that dies halfway leaves some
     * packages replaced, and installed.json — written only once the install
     * finishes — cannot be trusted to say so. So unless composer failed while
     * resolving, vendor/ is reinstalled from the restored lock. The result
     * names what could not be put back rather than claiming a clean state it
     * does not have.
     *
     * @param array{exitCode: int, output: string} $exec
     * @param array<string, string> $versionsBefore
     */
    private function rollBack(
        string $projectRoot,
        ComposerStateSnapshot $snapshot,
        array $exec,
        array $versionsBefore,
        ?string $installedBefore,
    ): ComposerUpdateResult {
        $restored = $snapshot->restoreFiles();
        $moved = $this->moves($versionsBefore, $this->state->semitexaVersions($projectRoot));

        $vendorNote = '';
        if (!$snapshot->hasLock()) {
            // Nothing to reinstall FROM: `composer install` without a lock
            // resolves a fresh set and writes one — a different project than
            // the one this run started with.
            if ($exec['exitCode'] !== self::COMPOSER_RESOLUTION_FAILED || $moved !== []) {
                $vendorNote = ' There was no composer.lock before this run, so vendor/ cannot be put back; review it before rerunning.';
            }
        } elseif ($restored && ($exec['exitCode'] !== self::COMPOSER_RESOLUTION_FAILED || $moved !== [])) {
            $reinstall = $this->executor->run([...['install', '--no-interaction'], ...$this->devMode($projectRoot)], $projectRoot);
            $moved = $this->moves($versionsBefore, $this->state->semitexaVersions($projectRoot));
            $vendorNote = $reinstall['exitCode'] === 0
                ? ' vendor/ was reinstalled from the restored lock.'
                : sprintf(' vendor/ could not be reinstalled — `composer install` exited %d; run it before anything else.', $reinstall['exitCode']);
        }

        $state = $restored
            ? ' composer.json and composer.lock were restored.' . $vendorNote
            : ' composer.json and composer.lock could NOT be restored — check both before rerunning.';

        return new ComposerUpdateResult(
            outcome: ComposerUpdateOutcome::Failed,
            bumpedPackages: $moved,
            installedBefore: $installedBefore,
            installedAfter: $this->state->installedVersion($projectRoot, self::UPDATER_PACKAGE),
            composerExitCode: $exec['exitCode'],
            composerOutput: $this->tail($exec['output'], 4096),
            message: 'composer update exited with code ' . $exec['exitCode'] . '.' . $state
                . ' Composer says: ' . $this->reasonFrom($exec['output']),
        );
    }

    /**
     * @param list<ComposerUpdatePlanEntry> $bumps
     */
    private function rewriteComposerJsonPins(string $path, array $bumps): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException("composer.json not found at {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read {$path}");
        }
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("composer.json is not a JSON object");
        }
        foreach (['require', 'require-dev'] as $bucket) {
            if (!isset($data[$bucket]) || !is_array($data[$bucket])) {
                continue;
            }
            foreach ($bumps as $bump) {
                if (array_key_exists($bump->name, $data[$bucket]) && $bump->targetVersion !== null) {
                    $data[$bucket][$bump->name] = $bump->targetVersion;
                }
            }
        }
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            throw new \RuntimeException('json_encode failed');
        }
        if (file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException("Unable to write {$path}");
        }
    }

    /**
     * A short, readable account of what moved — three packages named, the rest counted.
     *
     * @param array<string, array{from: ?string, to: ?string}> $moves
     */
    private function describeMoves(array $moves): string
    {
        $parts = [];
        foreach (array_slice($moves, 0, 3, true) as $name => $move) {
            $parts[] = sprintf('%s %s → %s', $name, $move['from'] ?? 'absent', $move['to'] ?? 'removed');
        }
        $rest = count($moves) - count($parts);

        return implode(', ', $parts) . ($rest > 0 ? sprintf(' and %d more', $rest) : '');
    }

    /**
     * Is a wildcard-declared package installed behind what upstream offers?
     *
     * The lock/vendor drift check deliberately walks past wildcard constraints:
     * a "*" cannot disagree with a lock, so by that measure a wildcard project
     * is always coherent and the composer phase is always skipped — the
     * packages sit wherever they were until someone runs composer by hand.
     *
     * This used to be answered by "does the installed set span several release
     * dates?". A release set does, by design — a cut tags only the packages that
     * changed — so every correctly updated project looked stale and composer
     * ran on every update. The question that matters is whether there is
     * anything newer, and the plan already knows.
     */
    private function wildcardBehindReason(ComposerUpdatePlan $plan): ?string
    {
        foreach ($plan->entries as $entry) {
            if ($entry->pinKind !== ComposerUpdatePlanEntry::PIN_WILDCARD
                || $entry->installed === null
                || $entry->upstreamVersion === null
                || !SemitexaReleaseVersion::isValid($entry->installed)
            ) {
                continue;
            }
            if (SemitexaReleaseVersion::compare($entry->upstreamVersion, $entry->installed) > 0) {
                return sprintf(
                    '%s is installed at %s and upstream offers %s',
                    $entry->name,
                    $entry->installed,
                    $entry->upstreamVersion,
                );
            }
        }

        return null;
    }

    /**
     * Returns a short human reason iff there is meaningful drift between
     * composer.json (declared), composer.lock (locked), and
     * vendor/composer/installed.json (installed) for any semitexa/*
     * package — i.e. `composer install` or `composer update` would actually
     * change something. Returns null when the local Composer state is
     * coherent and no composer invocation is needed.
     *
     * Coverage:
     *   - declared exact pin != locked version           → "lock_stale"
     *   - locked version    != installed version          → "vendor_stale"
     *   - declared but not present in lock                → "missing_from_lock"
     *   - locked but not present in vendor                → "missing_from_vendor"
     *
     * Path-repo packages never contribute. Dev and wildcard constraints
     * contribute only through presence (missing from the lock or vendor):
     * their versions cannot disagree with a lock.
     */
    private function lockOrVendorDriftReason(string $projectRoot): ?string
    {
        $declared = $this->state->readDeclared($projectRoot);
        [$locked, $lockPathRepos] = $this->state->readLocked($projectRoot);
        [$installed, $installedPathRepos] = $this->state->readInstalled($projectRoot);
        $pathRepos = $lockPathRepos + $installedPathRepos;

        $withoutDev = $this->state->installedWithoutDev($projectRoot);
        $lockedDev = $withoutDev ? $this->state->readLockedDevNames($projectRoot) : [];

        $names = $this->state->collectSemitexaNames($declared, $locked, $installed);
        foreach ($names as $name) {
            if (isset($pathRepos[$name])) {
                continue;
            }
            $d = $declared[$name] ?? null;
            $l = $locked[$name] ?? null;
            $i = $installed[$name] ?? null;
            // Presence is checked for every constraint kind: a package just
            // added as "*" is in neither the lock nor vendor, and skipping
            // wildcards here reported that project clean without installing it.
            if ($d !== null && $l === null) {
                return sprintf('%s declared but missing from composer.lock', $name);
            }
            if ($l !== null && $i === null && !($withoutDev && isset($lockedDev[$name]))) {
                // A dev package absent from a --no-dev vendor is the install
                // mode working, not drift; counting it ran composer — without
                // --no-dev — on every production update.
                return sprintf('%s locked but missing from vendor', $name);
            }
            // Versions are compared only for exact pins: "*" or @dev cannot
            // disagree with a lock.
            if ($this->state->isDevConstraint($d) || $this->state->isWildcardConstraint($d)) {
                continue;
            }
            if ($d !== null && $l !== null && $d !== $l) {
                return sprintf('%s composer.json pin (%s) differs from composer.lock (%s)', $name, $d, $l);
            }
            if ($l !== null && $i !== null && $l !== $i) {
                return sprintf('%s composer.lock (%s) differs from vendor (%s)', $name, $l, $i);
            }
        }
        return null;
    }

    /**
     * `--no-dev` when vendor/ was installed without dev packages: a composer
     * call without it would install them into a production tree.
     *
     * @return list<string>
     */
    private function devMode(string $projectRoot): array
    {
        return $this->state->installedWithoutDev($projectRoot) ? ['--no-dev'] : [];
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return array<string, array{from: ?string, to: ?string}>
     */
    private function moves(array $before, array $after): array
    {
        $moves = [];
        foreach ($after as $name => $version) {
            if (($before[$name] ?? null) !== $version) {
                $moves[$name] = ['from' => $before[$name] ?? null, 'to' => $version];
            }
        }
        foreach ($before as $name => $version) {
            if (!isset($after[$name])) {
                $moves[$name] = ['from' => $version, 'to' => null];
            }
        }
        ksort($moves);

        return $moves;
    }

    /**
     * The lines of composer's output that say why — its "Problem N" block when
     * there is one — so the operator's summary carries the cause, not only an
     * exit code.
     */
    private function reasonFrom(string $output): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $output) ?: []),
            static fn (string $l): bool => $l !== '',
        ));
        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^Problem \d+/', $line) === 1) {
                $start = $i;
                break;
            }
        }
        $picked = $start !== null ? array_slice($lines, $start, 4) : array_slice($lines, -3);

        return $picked === [] ? '(no output)' : implode(' ', $picked);
    }

    private function tail(string $output, int $maxBytes): string
    {
        if (strlen($output) <= $maxBytes) {
            return $output;
        }
        return '… [truncated] …' . "\n" . substr($output, -$maxBytes);
    }
}
