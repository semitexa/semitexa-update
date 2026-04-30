<?php

declare(strict_types=1);

namespace Semitexa\Update\Discovery;

use Semitexa\Update\Domain\Enum\UpdatePhase;

/**
 * Immutable snapshot of a data patch as discovered from attributes.
 *
 * Identity is `(module, id)` — combined into the `identity` field for journal lookups
 * and dependency resolution. The FQCN is kept for instantiation and diagnostics only;
 * it is not the runtime identity.
 */
final readonly class DiscoveredPatch
{
    /**
     * @param string                      $identity        Combined "module:id" — the journal key.
     * @param string                      $id              Per-module stable id.
     * @param string                      $module          Owning composer package.
     * @param class-string                $fqcn            Patch class FQCN (instantiation + diagnostics).
     * @param UpdatePhase                 $phase
     * @param list<string>                $dependencies    Identities of patches that must apply before this one.
     * @param list<class-string>          $requires        Required entity FQCNs.
     * @param array<string, list<string>> $requiresColumns table => list<column>.
     * @param string|null                 $minSemitexa
     * @param string|null                 $maxSemitexa
     * @param string|null                 $description
     * @param bool                        $reversible
     * @param string                      $checksum        sha256 of the source file, '' if unavailable.
     * @param string|null                 $filePath
     */
    public function __construct(
        public string $identity,
        public string $id,
        public string $module,
        public string $fqcn,
        public UpdatePhase $phase,
        public array $dependencies,
        public array $requires,
        public array $requiresColumns,
        public ?string $minSemitexa,
        public ?string $maxSemitexa,
        public ?string $description,
        public bool $reversible,
        public string $checksum,
        public ?string $filePath,
    ) {
    }
}
