<?php

declare(strict_types=1);

namespace Semitexa\Update\Attribute;

use Attribute;

/**
 * Marks a class the update should ask for a read-only note to the operator.
 *
 * Discovered exactly like #[AsDataPatch], and deliberately separate from it: a
 * patch mutates and must be ordered, journalled and idempotent, while an
 * advisory only reads. Sharing one attribute would have meant every advisory
 * carrying phase and dependency fields that mean nothing for it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsUpdateAdvisory
{
    public function __construct(
        /** Stable name within the module, e.g. 'override-drift'. */
        public string $name,
        /** Owning package, e.g. 'semitexa/prompt'. */
        public string $module,
    ) {
    }
}
