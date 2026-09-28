# Changelog

All notable changes to `semitexa/update`. Sections are `## <version> — <date>`
(newest first); `## Unreleased` collects changes until the next release tag.
This file is machine-read by `update:changelog` and the OS "What's new"
surface — keep entries short and operator-facing.

## 2026.09.28.0838 — 2026-09-28

### Fixed
- `update` no longer fails when a release does not re-tag `semitexa/update`. It used
  that package's latest tag as the "release set", aimed half the pins at a stale
  version, and the set could not resolve. The set is now the pins of the latest
  `semitexa/ultimate` release.
- A pin is never lowered. An exact pin that is not on Packagist (a private
  package) no longer blocks the update.
- A failed `composer update` restores composer.json and composer.lock, and
  vendor/ too if composer had already moved it. The project is left exactly as
  it was.
- `--dry-run` asks composer whether the plan resolves, instead of reporting
  success for a set composer will refuse.
- After upgrading itself, `update` continues in a fresh process instead of
  stopping with "Update completed." while half the stages never ran.
- A project spanning several release dates is no longer flagged, and no longer
  runs composer on every update. That is the normal shape of an up-to-date set.

### Added
- **Run journal** (`platform_update_run_journal`): every mutating `update` run and
  every auto-deploy attempt is recorded with stages, package version deltas,
  actor, outcome, and duration.
- `update:history` — browse past runs; `--id=<run>` shows stages and package
  changes of one run.
- `update:changelog` — package version changes applied here + available
  upstream, with release-note sections from per-package CHANGELOG.md files.
- **Preflight stage** before any mutating update: database reachable, disk
  space, composer.json/lock coherence, var/ writable.
- **Run lock** (`var/lock/semitexa-update.lock`) shared by `update` and the
  auto-deploy pipeline: concurrent runs fail fast instead of racing.
- **Post-update health check**: mandatory when a health URL is configured
  (`SEMITEXA_UPDATE_HEALTHCHECK_URL`, fallback
  `SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL`) in both the manual sweep and
  auto-deploy.
- **Auto-deploy rollback**: composer.json/lock are snapshotted before
  `composer update`; on a later failure the dependency state is restored and
  vendor reinstalled.
- Release discovery failures (unreachable Packagist, failed `git ls-remote`)
  now surface as warnings instead of silently reporting "no update".

### Changed
- `update:status` now shows the updater version, the installed release set,
  and the last recorded run.
- Read-only commands (`update:plan`, `update:status`, `--dry-run`) no longer
  create journal tables; tables are created by the first mutating run.
