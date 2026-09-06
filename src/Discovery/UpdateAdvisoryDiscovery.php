<?php

declare(strict_types=1);

namespace Semitexa\Update\Discovery;

use ReflectionClass;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Update\Attribute\AsUpdateAdvisory;
use Semitexa\Update\Domain\Contract\UpdateAdvisoryInterface;
use Semitexa\Update\Domain\Model\Advisory\UpdateAdvisory;
use Semitexa\Update\Exception\UpdateException;

/**
 * Finds every #[AsUpdateAdvisory] class and collects what it has to say.
 *
 * Validation mirrors {@see DataPatchDiscovery} and is deliberately loud —
 * a class attributed but not implementing the contract, or two classes claiming
 * one identity, is a packaging mistake the author wants to hear about.
 *
 * COLLECTION is deliberately quiet in the other direction: one package's
 * advisory throwing must not cost the operator every other package's. A
 * throwable becomes an advisory saying so, because an advisory that disappears
 * when its own subject is broken is the exact failure this seam exists to
 * prevent — the operator reads a clean report and concludes nothing is wrong.
 */
final class UpdateAdvisoryDiscovery
{
    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
    ) {
    }

    /**
     * @return list<UpdateAdvisory> ordered by id, so the report is stable
     *
     * @throws UpdateException when a declaration is invalid
     */
    public function collect(): array
    {
        $advisories = [];
        $seen = [];

        foreach ($this->classDiscovery->findClassesWithAttribute(AsUpdateAdvisory::class) as $fqcn) {
            if (!is_subclass_of($fqcn, UpdateAdvisoryInterface::class)) {
                throw new UpdateException(sprintf(
                    'Class %s is marked #[AsUpdateAdvisory] but does not implement %s.',
                    $fqcn,
                    UpdateAdvisoryInterface::class,
                ));
            }

            $ref = new ReflectionClass($fqcn);
            if ($ref->isAbstract()) {
                continue;
            }

            $attr = $ref->getAttributes(AsUpdateAdvisory::class)[0]->newInstance();
            if ($attr->name === '' || $attr->module === '') {
                throw new UpdateException(sprintf(
                    'Advisory %s declares an empty name or module; both are required.',
                    $fqcn,
                ));
            }

            $identity = $attr->module . ':' . $attr->name;
            if (isset($seen[$identity])) {
                throw new UpdateException(sprintf(
                    'Advisory identity %s is claimed by both %s and %s.',
                    $identity,
                    $seen[$identity],
                    $fqcn,
                ));
            }
            $seen[$identity] = $fqcn;

            $advisories[] = $this->ask($fqcn, $identity);
        }

        $advisories = array_values(array_filter($advisories));
        usort($advisories, static fn (UpdateAdvisory $a, UpdateAdvisory $b): int => strcmp($a->id, $b->id));

        return $advisories;
    }

    /**
     * @param class-string<UpdateAdvisoryInterface> $fqcn
     */
    private function ask(string $fqcn, string $identity): ?UpdateAdvisory
    {
        try {
            // Constructed directly, not through the container: the contract
            // requires a parameterless constructor so that a package whose
            // services cannot be built still gets to speak.
            return new $fqcn()->advise();
        } catch (\Throwable $e) {
            return UpdateAdvisory::actionable($identity, $identity, [
                'This advisory could not run: ' . $e->getMessage(),
                'Its subject was NOT checked — treat the silence as unknown, not as clean.',
            ]);
        }
    }
}
