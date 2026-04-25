<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Exception\PatchSafetyException;

/**
 * Behavioral guard for the DataPatchContext DDL gate.
 *
 * Data patches must operate on existing data only; structural changes belong to
 * the ORM. The context's runtime guard is the last line of defense — if it slips,
 * the data-patch boundary is gone.
 */
final class DataPatchContextDdlGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function ddlStatements(): array
    {
        return [
            'create table'    => ['CREATE TABLE foo (id INT)'],
            'lower create'    => ['create table foo (id int)'],
            'alter table'     => ['ALTER TABLE foo ADD COLUMN bar VARCHAR(32)'],
            'drop table'      => ['DROP TABLE foo'],
            'rename'          => ['RENAME TABLE foo TO bar'],
            'truncate'        => ['TRUNCATE TABLE foo'],
            'leading comment' => ["-- a comment\nCREATE TABLE foo (id INT)"],
            'leading block'   => ["/* hello */ CREATE TABLE foo (id INT)"],
        ];
    }

    /**
     * @dataProvider ddlStatements
     */
    public function testRejectsDdl(string $sql): void
    {
        $ctx = new DataPatchContext($this->fakeAdapter());

        $this->expectException(PatchSafetyException::class);
        $ctx->execute($sql);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dmlStatements(): array
    {
        return [
            'update'        => ['UPDATE articles SET slug = ? WHERE slug IS NULL'],
            'insert'        => ['INSERT INTO articles (id, title) VALUES (?, ?)'],
            'select'        => ['SELECT id FROM articles'],
            'delete'        => ['DELETE FROM articles WHERE id = ?'],
            'with-comments' => ["-- backfill\nUPDATE articles SET slug = ?"],
        ];
    }

    /**
     * @dataProvider dmlStatements
     */
    public function testAllowsDml(string $sql): void
    {
        $adapter = $this->fakeAdapter();
        $ctx = new DataPatchContext($adapter);

        $result = $ctx->execute($sql);
        self::assertSame($adapter->lastResult, $result);
    }

    private function fakeAdapter(): DatabaseAdapterInterface
    {
        return new class implements DatabaseAdapterInterface {
            public ?QueryResult $lastResult = null;

            public function supports(ServerCapability $capability): bool
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return 'test';
            }

            public function execute(string $sql, array $params = []): QueryResult
            {
                return $this->lastResult = $this->emptyResult();
            }

            public function query(string $sql): QueryResult
            {
                return $this->lastResult = $this->emptyResult();
            }

            public function lastInsertId(): string
            {
                return '0';
            }

            private function emptyResult(): QueryResult
            {
                return new QueryResult([], 0);
            }
        };
    }
}
