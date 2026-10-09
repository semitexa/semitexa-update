<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

use Semitexa\Core\Support\FrameworkVersion;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\SemitexaReleaseVersion;

/**
 * Records which Semitexa release vendor/ holds, for {@see FrameworkVersion}.
 *
 * A release is a `semitexa/ultimate` version: its `require` pins every
 * package of the cut. The installed release is the newest one whose pins
 * vendor/ meets — every pinned package that is installed sits at its pin or
 * later, and core is among them. A pinned package the project does not
 * install (crud on a site that has no admin) does not count against it.
 *
 * Read from vendor/, never from composer.lock: on 2026-09-22 a dead token
 * left vendor/ eight days behind a lock that said every deploy succeeded.
 *
 * Runs after every `update` and `update:packages:auto`, whatever they did,
 * so a rolled-back deploy rewrites the record too. When vendor/ holds no whole
 * release the record is dropped; when Packagist cannot be asked it is left as
 * it was, because nothing is known.
 */
final class InstalledReleaseSetRecorder
{
    private const RELEASE_SET_PACKAGE = 'semitexa/ultimate';
    private const CORE = 'semitexa/core';
    private const PREFIX = 'semitexa/';

    public function __construct(
        private readonly UpstreamVersionResolverInterface $resolver = new PackagistVersionResolver(),
        private readonly ComposerProjectState $state = new ComposerProjectState(),
    ) {
    }

    /**
     * @return string|false|null the release recorded; null when vendor/ holds
     *                           none and the record was dropped; false when
     *                           Packagist could not be asked
     */
    public function record(string $projectRoot): string|false|null
    {
        $release = $this->installedRelease($projectRoot);
        if ($release === false) {
            return false;
        }

        if ($release === null) {
            FrameworkVersion::forget($projectRoot);
            return null;
        }

        FrameworkVersion::record($release, $projectRoot);
        return $release;
    }

    /**
     * @return string|false|null false when Packagist could not be asked
     */
    public function installedRelease(string $projectRoot): string|false|null
    {
        // Path repos are excluded: a working tree is not a release.
        $installed = $this->state->semitexaVersions($projectRoot);
        if (!SemitexaReleaseVersion::isStable($installed[self::CORE] ?? '')) {
            return null;
        }

        $versions = $this->resolver->stableVersions(self::RELEASE_SET_PACKAGE);
        if ($versions === null) {
            return false;
        }
        usort($versions, static fn (string $a, string $b): int => SemitexaReleaseVersion::compare($b, $a));

        foreach ($versions as $version) {
            $require = $this->resolver->requiresOf(self::RELEASE_SET_PACKAGE, $version);
            if ($require !== null && $this->isMet($require, $installed)) {
                return $version;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $require
     * @param array<string, string> $installed
     */
    private function isMet(array $require, array $installed): bool
    {
        if (!isset($require[self::CORE])) {
            return false;
        }

        foreach ($require as $name => $pin) {
            if (!str_starts_with($name, self::PREFIX) || !SemitexaReleaseVersion::isStable($pin)) {
                continue;
            }
            if (!isset($installed[$name])) {
                continue;
            }
            $have = $installed[$name];
            if (!SemitexaReleaseVersion::isStable($have) || SemitexaReleaseVersion::compare($have, $pin) < 0) {
                return false;
            }
        }

        return true;
    }
}
