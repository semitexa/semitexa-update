<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

/**
 * What the upstream registry publishes, read-only.
 *
 * Two answers that look alike must stay apart, because the update treats them
 * oppositely:
 *
 *  - `null`  — the registry could not be asked (DNS, timeout, 5xx). Nothing is
 *              known, and an update that acts on nothing known is a guess.
 *  - `[]`    — the registry answered, and there is nothing: the package is not
 *              published there (a private VCS package, a path package). That is
 *              not a failure; composer resolves it from the project's own
 *              repositories.
 */
interface UpstreamVersionResolverInterface
{
    /**
     * Stable date-based release versions (`YYYY.MM.DD.HHMM`) of a package.
     *
     * @return list<string>|null null when the registry could not be asked
     */
    public function stableVersions(string $package): ?array;

    /**
     * The `require` map one published version declares.
     *
     * @return array<string, string>|null null when unknown or unreachable
     */
    public function requiresOf(string $package, string $version): ?array;
}
