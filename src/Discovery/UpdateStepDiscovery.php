<?php

declare(strict_types=1);

namespace Semitexa\Update\Discovery;

use ReflectionClass;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Update\Attribute\AsUpdateStep;
use Semitexa\Update\Contract\UpdateStepInterface;
use Semitexa\Update\Exception\UpdateException;

/**
 * Discovers classes marked with #[AsUpdateStep] via the core ClassDiscovery,
 * validates they implement UpdateStepInterface, and materializes DiscoveredStep
 * DTOs with attribute metadata and a source-file checksum.
 */
final class UpdateStepDiscovery
{
    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
    ) {
    }

    /**
     * @return list<DiscoveredStep>
     *
     * @throws UpdateException When an attributed class does not implement UpdateStepInterface.
     */
    public function discover(): array
    {
        $fqcns = $this->classDiscovery->findClassesWithAttribute(AsUpdateStep::class);
        $classMap = $this->classDiscovery->getClassMap();

        $steps = [];
        foreach ($fqcns as $fqcn) {
            if (!is_subclass_of($fqcn, UpdateStepInterface::class)) {
                throw new UpdateException(sprintf(
                    'Class %s is marked #[AsUpdateStep] but does not implement %s.',
                    $fqcn,
                    UpdateStepInterface::class,
                ));
            }

            $ref = new ReflectionClass($fqcn);
            if ($ref->isAbstract()) {
                continue;
            }

            $attr = $ref->getAttributes(AsUpdateStep::class)[0]->newInstance();
            $filePath = $classMap[$fqcn] ?? ($ref->getFileName() ?: null);
            $checksum = $this->checksum($filePath);

            $steps[] = new DiscoveredStep(
                fqcn: $fqcn,
                phase: $attr->phase,
                dependencies: array_values($attr->dependencies),
                description: $attr->description,
                checksum: $checksum,
                filePath: $filePath,
            );
        }

        usort($steps, static fn (DiscoveredStep $a, DiscoveredStep $b): int => strcmp($a->fqcn, $b->fqcn));

        return $steps;
    }

    private function checksum(?string $filePath): string
    {
        if ($filePath === null || !is_file($filePath)) {
            return '';
        }
        $hash = @hash_file('sha256', $filePath);
        return $hash === false ? '' : $hash;
    }
}
