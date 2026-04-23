# Semitexa Update

`semitexa/update` provides an attribute-driven update pipeline for Semitexa applications. It discovers update steps, orders them by phase and dependency DAG, records execution state in a journal, and exposes CLI commands for planning, running, and inspecting updates.

## What It Does

- discovers step classes annotated with `#[AsUpdateStep(...)]`
- runs phases in strict order: `pre-deploy`, `deploy`, `finalize`
- resolves per-phase dependencies through a DAG
- stores `pending`, `applied`, and `failed` state in a journal
- stops on the first failed step and reports the failure

## Core Concepts

Every update step must implement `Semitexa\Update\Contract\UpdateStepInterface` and provide an idempotent `apply()` method.

```php
<?php

declare(strict_types=1);

namespace App\Update;

use Semitexa\Update\Attribute\AsUpdateStep;
use Semitexa\Update\Context\UpdateContext;
use Semitexa\Update\Contract\UpdateStepInterface;
use Semitexa\Update\Enum\UpdatePhase;

#[AsUpdateStep(
    phase: UpdatePhase::Deploy,
    dependencies: [],
    description: 'Backfill slugs for existing articles',
)]
final class BackfillArticleSlugs implements UpdateStepInterface
{
    public function apply(UpdateContext $ctx): void
    {
        // Keep steps idempotent so reruns after a crash stay safe.
        $ctx->execute("
            UPDATE articles
            SET slug = LOWER(REPLACE(title, ' ', '-'))
            WHERE slug IS NULL
        ");
    }
}
```

Phase ordering is global. Dependency ordering is applied within the same phase.

## CLI Commands

The package exposes three commands:

- `update` — apply pending update steps in phase and DAG order
- `update --dry-run` — print the execution plan without changing state
- `update:plan` — compute and display pending steps only
- `update:status` — show applied, pending, and failed counts by phase

Each command accepts `--connection=<name>` and defaults to `default`.

## Runtime Behavior

- `update:plan` and `update:status` ensure the journal schema exists before reading state
- `update` marks each step as pending before execution, then as applied or failed
- the runner instantiates step classes directly and passes an `UpdateContext`
- step bodies own their transaction boundaries and idempotency guards

## When To Use It

Use `semitexa/update` when your package or application needs durable, restart-safe operational changes such as:

- schema migrations
- data backfills
- one-time repair tasks
- phased deploy/finalize work that must run in a deterministic order
