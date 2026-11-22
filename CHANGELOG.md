# Changelog

All notable changes to `semitexa/update`. Sections are `## <version> — <date>`
(newest first); `## Unreleased` collects changes until the next release tag.

This file is machine-read by `update:changelog` and the OS "What's new"
surface — keep entries short and operator-facing.

## Unreleased

### Fixed
- The auto-deploy report no longer says "operator restart required" for a restart its systemd wrapper makes. The wrapper holds `var/lock/semitexa-auto-deploy-wrapper.lock` while `update:packages:auto` runs, and the report then says the wrapper restarts the stack; a run by hand reports as before. A host with an older copy of `tools/run-auto-deploy-systemd.sh` keeps the old words until the script is installed again (`tools/install-auto-deploy-systemd.sh`).

## 2026.11.21.1434 — 2026-11-21

### Added
- **An update stops when the new code needs a setting `.env` does not have.** `bin/semitexa update` runs a `required-settings` stage after composer and before the scaffold, the schema and the patches (under `--no-composer` too). It asks `system:doctor` of the installed code; a failing check that names a setting, such as `APP_SECRET`, ends the update with exit 1 and prints the setting, the check, the `APP_ENV` judged and the fix. Other failing checks stop nothing. The auto-deploy asks the same before `orm:sync`: a missing setting rolls the code back, and the release is tried again once `.env` changes (a setting given only through the process environment: delete `var/update/rollback/blocked.json`).

### Fixed
- `bin/semitexa update` no longer tells a project to `composer require` a skeleton package that vendor already holds as a dependency of another one (semitexa/installer, which arrives with semitexa/update). The advisory now prints once, after the stages, in the process that finishes the run. It used to print with the plan, before composer had installed the package, and again in the continuation process.

## 2026.11.20.0947 — 2026-11-20

### Changed
- **The scaffold comes from `semitexa/installer`.** `bin/semitexa update` takes
  `bin/semitexa`, the Dockerfile and the Compose files from the installer,
  which Composer now installs with this package; this package keeps only the
  agents' docs. A project file that still matches a shipped version is
  replaced as before, and an edited one is kept.
- **A release of the installer alone reaches every project.** A package the
  project gets only through another one, installed below the version the
  release set pins, now runs composer.
- **A scaffold manifest the update cannot read no longer stops it before
  composer.** The scaffold stage refuses it, and composer can still bring a
  version that reads it.

## 2026.10.09.0942 — 2026-10-09

### Added
- **`update` and `update:packages:auto` record the installed release**: the
  newest `semitexa/ultimate` release whose pins vendor/ meets, read from
  vendor/ rather than the lock. Footers name it through
  `semitexa_version()`. The record is dropped when vendor/ holds no whole
  release and kept when Packagist cannot be asked. `update:packages:auto
  --json` reports it as `release_set`.

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
