<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Contract;

use Semitexa\Update\Domain\Model\Advisory\UpdateAdvisory;

/**
 * A package's read-only word to the operator about what the update just did to
 * it. Implementations are tagged #[AsUpdateAdvisory] and MUST have a
 * parameterless constructor — the update instantiates them directly, so a
 * container that cannot build one package's services never costs the operator
 * every other package's advice.
 *
 * An advisory MUST NOT change anything: that is what data patches are for. It
 * must also not throw for a condition it is reporting on — return an advisory
 * saying the check could not run. An escaped throwable is rendered as exactly
 * that, because an advisory that vanishes when its subject is broken is the
 * failure it was added to prevent.
 */
interface UpdateAdvisoryInterface
{
    /** Null when this package has nothing to say at all. */
    public function advise(): ?UpdateAdvisory;
}
