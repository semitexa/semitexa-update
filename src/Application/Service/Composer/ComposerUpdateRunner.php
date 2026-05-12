<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

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
 *   1. Read composer.json (declared), composer.lock (locked),
 *      vendor/composer/installed.json (installed) — same data sources as
 *      PackageDriftInspector, but now used to ACT instead of REPORT.
 *
 *   2. For each `semitexa/*` package, classify the pin: exact / dev /
 *      path_repo / wildcard. The runner only rewrites exact pins.
 *
 *   3. Use the upstream resolver to pick the latest stable
 *      `semitexa/update` tag — this is the anchor for the release set.
 *
 *   4. For every release-pinned semitexa/* package, target the anchor
 *      version when the upstream resolver confirms that version exists for
 *      that package; otherwise target its own latest stable.
 *
 *   5. Rewrite composer.json pin strings in-place (JSON re-pretty-printed;
 *      no other keys touched).
 *
 *   6. Shell out to `composer update "semitexa/*" -W` inside the container.
 *
 *   7. Compare installed.json semitexa/update version before vs after. If
 *      it changed, return UpdaterChanged so the orchestrator stops with a
 *      rerun message.
 *
 * Path-repo, @dev, dev-* and "*" pins are reported in the plan but never
 * mutated.
 */
final class ComposerUpdateRunner
{
    private const PREFIX = 'semitexa/';
    private const ANCHOR_PACKAGE = 'semitexa/update';

    public function __construct(
        private readonly ComposerExecutorInterface $executor,
        private readonly UpstreamVersionResolverInterface $resolver,
    ) {
    }

