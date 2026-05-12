<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

/**
 * Resolves the latest released version for one or more composer packages.
 *
 * Production implementation hits Packagist's `/p2/<pkg>.json` endpoint.
 * Tests inject a fake.
 *
 * The runner uses this for two purposes:
 *  1. Pick the "anchor" version — the latest `semitexa/update` tag — to
 *     align all release-pinned semitexa/* packages to.
 *  2. Confirm a specific tag actually exists for a given package before
 *     bumping its pin to that tag.
 */
interface UpstreamVersionResolverInterface
{
    /**
     * Latest stable release version of `$package`, or null if the resolver
     * can't determine one (network unavailable, package not found, only
     * dev/branch versions exist, etc.). Production: ignores -beta / dev-*
     * versions; only returns UTC YYYY.MM.DD.HHMM tags.
     */
    public function latestStable(string $package): ?string;

    /**
     * True iff `$version` is a published stable tag for `$package`.
     */
    public function hasVersion(string $package, string $version): bool;
}
