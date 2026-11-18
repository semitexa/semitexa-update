<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Scaffold;

use Semitexa\Update\Domain\Enum\ScaffoldFileCategory;
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
    /**
     * Beside the project in the framework's own workspace and in the release
     * clone. There the retired paths are the framework's sources, not copies.
     * A bare clone of semitexa-ultimate has no such marker (its .gitattributes
     * ships in the archive too): an update run there would remove its own CI
     * and tools, recoverable from git. Nothing runs one there today.
     */
    private const FRAMEWORK_CHECKOUT_MARKER = 'packages/semitexa-ultimate';

    public function __construct(
        private readonly ScaffoldHasher $hasher = new ScaffoldHasher(),
    ) {
    }

    public function plan(
        string $projectRoot,
        string $scaffoldRoot,
        ScaffoldManifest $manifest,
        ?\DateTimeImmutable $now = null,
        bool $writeCandidates = false,
    ): ScaffoldSyncPlan {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $integrityErrors = $this->verifyScaffoldIntegrity($scaffoldRoot, $manifest);

        $entries = [];
        foreach ($manifest->entries as $manifestEntry) {
            $entries[] = $this->classify($projectRoot, $manifestEntry, $now, $writeCandidates);
        }
        foreach ($this->classifyRetired($projectRoot, $manifest) as $retiredEntry) {
            $entries[] = $retiredEntry;
        }

        return new ScaffoldSyncPlan($entries, $integrityErrors);
    }

    /**
     * Files semitexa/ultimate shipped once and no longer does. A copy that is
     * byte for byte one of its versions is the framework's and goes; any other
     * is the project's and stays, with a note. A path the project lacks gives
     * no entry. Only a regular file reached without a link is read: a link at
     * the path or anywhere on the way to it is the project's own doing.
     *
     * @return list<ScaffoldSyncPlanEntry>
     */
    private function classifyRetired(string $projectRoot, ScaffoldManifest $manifest): array
    {
        if ($manifest->retired === [] || is_dir($projectRoot . '/' . self::FRAMEWORK_CHECKOUT_MARKER)) {
            return [];
        }
        $root = realpath($projectRoot);
        if ($root === false) {
            return [];
        }

        $entries = [];
        foreach ($manifest->retired as $path => $shipped) {
            $file = $projectRoot . '/' . $path;
            if (is_link($file) || !is_file($file)) {
                continue;
            }
            // The resolved path is the path itself: no link on the way, inside
            // the project or out of it.
            if (realpath($file) !== $root . '/' . $path) {
                continue;
            }
            $hash = $this->hasher->hashFile($file);
            $theFrameworks = in_array($hash, $shipped, true);
            $entries[] = new ScaffoldSyncPlanEntry(
                path: $path,
                status: ScaffoldSyncStatus::Retired,
                action: $theFrameworks ? ScaffoldSyncAction::Remove : ScaffoldSyncAction::ManualReview,
                oldHash: $hash,
                newHash: null,
                backupPath: null,
                newFilePath: null,
                preserveExecutable: false,
                critical: false,
                reason: $theFrameworks
                    ? 'semitexa/ultimate no longer ships it, and this copy is one of its versions: it will be removed.'
                    : 'Kept: semitexa/ultimate no longer ships it, and this copy differs from every version it shipped, so it is the project\'s. Delete it if nothing uses it.',
            );
        }

        return $entries;
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
        bool $writeCandidates,
    ): ScaffoldSyncPlanEntry {
        $projectFile = $projectRoot . '/' . $manifestEntry->path;

        if (!file_exists($projectFile)) {
            // A project that deleted an agent doc (AI_REFERENCE.md, a CLAUDE.md it
            // does not want) meant it: bringing it back on every update is noise.
            if ($manifestEntry->category === ScaffoldFileCategory::AgentDocs) {
                return new ScaffoldSyncPlanEntry(
                    path: $manifestEntry->path,
                    status: ScaffoldSyncStatus::Missing,
                    action: ScaffoldSyncAction::None,
                    oldHash: null,
                    newHash: $manifestEntry->currentSha256,
                    backupPath: null,
                    newFilePath: null,
                    preserveExecutable: $manifestEntry->preserveExecutable,
                    critical: $manifestEntry->critical,
                    reason: 'Agent doc absent in project — not recreated; the project removed it.',
                );
            }
            if ($manifestEntry->autoUpdate) {
                return new ScaffoldSyncPlanEntry(
                    path: $manifestEntry->path,
                    status: ScaffoldSyncStatus::Missing,
                    action: ScaffoldSyncAction::Create,
                    oldHash: null,
                    newHash: $manifestEntry->currentSha256,
                    backupPath: null,
                    newFilePath: null,
                    preserveExecutable: $manifestEntry->preserveExecutable,
                    critical: $manifestEntry->critical,
                    reason: 'Missing in project — scaffold provides it, auto_update=true.',
                );
            }
            return $this->candidateOrManualReview(
                manifestEntry: $manifestEntry,
                status: ScaffoldSyncStatus::Missing,
                projectHash: null,
                writeCandidates: $writeCandidates,
                manualReason: 'Missing in project — auto_update=false; live file left as-is. '
                    . 'Run `bin/semitexa update --write-scaffold-candidates` to generate '
                    . $manifestEntry->path . '.new.',
                candidateReason: 'Missing in project — auto_update=false; candidate written as .new.',
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
            return $this->candidateOrManualReview(
                manifestEntry: $manifestEntry,
                status: ScaffoldSyncStatus::KnownPrevious,
                projectHash: $projectHash,
                writeCandidates: $writeCandidates,
                manualReason: 'Matches a prior scaffold but auto_update=false; live file untouched. '
                    . 'Run `bin/semitexa update --write-scaffold-candidates` to generate '
                    . $manifestEntry->path . '.new.',
                candidateReason: 'Matches a prior scaffold but auto_update=false — candidate written as .new.',
            );
        }

        return $this->candidateOrManualReview(
            manifestEntry: $manifestEntry,
            status: ScaffoldSyncStatus::LocallyModified,
            projectHash: $projectHash,
            // An edited manual or skill still has to learn the new rules: the new
            // version always lands beside it as .new, never over the project's edits.
            writeCandidates: $writeCandidates || in_array($manifestEntry->category, [ScaffoldFileCategory::AgentDocs, ScaffoldFileCategory::AgentSkill], true),
            manualReason: 'Project file is locally modified — live file untouched. '
                . 'Run `bin/semitexa update --write-scaffold-candidates` to generate '
                . $manifestEntry->path . '.new.',
            candidateReason: 'Project file is locally modified — scaffold content written as .new; live file untouched.',
        );
    }

    private function candidateOrManualReview(
        ScaffoldFileEntry $manifestEntry,
        ScaffoldSyncStatus $status,
        ?string $projectHash,
        bool $writeCandidates,
        string $manualReason,
        string $candidateReason,
    ): ScaffoldSyncPlanEntry {
        if ($writeCandidates) {
            return new ScaffoldSyncPlanEntry(
                path: $manifestEntry->path,
                status: $status,
                action: ScaffoldSyncAction::WriteNew,
                oldHash: $projectHash,
                newHash: $manifestEntry->currentSha256,
                backupPath: null,
                newFilePath: $manifestEntry->path . '.new',
                preserveExecutable: $manifestEntry->preserveExecutable,
                critical: $manifestEntry->critical,
                reason: $candidateReason,
            );
        }
        return new ScaffoldSyncPlanEntry(
            path: $manifestEntry->path,
            status: $status,
            action: ScaffoldSyncAction::ManualReview,
            oldHash: $projectHash,
            newHash: $manifestEntry->currentSha256,
            backupPath: null,
            newFilePath: null,
            preserveExecutable: $manifestEntry->preserveExecutable,
            critical: $manifestEntry->critical,
            reason: $manualReason,
        );
    }

    private function backupPath(string $path, \DateTimeImmutable $now): string
    {
        return $path . '.bak.' . $now->format('Ymd\THis\Z');
    }
}
