<?php

declare(strict_types=1);

namespace Semitexa\Update\Discovery;

use Semitexa\Update\Enum\UpdatePhase;

/**
 * Immutable snapshot of an update step as discovered from attributes.
 *
 * The FQCN is the stable identity key in the journal. The checksum is a
 * sha256 of the class source file and lets later tooling detect drift
 * (step source changed after it was applied). V1 computes the checksum but
 * does not enforce it — that gate lands in a later version.
 */
final readonly class DiscoveredStep
{
    /**
     * @param class-string       $fqcn         Step class FQCN — the stable id.
     * @param UpdatePhase        $phase        Phase declared on the attribute.
     * @param list<class-string> $dependencies Other step FQCNs this one depends on.
     * @param string|null        $description  Operator-facing summary.
     * @param string             $checksum     sha256 of the source file, '' if unavailable.
     * @param string|null        $filePath     Source file path (for diagnostics), null if unknown.
     */
    public function __construct(
        public string $fqcn,
        public UpdatePhase $phase,
        public array $dependencies,
        public ?string $description,
        public string $checksum,
        public ?string $filePath,
    ) {
    }
}
