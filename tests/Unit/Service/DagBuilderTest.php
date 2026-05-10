<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\DagBuilder;
use Semitexa\Update\Discovery\DiscoveredPatch;
use Semitexa\Update\Domain\Enum\UpdatePhase;
use Semitexa\Update\Domain\Model\Plan;
use Semitexa\Update\Exception\DagCycleException;
use Semitexa\Update\Exception\UpdateException;

/**
 * Regression: DagBuilder previously referenced `Plan` without importing it,
 * so calling build() raised "Class Semitexa\Update\Application\Service\Plan
 * not found" from `bin/semitexa update`. This test guards both the import
 * and the wider planning contract.
 */
final class DagBuilderTest extends TestCase
{
    public function testBuildReturnsDomainModelPlanInstance(): void
    {
        $builder = new DagBuilder();

        $plan = $builder->build([], []);

        self::assertInstanceOf(Plan::class, $plan);
        self::assertSame(\Semitexa\Update\Domain\Model\Plan::class, $plan::class);
        self::assertTrue($plan->isEmpty());
        self::assertSame(0, $plan->pendingCount());
        self::assertSame(0, $plan->appliedCount());
    }

    public function testBuildPartitionsPatchesByPhaseAndOrdersWithinPhase(): void
    {
        $patches = [
            $this->patch('mod:b', UpdatePhase::Apply, dependencies: ['mod:a']),
            $this->patch('mod:a', UpdatePhase::Apply),
            $this->patch('mod:pre', UpdatePhase::Pre),
            $this->patch('mod:post', UpdatePhase::Post),
        ];

        $plan = (new DagBuilder())->build($patches, []);

        $apply = $plan->pendingByPhase[UpdatePhase::Apply->value] ?? [];
        self::assertCount(2, $apply);
        self::assertSame('mod:a', $apply[0]->identity);
        self::assertSame('mod:b', $apply[1]->identity);

        self::assertCount(1, $plan->pendingByPhase[UpdatePhase::Pre->value] ?? []);
        self::assertCount(1, $plan->pendingByPhase[UpdatePhase::Post->value] ?? []);
        self::assertSame(4, $plan->pendingCount());
    }

    public function testBuildRejectsBackwardCrossPhaseDependency(): void
    {
        $patches = [
            $this->patch('mod:pre', UpdatePhase::Pre, dependencies: ['mod:apply']),
            $this->patch('mod:apply', UpdatePhase::Apply),
        ];

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/later phase/');

        (new DagBuilder())->build($patches, []);
    }

    public function testBuildRejectsUnknownDependency(): void
    {
        $patches = [
            $this->patch('mod:a', UpdatePhase::Apply, dependencies: ['mod:missing']),
        ];

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/unknown patch/');

        (new DagBuilder())->build($patches, []);
    }

    public function testBuildDetectsIntraPhaseCycle(): void
    {
        $patches = [
            $this->patch('mod:a', UpdatePhase::Apply, dependencies: ['mod:b']),
            $this->patch('mod:b', UpdatePhase::Apply, dependencies: ['mod:a']),
        ];

        $this->expectException(DagCycleException::class);

        (new DagBuilder())->build($patches, []);
    }

    /**
     * @param list<string> $dependencies
     */
    private function patch(string $identity, UpdatePhase $phase, array $dependencies = []): DiscoveredPatch
    {
        [$module, $id] = explode(':', $identity, 2);

        return new DiscoveredPatch(
            identity: $identity,
            id: $id,
            module: $module,
            fqcn: 'Semitexa\\Update\\Tests\\Fixture\\NoSuchPatch',
            phase: $phase,
            dependencies: $dependencies,
            requires: [],
            requiresColumns: [],
            minSemitexa: null,
            maxSemitexa: null,
            description: null,
            reversible: false,
            checksum: '',
            filePath: null,
        );
    }
}
