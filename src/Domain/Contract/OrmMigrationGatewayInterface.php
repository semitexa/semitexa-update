<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Contract;

use Semitexa\Update\Domain\Model\SchemaSyncResult;
use Semitexa\Update\Domain\Model\SchemaSyncStatus;

/**
 * Update's one-way seam to the ORM schema migration system.
 *
 * Update never touches ORM internals: it asks the gateway whether the schema is
 * in sync and tells the gateway to bring it forward. The gateway encapsulates
 * everything the ORM owns — the diff/sync engine, safe-rename / deprecate-then-drop
 * policies, destructive-op gating.
 *
 * Substitute this interface with a fake in tests; the production implementation
 * (DefaultOrmMigrationGateway) is the only place in semitexa-update that knows
 * about the ORM's public API.
 */
interface OrmMigrationGatewayInterface
{
    /**
     * Inspect the schema state for the given connection without changing anything.
     */
    public function inspect(string $connection): SchemaSyncStatus;

    /**
     * Bring the database schema forward to match the entity model.
     *
     * Returns a result describing what was applied. Callers must treat ORM as the
     * authority on whether destructive operations are allowed; passing
     * `allowDestructive=true` only opts in to the destructive subset that ORM
     * already classified as safe-by-policy.
     */
    public function synchronize(
        string $connection,
        bool $allowDestructive = false,
        bool $dryRun = false,
    ): SchemaSyncResult;
}
