# Semitexa Update

`semitexa/update` owns the **update lifecycle** of a Semitexa application — package version detection, plan building, ORM schema synchronization, and post-schema **data patches**. It is **not** a schema migration system.

## Ownership boundary

| Layer | Owner | Tools |
|---|---|---|
| **Database schema** (tables, columns, indexes, foreign keys, safe-rename, deprecate-then-drop) | `semitexa-orm` | `orm:diff`, `orm:sync`, `orm:status` |
| **Update orchestration** (preflight, package version detection, sequencing) | `semitexa-update` | `update`, `update:plan`, `update:status`, `update:packages:*` |
| **Post-schema data patches** (backfills, normalizations, default-row creation) | `semitexa-update` | `#[AsDataPatch]` + `update` |
| **Developer tooling** | `semitexa-dev` | `make:*`, `ai:*`, `dev-graph` |

A data patch must never issue DDL. Schema changes go through ORM. The runner enforces this with a DDL-keyword guard at apply time.

## What this package does

- discovers classes annotated with `#[AsDataPatch(...)]`
- runs phases in strict order: `pre`, `apply`, `post` — relative to the ORM schema sync
- resolves per-phase dependencies through a DAG
- stores `pending`, `applied`, and `failed` state in a per-database journal
- stops on the first failed patch and reports the failure

## What this package does **not** do

- it does **not** perform schema migrations — see `semitexa-orm`
- it does **not** create, alter, drop, rename, or truncate tables or columns
- it does **not** run inside `semitexa-dev` — Dev is for developer tooling only

## Authoring a data patch

```php
<?php

declare(strict_types=1);

namespace App\Update;

use App\Domain\Article;
use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Semitexa\Update\Domain\Enum\UpdatePhase;

#[AsDataPatch(
    id: 'backfill-article-slugs',
    module: 'acme/blog',
    phase: UpdatePhase::Apply,
    dependencies: [],
    requires: [Article::class],
    description: 'Backfill slugs for existing articles',
)]
final class BackfillArticleSlugs implements DataPatchInterface
{
    public function apply(DataPatchContext $ctx): void
    {
        // Patches must be idempotent so re-runs after a crash stay safe.
        $ctx->execute("
            UPDATE articles
            SET slug = LOWER(REPLACE(title, ' ', '-'))
            WHERE slug IS NULL
        ");
    }
}
```

Identity is `(module, id)` — stable across class renames. The combined form `module:id`
is what the journal and dependency declarations use. Phase ordering is global; dependency
ordering is applied within the same phase.

### Required attribute fields

| Field | Type | Purpose |
|---|---|---|
| `id` | `string` | Per-module stable patch id. **MUST NOT change once shipped.** |
| `module` | `string` | Owning composer package. |
| `phase` | `UpdatePhase` | `Pre` / `Apply` / `Post` relative to ORM schema sync. Default: `Apply`. |
| `dependencies` | `list<string>` | Other patch identities (`module:id`) that must apply first. |
| `requires` | `list<class-string>` | `[FromTable]`-attributed entities the patch reads/writes. Runner verifies their tables and columns are live in DB. |
| `requiresColumns` | `array<string, list<string>>` | Explicit `table => columns` map for ad-hoc column gating. |
| `minSemitexa` / `maxSemitexa` | `?string` | Optional framework version range. Enforced when the caller provides the current Semitexa version to the runner/orchestrator. |
| `description` | `?string` | Operator-facing summary. |
| `reversible` | `bool` | Whether the patch class defines a `revert()` method. |

### Schema compatibility gate

Before the runner executes a patch it consults `SchemaCompatibilityChecker`. If any of the
`requires` entities or `requiresColumns` entries are missing from the live database, the
patch is **skipped** (not failed) — no journal row is written, and the operator is told why
in the run report. The fix is to bring the schema forward via `orm:sync`, then re-run `update`.

### DDL guard

`DataPatchContext::execute()` and `query()` reject SQL whose first non-comment token is
`CREATE`, `ALTER`, `DROP`, `RENAME`, or `TRUNCATE`. The runner throws a `PatchSafetyException`
in that case. Schema mutations belong to the ORM.

## CLI commands

- `update` — run the full sweep: preflight → composer → scaffold → patches → orm-sync → health check; `--dry-run` prints the plan without changing state
- `update:plan` — compute and display pending patches only
- `update:status` — updater version, installed release set, last run, and applied/pending/failed patch counts by phase
- `update:history` — the run journal: past runs with outcome, package deltas, duration; `--id=<run>` shows one run in full
- `update:changelog` — package version changes applied here + available upstream, with release-note sections; `--package=<name>` shows one package's CHANGELOG; `--no-remote` skips network

Each accepts `--connection=<name>` and defaults to `default`.

## Run journal & changelog

Every mutating `update` run and every auto-deploy attempt writes one row to
`platform_update_run_journal` (stages, package version deltas, actor, outcome,
duration). `update:history` and `update:changelog` read it; a run lock in
`var/lock/semitexa-update.lock` serializes both paths.

**Per-package release notes convention:** each package keeps a `CHANGELOG.md`
at its root with sections `## <version> — <date>` (newest first, `v` prefix and
date optional, `## Unreleased` on top). `update:changelog` resolves a version
delta to those sections from `vendor/semitexa/<name>/CHANGELOG.md` (consumer)
or `packages/semitexa-<name>/CHANGELOG.md` (dev workspace).

## When to use this package

- backfilling values after a new column has been added by ORM
- normalizing existing data into a new format
- creating required default rows
- migrating configuration rows
- repairing data that depends on a new schema shape

