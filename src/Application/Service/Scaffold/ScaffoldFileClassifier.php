<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Scaffold;

use Semitexa\Update\Domain\Enum\ScaffoldFileHashMatch;
use Semitexa\Update\Domain\Enum\ScaffoldSyncAction;
use Semitexa\Update\Domain\Enum\ScaffoldSyncStatus;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldFileEntry;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncPlan;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncPlanEntry;

/**
 * Builds a {@see ScaffoldSyncPlan} from a manifest + project root pair.
 *
 * The classifier never writes; it computes status + intended action per file
 * and verifies that the scaffold source itself is consistent with the manifest
 * (integrity check). Engine execution refuses if the integrity check fails.
 */
final class ScaffoldFileClassifier
{
    public function __construct(
        private readonly ScaffoldHasher $hasher = new ScaffoldHasher(),
    ) {
    }

    public function plan(
        string $projectRoot,
        string $scaffoldRoot,
        ScaffoldManifest $manifest,
        ?\DateTimeImmutable $now = null,
    ): ScaffoldSyncPlan {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $integrityErrors = $this->verifyScaffoldIntegrity($scaffoldRoot, $manifest);

        $entries = [];
        foreach ($manifest->entries as $manifestEntry) {
            $entries[] = $this->classify($projectRoot, $manifestEntry, $now);
        }

        return new ScaffoldSyncPlan($entries, $integrityErrors);
    }

    /**
     * @return list<string>
     */
    private function verifyScaffoldIntegrity(string $scaffoldRoot, ScaffoldManifest $manifest): array
    {
        $errors = [];
        foreach ($manifest->entries as $entry) {
            $absolute = $scaffoldRoot . '/' . $entry->path;
            if (!is_file($absolute)) {
                $errors[] = sprintf(
                    'Scaffold source missing for manifest entry "%s" at %s',
                    $entry->path,
                    $absolute,
                );
                continue;
            }
            $actual = $this->hasher->hashFile($absolute);
            if ($actual !== $entry->currentSha256) {
                $errors[] = sprintf(
                    'Scaffold source for "%s" hashes to %s but manifest current_sha256 is %s',
                    $entry->path,
                    $actual,
                    $entry->currentSha256,
                );
            }
        }
        return $errors;
    }

    private function classify(
        string $projectRoot,
        ScaffoldFileEntry $manifestEntry,
        \DateTimeImmutable $now,
    ): ScaffoldSyncPlanEntry {
        $projectFile = $projectRoot . '/' . $manifestEntry->path;

        if (!file_exists($projectFile)) {
            $action = $manifestEntry->autoUpdate
                ? ScaffoldSyncAction::Create
                : ScaffoldSyncAction::WriteNew;

            return new ScaffoldSyncPlanEntry(
                path: $manifestEntry->path,
                status: ScaffoldSyncStatus::Missing,
                action: $action,
                oldHash: null,
                newHash: $manifestEntry->currentSha256,
                backupPath: null,
                newFilePath: $action === ScaffoldSyncAction::WriteNew
                    ? $manifestEntry->path . '.new'
                    : null,
                preserveExecutable: $manifestEntry->preserveExecutable,
                critical: $manifestEntry->critical,
                reason: $action === ScaffoldSyncAction::Create
                    ? 'Missing in project — scaffold provides it, auto_update=true.'
                    : 'Missing in project — auto_update=false, candidate written as .new.',
            );
        }

        $projectHash = $this->hasher->hashFile($projectFile);
        $match = $manifestEntry->classifyHash($projectHash);

        if ($match === ScaffoldFileHashMatch::Current) {
            return new ScaffoldSyncPlanEntry(
                path: $manifestEntry->path,
                status: ScaffoldSyncStatus::Current,
                action: ScaffoldSyncAction::None,
                oldHash: $projectHash,
                newHash: $manifestEntry->currentSha256,
                backupPath: null,
                newFilePath: null,
                preserveExecutable: $manifestEntry->preserveExecutable,
                critical: $manifestEntry->critical,
                reason: 'Project file matches current scaffold hash.',
            );
        }

        if ($match === ScaffoldFileHashMatch::KnownPrevious) {
            if ($manifestEntry->autoUpdate) {
                return new ScaffoldSyncPlanEntry(
                    path: $manifestEntry->path,
                    status: ScaffoldSyncStatus::KnownPrevious,
                    action: ScaffoldSyncAction::Replace,
                    oldHash: $projectHash,
                    newHash: $manifestEntry->currentSha256,
                    backupPath: $this->backupPath($manifestEntry->path, $now),
                    newFilePath: null,
                    preserveExecutable: $manifestEntry->preserveExecutable,
                    critical: $manifestEntry->critical,
                    reason: 'Project file matches a known prior scaffold hash — safe to auto-update with backup.',
                );
            }
            return new ScaffoldSyncPlanEntry(
                path: $manifestEntry->path,
                status: ScaffoldSyncStatus::KnownPrevious,
                action: ScaffoldSyncAction::WriteNew,
                oldHash: $projectHash,
                newHash: $manifestEntry->currentSha256,
                backupPath: null,
                newFilePath: $manifestEntry->path . '.new',
                preserveExecutable: $manifestEntry->preserveExecutable,
                critical: $manifestEntry->critical,
                reason: 'Project file matches a prior scaffold but auto_update=false — candidate written as .new.',
            );
        }

        return new ScaffoldSyncPlanEntry(
            path: $manifestEntry->path,
            status: ScaffoldSyncStatus::LocallyModified,
            action: ScaffoldSyncAction::WriteNew,
            oldHash: $projectHash,
            newHash: $manifestEntry->currentSha256,
            backupPath: null,
            newFilePath: $manifestEntry->path . '.new',
            preserveExecutable: $manifestEntry->preserveExecutable,
            critical: $manifestEntry->critical,
            reason: 'Project file is locally modified — scaffold content written as .new; live file untouched.',
        );
    }

    private function backupPath(string $path, \DateTimeImmutable $now): string
    {
        return $path . '.bak.' . $now->format('Ymd\THis\Z');
    }
}
