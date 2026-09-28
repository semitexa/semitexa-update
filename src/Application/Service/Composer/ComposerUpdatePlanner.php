<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

use Semitexa\Update\Application\Service\Packaging\Releases\Support\SemitexaReleaseVersion;
use Semitexa\Update\Domain\Model\Composer\ComposerUpdatePlan;
use Semitexa\Update\Domain\Model\Composer\ComposerUpdatePlanEntry;

/**
 * Decides what each semitexa/* pin should move to. Read-only.
 *
 * The release set is the `require` map of the latest stable
 * `semitexa/ultimate`: every release cut republishes ultimate with the exact
 * version of every package, tested together — it is the one package that
 * always moves. (This used to be the latest tag of `semitexa/update`, which a
 * cut only re-tags when update itself changed: whenever it did not, half the
 * set was aimed at a stale version the other half could not accept, and the
 * plan could not resolve.)
 *
 * Each exact pin targets its version in the set; outside the set, its own
 * latest release; never lower than the declared pin. A package the registry
 * answers for with nothing is left alone — composer finds it in the project's
 * own repositories. Only a registry that could not be asked blocks.
 */
final class ComposerUpdatePlanner
{
    public const COMPOSER_COMMAND = 'composer update "semitexa/*" -W --no-interaction';

    private const PREFIX = 'semitexa/';
    private const RELEASE_SET_PACKAGE = 'semitexa/ultimate';
    private const RELEASE_VERSION = '/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/';

    public function __construct(
        private readonly UpstreamVersionResolverInterface $resolver,
        private readonly ComposerProjectState $state = new ComposerProjectState(),
    ) {
    }

    public function plan(string $projectRoot, bool $inContainer, string $containerError): ComposerUpdatePlan
    {
        $declared = $this->state->readDeclared($projectRoot);
        [$locked, $lockPathRepos] = $this->state->readLocked($projectRoot);
        [$installed, $installedPathRepos] = $this->state->readInstalled($projectRoot);

        $names = $this->state->collectSemitexaNames($declared, $locked, $installed);
        $pathRepos = $lockPathRepos + $installedPathRepos;

        [$releaseSetVersion, $releaseSet] = $this->releaseSet();

        $entries = [];
        foreach ($names as $name) {
            $entries[] = $this->planEntry(
                $name,
                $declared[$name] ?? null,
                $locked[$name] ?? null,
                $installed[$name] ?? null,
                isset($pathRepos[$name]),
                $releaseSet,
            );
        }

        return new ComposerUpdatePlan(
            entries: $entries,
            releaseSetVersion: $releaseSetVersion,
            composerCommand: self::COMPOSER_COMMAND,
            inContainer: $inContainer,
            containerError: $containerError,
            releaseSetUnreachable: $releaseSet === false,
        );
    }

    /**
     * The latest stable ultimate release and the semitexa/* versions it pins.
     *
     * The set is `false` when the registry could not be asked for ultimate.
     * That must block like any unreachable registry: falling back to each
     * package's own latest would pick exactly the tags the set exists to keep
     * out, and "never lower" would then hold them there in every later run.
     * Null — ultimate not published, or no stable release — is an answer, and
     * each pin falls back to its own latest.
     *
     * @return array{0: ?string, 1: array<string, string>|false|null}
     */
    private function releaseSet(): array
    {
        $versions = $this->resolver->stableVersions(self::RELEASE_SET_PACKAGE);
        if ($versions === null) {
            return [null, false];
        }
        $latest = SemitexaReleaseVersion::latestStable($versions);
        if ($latest === null) {
            return [null, null];
        }
        $require = $this->resolver->requiresOf(self::RELEASE_SET_PACKAGE, $latest);
        if ($require === null) {
            return [null, false];
        }

        $set = [];
        foreach ($require as $name => $constraint) {
            if (str_starts_with($name, self::PREFIX) && preg_match(self::RELEASE_VERSION, $constraint) === 1) {
                $set[$name] = $constraint;
            }
        }

        return [$latest, $set];
    }

