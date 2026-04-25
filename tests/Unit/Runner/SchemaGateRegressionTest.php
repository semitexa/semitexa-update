<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Contract\DataPatchInterface;
use Semitexa\Update\Discovery\DataPatchDiscovery;
use Semitexa\Update\Discovery\DiscoveredPatch;
use Semitexa\Update\Enum\UpdatePhase;
use Semitexa\Update\Journal\JournalRepository;
use Semitexa\Update\Planner\DagBuilder;
use Semitexa\Update\Planner\Plan;
use Semitexa\Update\Runner\UpdateRunner;
use Semitexa\Update\Schema\LiveSchemaInspector;
use Semitexa\Update\Schema\SchemaCompatibilityChecker;

final class SchemaGateRegressionTest extends TestCase
{
    public function testSchemaCompatibilityCheckerRejectsMissingTableAndVersionRange(): void
    {
        $checker = new SchemaCompatibilityChecker(new LiveSchemaInspector($this->sqlite()));
        $patch = $this->patch(
            requires: [SchemaGateEntity::class],
            minSemitexa: '2.0.0',
            maxSemitexa: '2.5.0',
        );

        $result = $checker->check($patch, '1.9.0');

        self::assertFalse($result->compatible);
        self::assertContains('requires Semitexa >= 2.0.0, current is 1.9.0', $result->reasons);
        self::assertContains(
            "required entity " . SchemaGateEntity::class . " expects table 'schema_gate_entities', which does not exist in the database",
            $result->reasons,
        );
    }

    public function testSchemaCompatibilityCheckerRejectsMissingColumn(): void
    {
        $db = $this->sqlite();
        $db->query('CREATE TABLE schema_gate_entities (id INTEGER PRIMARY KEY)');

        $checker = new SchemaCompatibilityChecker(new LiveSchemaInspector($db));
        $result = $checker->check($this->patch(requires: [SchemaGateEntity::class]), '2.1.0');

        self::assertFalse($result->compatible);
        self::assertContains(
            "required entity " . SchemaGateEntity::class . " expects column schema_gate_entities.slug, which does not exist in the database",
            $result->reasons,
        );
    }

    public function testRunPhasesReportsGatedPatchAsSkipped(): void
    {
        $db = $this->sqlite();
        $runner = new UpdateRunner(
            discovery: new DataPatchDiscovery(new ClassDiscovery()),
            dagBuilder: new DagBuilder(),
            journal: new JournalRepository($db),
            adapter: $db,
            compatibilityChecker: new SchemaCompatibilityChecker(new LiveSchemaInspector($db)),
            semitexaVersion: '2.1.0',
        );

        $report = $runner->runPhases(
            new Plan(
                pendingByPhase: [
                    UpdatePhase::Apply->value => [$this->patch(requires: [SchemaGateEntity::class])],
                ],
                appliedByPhase: [],
                journalByIdentity: [],
            ),
            [UpdatePhase::Apply],
        );

        self::assertSame([], $report->applied);
        self::assertNull($report->failedIdentity);
        self::assertCount(1, $report->skipped);
        self::assertSame('semitexa/update:schema-gate-test', $report->skipped[0]->identity);
        self::assertNotEmpty($report->skipped[0]->reasons);
    }

    private function sqlite(): SqliteAdapter
    {
        return new SqliteAdapter('sqlite::memory:');
    }

    /**
     * @param list<class-string> $requires
     */
    private function patch(array $requires = [], ?string $minSemitexa = null, ?string $maxSemitexa = null): DiscoveredPatch
    {
        return new DiscoveredPatch(
            identity: 'semitexa/update:schema-gate-test',
            id: 'schema-gate-test',
            module: 'semitexa/update',
            fqcn: SchemaGatePatch::class,
            phase: UpdatePhase::Apply,
            dependencies: [],
            requires: $requires,
            requiresColumns: [],
            minSemitexa: $minSemitexa,
            maxSemitexa: $maxSemitexa,
            description: 'schema gate regression test',
            reversible: false,
            checksum: 'test',
            filePath: __FILE__,
        );
    }
}

#[FromTable('schema_gate_entities')]
final class SchemaGateEntity
{
    #[Column(type: MySqlType::Varchar, length: 255)]
    public string $slug;
}

final class SchemaGatePatch implements DataPatchInterface
{
    public function apply(DataPatchContext $ctx): void
    {
    }
}
