<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Update\Application\Service\LiveSchemaInspector;

/**
 * The inspector asks the adapter's dialect, not its class (tk-orm-dialect-layer step 8): the
 * query adapter an ORM hands out is a TransactionAwareAdapter, neither MysqlAdapter nor
 * SqliteAdapter, and was refused as "unsupported" (found reading the prompt override table).
 */
final class LiveSchemaInspectorTest extends TestCase
{
    #[Test]
    public function the_query_adapter_an_orm_hands_out_is_read_by_its_dialect(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute('CREATE TABLE present (id INTEGER PRIMARY KEY, name TEXT)');

        foreach ([$orm->getQueryAdapter(), $orm->getAdapter()] as $adapter) {
            $inspector = new LiveSchemaInspector($adapter);
            self::assertTrue($inspector->hasTable('present'), $adapter::class);
            self::assertFalse($inspector->hasTable('absent'), $adapter::class);
            self::assertTrue($inspector->hasColumn('present', 'name'), $adapter::class);
            self::assertFalse($inspector->hasColumn('present', 'missing'), $adapter::class);
        }
    }
}
