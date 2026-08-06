<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Composer;

/**
 * One package the Composer-update phase intends to act on.
 *
 *  - `name`               — composer package name (e.g. "semitexa/update")
 *  - `declared`           — composer.json constraint (e.g. "2026.05.12.0643",
 *                           "@dev", "*", or null when the package only shows
 *                           up in lock/vendor)
 *  - `locked`             — composer.lock concrete version
 *  - `installed`          — vendor/composer/installed.json concrete version
 *  - `targetVersion`      — what the runner intends to pin composer.json to,
 *                           OR null if the entry is a path-repo / dev /
 *                           wildcard that the runner refuses to touch
 *  - `pinKind`            — `exact` (release-pinned), `dev` (@dev / dev-*),
 *                           `path_repo` (lock or installed shows path), or
 *                           `wildcard` ("*"). `exact` is the only kind the
 *                           runner bumps.
 *  - `skipReason`         — non-empty when the runner won't touch this entry
 */
final readonly class ComposerUpdatePlanEntry
{
    public const PIN_EXACT = 'exact';
    public const PIN_DEV = 'dev';
    public const PIN_PATH_REPO = 'path_repo';
    public const PIN_WILDCARD = 'wildcard';

    /**
     * Present in the lock or vendor, but absent from composer.json: something
     * else requires it. This project pins nothing about it, composer resolves
     * it, and there is no pin here to rewrite — so it must never be able to
     * block an update the way an unresolvable exact pin does.
     */
    public const PIN_TRANSITIVE = 'transitive';

    public function __construct(
        public string $name,
        public ?string $declared,
        public ?string $locked,
        public ?string $installed,
        public ?string $targetVersion,
        public string $pinKind,
        public string $skipReason,
    ) {
    }

    public function willBeBumped(): bool
    {
        return $this->pinKind === self::PIN_EXACT
            && $this->targetVersion !== null
            && $this->targetVersion !== $this->declared;
    }

    /**
     * True when this entry was an exact-pinned release that the runner
     * tried to resolve upstream but couldn't (no Packagist metadata, no
     * date-based release tags published, network unreachable, etc.).
     *
     * Distinct from `skipReason !== ''`, which means the entry was
     * intentionally left alone (path-repo / dev / wildcard).
     */
    public function isUnresolved(): bool
    {
        return $this->pinKind === self::PIN_EXACT
            && $this->targetVersion === null
            && $this->skipReason === '';
    }
}
