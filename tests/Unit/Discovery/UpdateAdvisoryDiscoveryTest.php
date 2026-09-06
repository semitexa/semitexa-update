<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Update\Attribute\AsUpdateAdvisory;
use Semitexa\Update\Discovery\UpdateAdvisoryDiscovery;
use Semitexa\Update\Domain\Contract\UpdateAdvisoryInterface;
use Semitexa\Update\Domain\Model\Advisory\UpdateAdvisory;
use Semitexa\Update\Exception\UpdateException;

/**
 * The advisory seam: how other packages get a word in after an update.
 *
 * The behaviour worth the most here is the failure one. An advisory that throws
 * must become an advisory that SAYS SO, never a gap in the report — an operator
 * reading a clean list would otherwise conclude nothing had drifted, when in
 * fact nothing was checked.
 */
final class UpdateAdvisoryDiscoveryTest extends TestCase
{
    #[Test]
    public function it_collects_what_each_package_has_to_say_in_a_stable_order(): void
    {
        $advisories = $this->discover([ZebraAdvisory::class, AlphaAdvisory::class]);

        self::assertSame(
            ['pkg/alpha:one', 'pkg/zebra:one'],
            array_map(static fn (UpdateAdvisory $a): string => $a->id, $advisories),
            'the report order must not depend on discovery order',
        );
    }

    #[Test]
    public function a_package_with_nothing_to_say_is_absent_rather_than_empty(): void
    {
        self::assertSame([], $this->discover([SilentAdvisory::class]));
    }

    /**
     * The one that matters. Silence and "clean" look identical in a report, so
     * a broken advisory has to speak.
     */
    #[Test]
    public function an_advisory_that_throws_is_reported_not_dropped(): void
    {
        $advisories = $this->discover([ExplodingAdvisory::class]);

        self::assertCount(1, $advisories, 'a throwing advisory vanished from the report');
        self::assertTrue($advisories[0]->actionable);
        self::assertStringContainsString('could not run', implode(' ', $advisories[0]->lines));
        self::assertStringContainsString('the disk is gone', implode(' ', $advisories[0]->lines));
        self::assertStringContainsString(
            'NOT checked',
            implode(' ', $advisories[0]->lines),
            'the report must not let unknown read as clean',
        );
    }

    #[Test]
    public function a_class_attributed_but_not_implementing_the_contract_is_a_packaging_error(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        $this->discover([NotAnAdvisory::class]);
    }

    #[Test]
    public function two_packages_cannot_claim_one_identity(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/claimed by both/');

        $this->discover([AlphaAdvisory::class, AlphaTwinAdvisory::class]);
    }

    #[Test]
    public function an_empty_name_or_module_is_refused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/empty name or module/');

        $this->discover([NamelessAdvisory::class]);
    }

    /**
     * @param list<class-string> $classes
     * @return list<UpdateAdvisory>
     */
    private function discover(array $classes): array
    {
        return new UpdateAdvisoryDiscovery(new FixedClassDiscovery($classes))->collect();
    }
}

final class FixedClassDiscovery extends ClassDiscovery
{
    /** @param list<class-string> $classes */
    public function __construct(private readonly array $classes)
    {
    }

    /** @return list<class-string> */
    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $this->classes;
    }
}

#[AsUpdateAdvisory(name: 'one', module: 'pkg/alpha')]
final class AlphaAdvisory implements UpdateAdvisoryInterface
{
    public function advise(): ?UpdateAdvisory
    {
        return UpdateAdvisory::clean('pkg/alpha:one', 'Alpha', 'nothing to do');
    }
}

#[AsUpdateAdvisory(name: 'one', module: 'pkg/alpha')]
final class AlphaTwinAdvisory implements UpdateAdvisoryInterface
{
    public function advise(): ?UpdateAdvisory
    {
        return null;
    }
}

#[AsUpdateAdvisory(name: 'one', module: 'pkg/zebra')]
final class ZebraAdvisory implements UpdateAdvisoryInterface
{
    public function advise(): ?UpdateAdvisory
    {
        return UpdateAdvisory::clean('pkg/zebra:one', 'Zebra', 'nothing to do');
    }
}

#[AsUpdateAdvisory(name: 'quiet', module: 'pkg/silent')]
final class SilentAdvisory implements UpdateAdvisoryInterface
{
    public function advise(): ?UpdateAdvisory
    {
        return null;
    }
}

#[AsUpdateAdvisory(name: 'boom', module: 'pkg/exploding')]
final class ExplodingAdvisory implements UpdateAdvisoryInterface
{
    public function advise(): ?UpdateAdvisory
    {
        throw new \RuntimeException('the disk is gone');
    }
}

#[AsUpdateAdvisory(name: '', module: 'pkg/nameless')]
final class NamelessAdvisory implements UpdateAdvisoryInterface
{
    public function advise(): ?UpdateAdvisory
    {
        return null;
    }
}

#[AsUpdateAdvisory(name: 'nope', module: 'pkg/wrong')]
final class NotAnAdvisory
{
}