    /**
     * What upstream offers for a package: its version in the release set, else
     * its own latest release. `false` when the registry could not be asked,
     * null when it answered that there is nothing.
     *
     * @param array<string, string>|false|null $releaseSet
     */
    private function upstreamVersion(string $name, array|false|null $releaseSet): string|false|null
    {
        if ($releaseSet === false) {
            return false;
        }
        if (isset($releaseSet[$name])) {
            return $releaseSet[$name];
        }
        $versions = $this->resolver->stableVersions($name);
        if ($versions === null) {
            return false;
        }

        return SemitexaReleaseVersion::latestStable($versions);
    }

    /**
     * @param array<string, string>|false|null $releaseSet
     */
    private function planEntry(
        string $name,
        ?string $declared,
        ?string $locked,
        ?string $installed,
        bool $isPathRepo,
        array|false|null $releaseSet,
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
        if ($this->state->isDevConstraint($declared)) {
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
        if ($this->state->isWildcardConstraint($declared)) {
            $upstream = $this->upstreamVersion($name, $releaseSet);
            return new ComposerUpdatePlanEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                targetVersion: null,
                pinKind: ComposerUpdatePlanEntry::PIN_WILDCARD,
                skipReason: 'Wildcard constraint — composer update will resolve within it.',
                upstreamVersion: is_string($upstream) ? $upstream : null,
            );
        }
        if ($declared === null) {
            // In the lock or vendor but not in composer.json: something else
            // requires it. There is no pin here to rewrite and nothing for the
            // operator to decide, so it cannot be "unresolvable" — it simply is
            // not ours. Without this it fell through to the exact-pin branch
            // below (both constraint helpers answer false for null) and a
            // transitive dependency could block the whole update.
            return new ComposerUpdatePlanEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                targetVersion: null,
                pinKind: ComposerUpdatePlanEntry::PIN_TRANSITIVE,
                skipReason: 'Not required by this project — composer resolves it for whoever does.',
                upstreamVersion: is_array($releaseSet) ? ($releaseSet[$name] ?? null) : null,
            );
        }

        // Release-pinned. Pick the target.
        $upstream = $this->upstreamVersion($name, $releaseSet);

        if ($upstream === false) {
            // Unresolved: pinKind=Exact, target=null, skipReason="". This
            // signature is the blocking signal `unresolvedEntries()` looks for.
            // Only a registry that could not be ASKED gets here.
            return new ComposerUpdatePlanEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                targetVersion: null,
                pinKind: ComposerUpdatePlanEntry::PIN_EXACT,
                skipReason: '',
            );
        }

        if ($upstream === null) {
            // The registry answered: nothing published there. A private VCS
            // package or one served from the project's own repositories — not
            // ours to judge. This used to block the whole update on production,
            // where three exact-pinned content packages live in private repos.
            return new ComposerUpdatePlanEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                targetVersion: null,
                pinKind: ComposerUpdatePlanEntry::PIN_EXACT,
                skipReason: 'Not published on Packagist — left at its pin; composer resolves it from the project\'s repositories.',
            );
        }

        // Never lower. A pin already at or past what upstream offers stays: an
        // operator who moved core ahead by hand must not have the update
        // quietly move it back — which is exactly what it used to do.
        $target = SemitexaReleaseVersion::isValid($declared)
            && SemitexaReleaseVersion::compare($upstream, $declared) <= 0
            ? $declared
            : $upstream;

        return new ComposerUpdatePlanEntry(
            name: $name,
            declared: $declared,
            locked: $locked,
            installed: $installed,
            targetVersion: $target,
            pinKind: ComposerUpdatePlanEntry::PIN_EXACT,
            skipReason: '',
            upstreamVersion: $upstream,
        );
    }
}
