<?php

declare(strict_types=1);

namespace Semitexa\Update\Discovery;

use ReflectionClass;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Semitexa\Update\Exception\UpdateException;

/**
 * Discovers classes marked with #[AsDataPatch], validates their declarations, and
 * materializes DiscoveredPatch DTOs.
 *
 * Validation rejected here:
 *   - class is attributed but does not implement DataPatchInterface
 *   - id or module is empty
 *   - duplicate identity (same module:id from two different classes)
 */
final class DataPatchDiscovery
{
    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
    ) {
    }

    /**
     * @return list<DiscoveredPatch>
     *
     * @throws UpdateException When validation fails.
     */
    public function discover(): array
    {
        $fqcns = $this->classDiscovery->findClassesWithAttribute(AsDataPatch::class);
        $classMap = $this->classDiscovery->getClassMap();

        $patches = [];
        $seenIdentity = [];

        foreach ($fqcns as $fqcn) {
            if (!is_subclass_of($fqcn, DataPatchInterface::class)) {
                throw new UpdateException(sprintf(
                    'Class %s is marked #[AsDataPatch] but does not implement %s.',
                    $fqcn,
                    DataPatchInterface::class,
                ));
            }

            $ref = new ReflectionClass($fqcn);
            if ($ref->isAbstract()) {
                continue;
            }

            $attr = $ref->getAttributes(AsDataPatch::class)[0]->newInstance();

            if ($attr->id === '') {
                throw new UpdateException(sprintf(
                    'Patch %s declares an empty id; AsDataPatch->id is required.',
                    $fqcn,
                ));
            }
            if ($attr->module === '') {
                throw new UpdateException(sprintf(
                    'Patch %s declares an empty module; AsDataPatch->module is required.',
                    $fqcn,
                ));
            }

            $identity = $attr->identity();
            if (isset($seenIdentity[$identity])) {
                throw new UpdateException(sprintf(
                    'Patch identity collision: %s is declared by both %s and %s.',
                    $identity,
                    $seenIdentity[$identity],
                    $fqcn,
                ));
            }
            $seenIdentity[$identity] = $fqcn;

            $filePath = $classMap[$fqcn] ?? ($ref->getFileName() ?: null);
            $checksum = $this->checksum($filePath);

            $patches[] = new DiscoveredPatch(
                identity: $identity,
                id: $attr->id,
                module: $attr->module,
                fqcn: $fqcn,
                phase: $attr->phase,
                dependencies: array_values($attr->dependencies),
                requires: array_values($attr->requires),
                requiresColumns: $attr->requiresColumns,
                minSemitexa: $attr->minSemitexa,
                maxSemitexa: $attr->maxSemitexa,
                description: $attr->description,
                reversible: $attr->reversible,
                checksum: $checksum,
                filePath: $filePath,
            );
        }

        usort($patches, static fn (DiscoveredPatch $a, DiscoveredPatch $b): int => strcmp($a->identity, $b->identity));

        return $patches;
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
