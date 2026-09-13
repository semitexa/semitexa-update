<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

/**
 * Coarse classification of a scaffold file. Used by the future sync engine
 * to decide whether a missing/old/diverged file in a downstream project
 * is safe to auto-replace, requires `.new` conflict reporting, or must
 * never be touched.
 *
 * The categories are intentionally low-cardinality. New categories should
 * be added only when a genuinely different sync policy applies.
 */
enum ScaffoldFileCategory: string
{
    /** A program intended to be executed; the executable bit matters. */
    case Executable = 'executable';

    /** Template that ships with the project but is never the live secret file
     *  (e.g. .env.default lives next to a project-managed .env). */
    case EnvTemplate = 'env_template';

    /** Container / build / orchestration files (Dockerfile, docker-compose.*.yml). */
    case Infrastructure = 'infrastructure';

    /** Files that exist primarily to be checked into version control (e.g. .gitignore). */
    case Vcs = 'vcs';

    /** Template that the operator is expected to copy + edit (e.g. docker-compose.override.yml.example). */
    case Example = 'example';

    /**
     * Static-analysis configuration (phpstan.neon and the files it names).
     *
     * A category of its own because the sync policy genuinely differs: the
     * shim and the strict wrapper are framework-owned and replace cleanly,
     * while phpstan.neon is the one file a project is EXPECTED to edit — it
     * adds paths and uncomments its baseline include — so it is offered as a
     * candidate rather than written over.
     */
    case Analysis = 'analysis';
}
