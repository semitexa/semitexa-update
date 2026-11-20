<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Scaffold;

/**
 * One place the scaffold comes from: its files at their project-relative paths,
 * and the manifest that describes them.
 *
 * Since step 2 of one source for bin/semitexa there are two:
 *  - semitexa/installer's: bin/semitexa, the Dockerfile, the Compose files;
 *  - this package's own: the agents' docs (until step 7) and the retired list.
 */
final readonly class ScaffoldSource
{
    public function __construct(
        /** The package it comes from, as messages name it. */
        public string $name,
        public string $root,
        public string $manifestPath,
    ) {
    }
}
