<?php

declare(strict_types=1);

namespace Semitexa\Update\Attribute;

use Attribute;
use Semitexa\Update\Domain\Enum\UpdatePhase;

/**
 * Marks a class as a post-schema data patch.
 *
 * Patches modify *data* — never schema. Schema migrations are owned by the ORM
 * (`orm:diff` / `orm:sync`); this attribute must not be used to declare structural
 * database changes (CREATE/ALTER/DROP/RENAME/TRUNCATE).
 *
 * Identity is `(module, id)`, not the class FQCN. The class can be renamed without
 * the runner re-applying the patch, and two unrelated modules can ship the same
 * local id without colliding.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsDataPatch
{
    /**
     * @param string                       $id              Stable per-module patch id (e.g. "backfill-article-slugs"). MUST NOT change once shipped.
     * @param string                       $module          Owning composer package, e.g. "vendor/pkg".
     * @param UpdatePhase                  $phase           Pre / Apply / Post relative to the ORM schema sync.
     * @param list<string>                 $dependencies    Full identities ("module:id") of patches that must apply before this one.
     * @param list<class-string>           $requires        FQCNs of [Resource]/[FromTable]-attributed entities the patch reads/writes; the runner verifies their tables and columns are live in DB.
     * @param array<string, list<string>>  $requiresColumns Explicit table => list<column> the patch reads/writes; runner verifies presence in DB.
     * @param string|null                  $minSemitexa     Optional inclusive minimum framework version (composer-style).
     * @param string|null                  $maxSemitexa     Optional inclusive maximum framework version.
     * @param string|null                  $description     Operator-facing one-line summary.
     * @param bool                         $reversible      Whether the patch class defines a revert(DataPatchContext) method.
     */
    public function __construct(
        public string $id,
        public string $module,
        public UpdatePhase $phase = UpdatePhase::Apply,
        public array $dependencies = [],
        public array $requires = [],
        public array $requiresColumns = [],
        public ?string $minSemitexa = null,
        public ?string $maxSemitexa = null,
        public ?string $description = null,
        public bool $reversible = false,
    ) {
    }

    /**
     * Combined runtime identity. Used as the journal key and for declaring dependencies.
     */
    public function identity(): string
    {
        return $this->module . ':' . $this->id;
    }
}
