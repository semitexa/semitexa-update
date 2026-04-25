<?php

declare(strict_types=1);

namespace Semitexa\Update\Migration;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Orm\Connection\ConnectionRegistry;
use Semitexa\Update\Exception\UpdateException;
use Throwable;

/**
 * The only place in semitexa-update that touches the ORM's public API. All schema
 * inspection and synchronization goes through here; the rest of Update treats the
 * ORM as a black box behind OrmMigrationGatewayInterface.
 *
 * If ORM ever exposes a richer migration façade, this class is the single point
 * of adaptation.
 */
#[AsService]
#[SatisfiesServiceContract(of: OrmMigrationGatewayInterface::class)]
final class DefaultOrmMigrationGateway implements OrmMigrationGatewayInterface
{
    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    public function inspect(string $connection): SchemaSyncStatus
    {
        try {
            $orm = $this->connections->manager($connection);
            $codeSchema = $orm->getSchemaCollector()->collect();
            $diff = $orm->getSchemaComparator()->compare($codeSchema);

            if ($diff->isEmpty()) {
                return new SchemaSyncStatus(
                    inSync: true,
                    pendingOperations: 0,
                    destructiveOperations: 0,
                    summary: 'Database is up to date.',
                );
            }

            $plan = $orm->getSyncEngine()->buildPlan($diff);
            $safe = $plan->getSafeOperations();
            $destructive = $plan->getDestructiveOperations();

            return new SchemaSyncStatus(
                inSync: false,
                pendingOperations: count($safe) + count($destructive),
                destructiveOperations: count($destructive),
                summary: $plan->getSummary(),
            );
        } catch (Throwable $e) {
            throw new UpdateException(
                'OrmMigrationGateway::inspect failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    public function synchronize(
        string $connection,
        bool $allowDestructive = false,
        bool $dryRun = false,
    ): SchemaSyncResult {
        try {
            $orm = $this->connections->manager($connection);
            $codeSchema = $orm->getSchemaCollector()->collect();
            $diff = $orm->getSchemaComparator()->compare($codeSchema);

            if ($diff->isEmpty()) {
                return new SchemaSyncResult(
                    executedOperations: 0,
                    skippedDestructive: 0,
                    dryRun: $dryRun,
                    summary: 'Database is up to date. No changes needed.',
                );
            }

            $syncEngine = $orm->getSyncEngine();
            $plan = $syncEngine->buildPlan($diff);
            $destructive = count($plan->getDestructiveOperations());

            if ($dryRun) {
                $totalSafe = count($plan->getSafeOperations());
                return new SchemaSyncResult(
                    executedOperations: 0,
                    skippedDestructive: $allowDestructive ? 0 : $destructive,
                    dryRun: true,
                    summary: sprintf(
                        'Dry-run: would execute %d safe operation(s)%s. %s',
                        $totalSafe,
                        $allowDestructive ? sprintf(' + %d destructive', $destructive) : '',
                        $plan->getSummary(),
                    ),
                );
            }

            $executed = $syncEngine->execute($plan, $allowDestructive);
            $executedCount = count($executed);
            $skipped = $allowDestructive ? 0 : $destructive;

            return new SchemaSyncResult(
                executedOperations: $executedCount,
                skippedDestructive: $skipped,
                dryRun: false,
                summary: sprintf(
                    'ORM applied %d operation(s)%s.',
                    $executedCount,
                    $skipped > 0 ? sprintf(' (%d destructive skipped — use --allow-destructive)', $skipped) : '',
                ),
            );
        } catch (Throwable $e) {
            throw new UpdateException(
                'OrmMigrationGateway::synchronize failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
