<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

/**
 * A `semitexa/*` package whose Composer install source resolves to a local
 * path repository (e.g. `packages/semitexa-core` in a development checkout).
 *
 * The update workflow treats these packages as workspace source code, not
 * Composer-updateable dependencies: they are skipped by version discovery,
 * release-manifest materialization, and any deployment planning.
 */
final readonly class LocalWorkspacePackage
{
    /**
     * @param string $name      Composer package name, e.g. "semitexa/core".
     * @param string $version   composer.lock version (often "dev-main" for path repos).
     * @param string $sourceUrl The path repository URL recorded in composer.lock.
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $sourceUrl,
    ) {
    }
}