## When **not** to use this package

- creating, altering, dropping, or renaming tables / columns / indexes / foreign keys → use `orm:sync`
- adding new entities to the model → declare a `[Resource]`-attributed class and let ORM pick it up
- versioned numbered schema migrations — Semitexa ORM uses entity attributes as the source of truth, not numbered files

## Package update / framework auto-deploy / remote bootstrap

This package also owns the operational commands that detect, plan, and apply Semitexa **package updates** for a project. Schema changes still flow through ORM; package updates are purely about composer-level version bumps and the orchestration that supports them.

### Configuration

Auto-deploy is opt-in:

```dotenv
SEMITEXA_AUTO_DEPLOY_ENABLED=true
SEMITEXA_AUTO_DEPLOY_CHANNEL=stable
SEMITEXA_AUTO_DEPLOY_SOURCE=mixed
SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL=git@github.com:semitexa/releases.git
SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL=https://example.test/health
SEMITEXA_AUTO_DEPLOY_RESTART_COMMAND=bin/semitexa server:start
```

Remote first-deployment (Ubuntu 20.04+):

```dotenv
SEMITEXA_REMOTE_DEPLOY_TARGETS=deploy@203.0.113.10
SEMITEXA_REMOTE_DEPLOY_PATH=/srv/semitexa/my-project
SEMITEXA_REMOTE_DEPLOY_SSH_PORT=22
SEMITEXA_REMOTE_DEPLOY_DOMAIN=my-project.example.com
SEMITEXA_REMOTE_DEPLOY_USE_PASSWORD=false
```

### Operator commands

| Command | Purpose |
|---|---|
| `update:packages:check` | Inspect auto-deploy state + discover newer Semitexa releases |
| `update:packages:auto` | Execute the package update flow when enabled and updates are available |
| `update:packages:bootstrap-remote` | Bootstrap a fresh remote server for first deployment |
| `update:packages:materialize` | Generate a production-friendly composer manifest with exact `semitexa/*` versions |

Production polling install:

```bash
sudo SEMITEXA_AUTO_DEPLOY_ENABLE=1 packages/semitexa-update/tools/install-auto-deploy-systemd.sh /srv/semitexa/my-project
```

The systemd wrapper (`packages/semitexa-update/tools/run-auto-deploy-systemd.sh`) calls `update:packages:auto` and reruns `bin/semitexa server:start` when `restart_required=true`, then performs the configured HTTP healthcheck.

### Production deploy systemd units

The installer above provisions **two independent unit pairs**, decoupled on purpose:

| Unit | Purpose |
|---|---|
| `semitexa-auto-deploy.service` | Composer-updates `semitexa/*` and restarts the runtime when needed. |
| `semitexa-auto-deploy.timer` | Periodic poll (default `15m`, `RandomizedDelaySec=1m`). `update:packages:auto` short-circuits when nothing's new, so polling is cheap. |
| `semitexa-refresh-install-sh.service` | Atomically refreshes `packages/semitexa-ultimate/install.sh` from upstream `master`. |
| `semitexa-refresh-install-sh.timer` | Periodic refresh (default `10m`). **Independent of the deploy** — install.sh stays current even when the application deploy is failing. |

`semitexa-auto-deploy.service` runs `composer update 'semitexa/*' --with-all-dependencies --prefer-dist --no-dev --no-interaction --optimize-autoloader`. The `--prefer-dist` flag is mandatory: production `vendor/semitexa/*` is shipped as dist (no `.git/`), so without it composer may pick source mode for some packages and fail with `GitDownloader.php line 155: The .git directory is missing`.

#### Install / update on production

```bash
# Repo files come from packages/semitexa-update/{tools,resources}
sudo SEMITEXA_AUTO_DEPLOY_ENABLE=1 \
     packages/semitexa-update/tools/install-auto-deploy-systemd.sh /srv/semitexa/my-project

# After unit changes:
sudo systemctl daemon-reload
sudo systemctl enable --now semitexa-auto-deploy.timer
sudo systemctl enable --now semitexa-refresh-install-sh.timer
```

#### Verify

```bash
systemctl list-timers --all | grep semitexa
systemctl status semitexa-auto-deploy.timer --no-pager
systemctl status semitexa-refresh-install-sh.timer --no-pager
```

#### Manually trigger

```bash
# Run a deploy now (will no-op if nothing's new):
sudo systemctl start semitexa-auto-deploy.service

# Refresh install.sh now:
sudo systemctl start semitexa-refresh-install-sh.service
```

#### Inspect logs

```bash
journalctl -u semitexa-auto-deploy.service        -n 100 --no-pager
journalctl -u semitexa-refresh-install-sh.service -n 100 --no-pager

# Per-deploy structured logs (one JSON file per attempt):
ls -lt /srv/semitexa/my-project/var/log/deployments/ | head
```

#### Removing the legacy ExecStartPost drop-in

Hosts provisioned before the refresh-install-sh units existed may still carry
`/etc/systemd/system/semitexa-auto-deploy.service.d/10-refresh-install-sh.conf`,
which refreshed install.sh from inside `ExecStartPost`. Once the new
`semitexa-refresh-install-sh.timer` is enabled, that drop-in is redundant —
remove it so install.sh refresh stops being parasitic on a successful deploy:

```bash
sudo rm /etc/systemd/system/semitexa-auto-deploy.service.d/10-refresh-install-sh.conf
sudo rmdir --ignore-fail-on-non-empty /etc/systemd/system/semitexa-auto-deploy.service.d
sudo systemctl daemon-reload
```