    public function plan(string $projectRoot): ComposerUpdatePlan
    {
        $declared = $this->readDeclared($projectRoot);
        [$locked, $lockPathRepos] = $this->readLocked($projectRoot);
        [$installed, $installedPathRepos] = $this->readInstalled($projectRoot);

        $names = $this->collectSemitexaNames($declared, $locked, $installed);
        $pathRepos = $lockPathRepos + $installedPathRepos;

        // Anchor: latest stable tag of semitexa/update.
        $anchor = $this->resolver->latestStable(self::ANCHOR_PACKAGE);

        $entries = [];
        foreach ($names as $name) {
            $entries[] = $this->planEntry(
                $name,
                $declared[$name] ?? null,
                $locked[$name] ?? null,
                $installed[$name] ?? null,
                isset($pathRepos[$name]),
                $anchor,
            );
        }

        return new ComposerUpdatePlan(
            entries: $entries,
            targetReleaseSet: $anchor,
            composerCommand: 'composer update "' . self::PREFIX . '*" -W --no-interaction',
            inContainer: $this->executor->isAvailable(),
            containerError: $this->executor->containerError(),
        );
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
                installedBefore: $this->installedVersion($projectRoot, self::ANCHOR_PACKAGE),
                installedAfter: $this->installedVersion($projectRoot, self::ANCHOR_PACKAGE),
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
                installedBefore: $this->installedVersion($projectRoot, self::ANCHOR_PACKAGE),
                installedAfter: $this->installedVersion($projectRoot, self::ANCHOR_PACKAGE),
                composerExitCode: 1,
                composerOutput: $plan->containerError,
                message: 'Composer phase refused to run: ' . $plan->containerError,
            );
        }

        // Unresolved upstream is a blocking failure by default. The operator
        // must explicitly opt in with --allow-partial-composer-update to
        // proceed with whatever bumps we could resolve.
        $unresolved = $plan->unresolvedEntries();
        if ($unresolved !== [] && !$allowPartial) {
            $names = array_map(static fn ($e) => $e->name, $unresolved);
            $installedBefore = $this->installedVersion($projectRoot, self::ANCHOR_PACKAGE);
            return new ComposerUpdateResult(
                outcome: ComposerUpdateOutcome::Failed,
                bumpedPackages: [],
                installedBefore: $installedBefore,
                installedAfter: $installedBefore,
                composerExitCode: 1,
                composerOutput: '',
                message: sprintf(
                    'Composer-update phase blocked: upstream metadata could not be resolved for %d exact-pinned semitexa/* package(s): %s. '
                    . 'Retry with network access, or rerun with --allow-partial-composer-update to proceed with only the packages that could be resolved.',
                    count($unresolved),
                    implode(', ', $names),
                ),
            );
        }

        $bumps = $plan->entriesToBump();
        $installedBefore = $this->installedVersion($projectRoot, self::ANCHOR_PACKAGE);

        // No bumps, no lock/vendor drift, no explicit force → skip the
        // composer call entirely. Invoking `composer update` in this state
        // is a no-op for versions but still rewrites composer.lock's
        // content-hash field, producing noisy git diffs on every plain
        // `bin/semitexa update`. The `$force` flag (wired to --composer-only)
        // is the explicit operator override.
        $driftReason = $this->lockOrVendorDriftReason($projectRoot);
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

        if ($dryRun) {
            $bumpedSummary = [];
            foreach ($bumps as $b) {
                $bumpedSummary[$b->name] = ['from' => $b->declared, 'to' => (string) $b->targetVersion];
            }
            $degradedTail = ($unresolved !== [] && $allowPartial)
                ? sprintf(' Composer phase will run DEGRADED — %d package(s) unresolved upstream: %s.',
                    count($unresolved),
                    implode(', ', array_map(static fn ($e) => $e->name, $unresolved)),
                )
                : '';
            $reasonTail = $driftReason !== null
                ? ' Reason: ' . $driftReason . '.'
                : ($force ? ' Reason: --composer-only forces a composer run.' : '');
            return new ComposerUpdateResult(
                outcome: ComposerUpdateOutcome::WouldRun,
                bumpedPackages: $bumpedSummary,
                installedBefore: $installedBefore,
                installedAfter: $installedBefore,
                composerExitCode: 0,
                composerOutput: '',
                message: ($bumps === []
                    ? 'No release-pinned semitexa/* package needs a bump.'
                    : sprintf('Would bump %d pin(s) and run: %s', count($bumps), $plan->composerCommand))
                    . $reasonTail
                    . $degradedTail,
            );
        }

        // 1. Rewrite pins
        if ($bumps !== []) {
            try {
                $this->rewriteComposerJsonPins($projectRoot, $bumps);
            } catch (\Throwable $e) {
                return new ComposerUpdateResult(
                    outcome: ComposerUpdateOutcome::Failed,
                    bumpedPackages: [],
                    installedBefore: $installedBefore,
                    installedAfter: $installedBefore,
                    composerExitCode: 1,
                    composerOutput: $e->getMessage(),
                    message: 'Refusing to run composer — pin rewrite failed: ' . $e->getMessage(),
                );
            }
        }

        // 2. Run composer
        $exec = $this->executor->run(
            ['update', self::PREFIX . '*', '-W', '--no-interaction'],
            $projectRoot,
        );

        $bumpedSummary = [];
        foreach ($bumps as $b) {
            $bumpedSummary[$b->name] = ['from' => $b->declared, 'to' => (string) $b->targetVersion];
        }

        if ($exec['exitCode'] !== 0) {
            return new ComposerUpdateResult(
                outcome: ComposerUpdateOutcome::Failed,
                bumpedPackages: $bumpedSummary,
                installedBefore: $installedBefore,
                installedAfter: $this->installedVersion($projectRoot, self::ANCHOR_PACKAGE),
                composerExitCode: $exec['exitCode'],
                composerOutput: $this->tail($exec['output'], 4096),
                message: 'composer update exited with code ' . $exec['exitCode'] . '.',
            );
        }

        $installedAfter = $this->installedVersion($projectRoot, self::ANCHOR_PACKAGE);
        $updaterChanged = $installedBefore !== $installedAfter
            && $installedBefore !== null
            && $installedAfter !== null;

        $outcome = $updaterChanged
            ? ComposerUpdateOutcome::UpdaterChanged
            : ($bumpedSummary !== [] ? ComposerUpdateOutcome::Updated : ComposerUpdateOutcome::Clean);

        $degradedTail = ($unresolved !== [] && $allowPartial)
            ? sprintf(
                ' DEGRADED: %d package(s) had no upstream metadata and were left at their current pin: %s.',
                count($unresolved),
                implode(', ', array_map(static fn ($e) => $e->name, $unresolved)),
            )
            : '';

        $message = match ($outcome) {
            ComposerUpdateOutcome::UpdaterChanged => sprintf(
                'semitexa/update was upgraded (%s → %s). Stopping cleanly so the next stages run with fresh code. '
                . 'Rerun `bin/semitexa update` to continue.',
                $installedBefore,
                $installedAfter,
            ),
            ComposerUpdateOutcome::Updated => sprintf(
                'Bumped %d pin(s); composer update succeeded.%s',
                count($bumpedSummary),
                $degradedTail,
            ),
            default => 'No release-pinned semitexa/* package needed a bump.' . $degradedTail,
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

    private function planEntry(
        string $name,
        ?string $declared,
        ?string $locked,
        ?string $installed,
        bool $isPathRepo,
        ?string $anchor,
    ): ComposerUpdatePlanEntry {
        if ($isPathRepo) {
            return new ComposerUpdatePlanEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                targetVersion: null,
                pinKind: ComposerUpdatePlanEntry::PIN_PATH_REPO,
                skipReason: 'Path repository — composer update will not relocate to Packagist.',
            );
        }
        if ($this->isDevConstraint($declared)) {
            return new ComposerUpdatePlanEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                targetVersion: null,
                pinKind: ComposerUpdatePlanEntry::PIN_DEV,
                skipReason: 'Dev constraint — operator opted out of release pinning.',
            );
        }
        if ($this->isWildcardConstraint($declared)) {
            return new ComposerUpdatePlanEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                targetVersion: null,
                pinKind: ComposerUpdatePlanEntry::PIN_WILDCARD,
                skipReason: 'Wildcard constraint — composer update will resolve within it.',
            );
        }

        // Release-pinned. Pick the target.
        $target = null;
        if ($anchor !== null && $this->resolver->hasVersion($name, $anchor)) {
            $target = $anchor;
        } else {
            $latestForPackage = $this->resolver->latestStable($name);
            if ($latestForPackage !== null) {
                $target = $latestForPackage;
            }
        }

        // Unresolved: pinKind=Exact, target=null, skipReason="". This
        // signature is the blocking signal `unresolvedEntries()` looks for.
        // We deliberately don't fill skipReason here — that's for entries
        // we intentionally leave alone (path-repo / dev / wildcard).

        return new ComposerUpdatePlanEntry(
            name: $name,
            declared: $declared,
            locked: $locked,
            installed: $installed,
            targetVersion: $target,
            pinKind: ComposerUpdatePlanEntry::PIN_EXACT,
            skipReason: '',
        );
    }

    /**
     * @param list<ComposerUpdatePlanEntry> $bumps
     */
    private function rewriteComposerJsonPins(string $projectRoot, array $bumps): void
    {
        $path = $projectRoot . '/composer.json';
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

    private function isDevConstraint(?string $declared): bool
    {
        if ($declared === null) {
            return false;
        }
        $d = strtolower(trim($declared));
        return $d === '@dev' || str_starts_with($d, 'dev-');
    }

    private function isWildcardConstraint(?string $declared): bool
    {
        if ($declared === null) {
            return false;
        }
        $d = trim($declared);
        return $d === '' || $d === '*' || str_starts_with($d, '^') || str_starts_with($d, '~')
            || str_contains($d, '||') || str_contains($d, ',') || str_contains($d, ' ')
            || str_contains($d, '>') || str_contains($d, '<');
    }

    /**
     * @return array<string, string>
     */
    private function readDeclared(string $projectRoot): array
    {
        $data = $this->readJson($projectRoot . '/composer.json');
        if ($data === null) {
            return [];
        }
        $declared = [];
        foreach (['require', 'require-dev'] as $bucket) {
            $entries = $data[$bucket] ?? [];
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $name => $constraint) {
                if (is_string($name) && is_string($constraint)) {
                    $declared[$name] = $constraint;
                }
            }
        }
        return $declared;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    private function readLocked(string $projectRoot): array
    {
        $data = $this->readJson($projectRoot . '/composer.lock');
        if ($data === null) {
            return [[], []];
        }
        return $this->scanInstalledShape($data, ['packages', 'packages-dev']);
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    private function readInstalled(string $projectRoot): array
    {
        $data = $this->readJson($projectRoot . '/vendor/composer/installed.json');
        if ($data === null) {
            return [[], []];
        }
        if (isset($data['packages']) && is_array($data['packages'])) {
            return $this->scanInstalledShape($data, ['packages']);
        }
        return $this->scanInstalledShape(['packages' => $data], ['packages']);
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $buckets
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    private function scanInstalledShape(array $data, array $buckets): array
    {
        $versions = [];
        $pathRepos = [];
        foreach ($buckets as $bucket) {
            $entries = $data[$bucket] ?? [];
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $package) {
                if (!is_array($package)) {
                    continue;
                }
                $name = (string) ($package['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if ($this->isPathRepoEntry($package)) {
                    $pathRepos[$name] = true;
                }
                $version = ltrim((string) ($package['version'] ?? ''), 'v');
                if ($version !== '') {
                    $versions[$name] = $version;
                }
            }
        }
        return [$versions, $pathRepos];
    }

    /**
     * @param array<string, mixed> $package
     */
    private function isPathRepoEntry(array $package): bool
    {
        foreach (['dist', 'source'] as $key) {
            $section = $package[$key] ?? null;
            if (is_array($section) && ($section['type'] ?? null) === 'path') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, string> $declared
     * @param array<string, string> $locked
     * @param array<string, string> $installed
     * @return list<string>
     */
    private function collectSemitexaNames(array $declared, array $locked, array $installed): array
    {
        $names = [];
        foreach (array_merge(array_keys($declared), array_keys($locked), array_keys($installed)) as $name) {
            if (str_starts_with($name, self::PREFIX)) {
                $names[$name] = true;
            }
        }
        $list = array_keys($names);
        sort($list);
        return $list;
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function installedVersion(string $projectRoot, string $package): ?string
    {
        $data = $this->readJson($projectRoot . '/vendor/composer/installed.json');
        if ($data === null) {
            return null;
        }
        $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;
        if (!is_array($packages)) {
            return null;
        }
        foreach ($packages as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['name'] ?? null) === $package) {
                return ltrim((string) ($entry['version'] ?? ''), 'v') ?: null;
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
     * Path-repo / dev / wildcard packages never contribute to drift here:
     * if they did, ``--no-composer`` would be the only sensible operator
     * response, and that's the operator's call to make.
     */
    private function lockOrVendorDriftReason(string $projectRoot): ?string
    {
        $declared = $this->readDeclared($projectRoot);
        [$locked, $lockPathRepos] = $this->readLocked($projectRoot);
        [$installed, $installedPathRepos] = $this->readInstalled($projectRoot);
        $pathRepos = $lockPathRepos + $installedPathRepos;

        $names = $this->collectSemitexaNames($declared, $locked, $installed);
        foreach ($names as $name) {
            if (isset($pathRepos[$name])) {
                continue;
            }
            $d = $declared[$name] ?? null;
            $l = $locked[$name] ?? null;
            $i = $installed[$name] ?? null;
            if ($this->isDevConstraint($d) || $this->isWildcardConstraint($d)) {
                continue;
            }
            if ($d !== null && $l === null) {
                return sprintf('%s declared but missing from composer.lock', $name);
            }
            if ($l !== null && $i === null) {
                return sprintf('%s locked but missing from vendor', $name);
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

    private function tail(string $output, int $maxBytes): string
    {
        if (strlen($output) <= $maxBytes) {
            return $output;
        }
        return '… [truncated] …' . "\n" . substr($output, -$maxBytes);
    }
}
