<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Scaffold;

use Semitexa\Update\Domain\Enum\ScaffoldSyncAction;
use Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncPlan;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncPlanEntry;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncReport;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncResult;

/**
 * Executes a {@see ScaffoldSyncPlan} against a project root.
 *
 * Safety guarantees enforced here:
 *   - Refuses to execute if the plan carries integrity errors.
 *   - Never overwrites a locally modified file — writes <path>.new instead.
 *   - Always writes a backup before Replace.
 *   - Preserves directory structure when creating files.
 *   - When preserve_executable is set, chmods +x on Create and Replace.
 *   - Idempotent: a second run after a clean Apply observes Current state
 *     and produces NoOp results.
 *   - Dry-run performs no filesystem mutations.
 */
final class ScaffoldSyncEngine
{
    public function __construct(
        private readonly ScaffoldHasher $hasher = new ScaffoldHasher(),
    ) {
    }

    public function execute(
        ScaffoldSyncPlan $plan,
        string $projectRoot,
        string $scaffoldRoot,
        bool $dryRun = false,
        ?\DateTimeImmutable $now = null,
    ): ScaffoldSyncReport {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($plan->hasIntegrityFailure()) {
            $results = [];
            foreach ($plan->entries as $entry) {
                $results[] = $this->skippedDueToIntegrity($entry);
            }
            return new ScaffoldSyncReport($results, $dryRun, $plan->integrityErrors);
        }

        $results = [];
        foreach ($plan->entries as $entry) {
            $results[] = $this->executeEntry($entry, $projectRoot, $scaffoldRoot, $dryRun, $now);
        }
        return new ScaffoldSyncReport($results, $dryRun, []);
    }

    private function executeEntry(
        ScaffoldSyncPlanEntry $entry,
        string $projectRoot,
        string $scaffoldRoot,
        bool $dryRun,
        \DateTimeImmutable $now,
    ): ScaffoldSyncResult {
        try {
            return match ($entry->action) {
                ScaffoldSyncAction::None     => $this->noOpResult($entry),
                ScaffoldSyncAction::Replace  => $this->doReplace($entry, $projectRoot, $scaffoldRoot, $dryRun),
                ScaffoldSyncAction::Create   => $this->doCreate($entry, $projectRoot, $scaffoldRoot, $dryRun),
                ScaffoldSyncAction::WriteNew => $this->doWriteNew($entry, $projectRoot, $scaffoldRoot, $dryRun, $now),
            };
        } catch (\Throwable $e) {
            return new ScaffoldSyncResult(
                path: $entry->path,
                status: $entry->status,
                action: $entry->action,
                outcome: ScaffoldSyncOutcome::Failed,
                oldHash: $entry->oldHash,
                newHash: $entry->newHash,
                backupPath: null,
                newFilePath: null,
                message: 'Failed: ' . $e->getMessage(),
            );
        }
    }

    private function noOpResult(ScaffoldSyncPlanEntry $entry): ScaffoldSyncResult
    {
        return new ScaffoldSyncResult(
            path: $entry->path,
            status: $entry->status,
            action: ScaffoldSyncAction::None,
            outcome: ScaffoldSyncOutcome::NoOp,
            oldHash: $entry->oldHash,
            newHash: $entry->newHash,
            backupPath: null,
            newFilePath: null,
            message: $entry->reason,
        );
    }

    private function doReplace(
        ScaffoldSyncPlanEntry $entry,
        string $projectRoot,
        string $scaffoldRoot,
        bool $dryRun,
    ): ScaffoldSyncResult {
        $projectFile = $projectRoot . '/' . $entry->path;
        $scaffoldFile = $scaffoldRoot . '/' . $entry->path;
        $backupAbsolute = $projectRoot . '/' . $entry->backupPath;

        if ($dryRun) {
            return $this->wouldApplyResult($entry, sprintf(
                'Would replace %s (backup %s).',
                $entry->path,
                $entry->backupPath,
            ));
        }

        $this->ensureParentDir($backupAbsolute);
        if (!@copy($projectFile, $backupAbsolute)) {
            throw new \RuntimeException("Unable to write backup at {$backupAbsolute}");
        }
        $this->writeAtomic($projectFile, $this->readScaffold($scaffoldFile));
        if ($entry->preserveExecutable) {
            @chmod($projectFile, 0755);
        }

        return new ScaffoldSyncResult(
            path: $entry->path,
            status: $entry->status,
            action: ScaffoldSyncAction::Replace,
            outcome: ScaffoldSyncOutcome::Applied,
            oldHash: $entry->oldHash,
            newHash: $entry->newHash,
            backupPath: $entry->backupPath,
            newFilePath: null,
            message: 'Replaced; previous content saved to backup.',
        );
    }

    private function doCreate(
        ScaffoldSyncPlanEntry $entry,
        string $projectRoot,
        string $scaffoldRoot,
        bool $dryRun,
    ): ScaffoldSyncResult {
        $projectFile = $projectRoot . '/' . $entry->path;
        $scaffoldFile = $scaffoldRoot . '/' . $entry->path;

        if ($dryRun) {
            return $this->wouldApplyResult($entry, sprintf('Would create %s.', $entry->path));
        }

        $this->ensureParentDir($projectFile);
        $this->writeAtomic($projectFile, $this->readScaffold($scaffoldFile));
        if ($entry->preserveExecutable) {
            @chmod($projectFile, 0755);
        }

        return new ScaffoldSyncResult(
            path: $entry->path,
            status: $entry->status,
            action: ScaffoldSyncAction::Create,
            outcome: ScaffoldSyncOutcome::Applied,
            oldHash: null,
            newHash: $entry->newHash,
            backupPath: null,
            newFilePath: null,
            message: 'Created from current scaffold.',
        );
    }

    private function doWriteNew(
        ScaffoldSyncPlanEntry $entry,
        string $projectRoot,
        string $scaffoldRoot,
        bool $dryRun,
        \DateTimeImmutable $now,
    ): ScaffoldSyncResult {
        $scaffoldFile = $scaffoldRoot . '/' . $entry->path;
        $scaffoldBytes = $this->readScaffold($scaffoldFile);
        $newPath = $entry->newFilePath ?? ($entry->path . '.new');
        $newAbsolute = $projectRoot . '/' . $newPath;

        if (file_exists($newAbsolute)) {
            $existingHash = $this->hasher->hashFile($newAbsolute);
            if ($existingHash !== $entry->newHash) {
                $newPath = $entry->path . '.new.' . $now->format('Ymd\THis\Z');
                $newAbsolute = $projectRoot . '/' . $newPath;
            }
        }

        if ($dryRun) {
            return $this->wouldApplyResult(
                $entry,
                sprintf('Would write %s for manual review.', $newPath),
                newFilePath: $newPath,
            );
        }

        $this->ensureParentDir($newAbsolute);
        $this->writeAtomic($newAbsolute, $scaffoldBytes);

        return new ScaffoldSyncResult(
            path: $entry->path,
            status: $entry->status,
            action: ScaffoldSyncAction::WriteNew,
            outcome: ScaffoldSyncOutcome::Applied,
            oldHash: $entry->oldHash,
            newHash: $entry->newHash,
            backupPath: null,
            newFilePath: $newPath,
            message: 'Live file untouched. Current scaffold written to ' . $newPath . ' for manual review.',
        );
    }

    private function wouldApplyResult(
        ScaffoldSyncPlanEntry $entry,
        string $message,
        ?string $newFilePath = null,
    ): ScaffoldSyncResult {
        return new ScaffoldSyncResult(
            path: $entry->path,
            status: $entry->status,
            action: $entry->action,
            outcome: ScaffoldSyncOutcome::WouldApply,
            oldHash: $entry->oldHash,
            newHash: $entry->newHash,
            backupPath: $entry->backupPath,
            newFilePath: $newFilePath ?? $entry->newFilePath,
            message: $message,
        );
    }

    private function skippedDueToIntegrity(ScaffoldSyncPlanEntry $entry): ScaffoldSyncResult
    {
        return new ScaffoldSyncResult(
            path: $entry->path,
            status: $entry->status,
            action: $entry->action,
            outcome: ScaffoldSyncOutcome::Skipped,
            oldHash: $entry->oldHash,
            newHash: $entry->newHash,
            backupPath: null,
            newFilePath: null,
            message: 'Scaffold integrity check failed — refusing to mutate.',
        );
    }

    private function readScaffold(string $absolute): string
    {
        $bytes = @file_get_contents($absolute);
        if ($bytes === false) {
            throw new \RuntimeException("Unable to read scaffold source: {$absolute}");
        }
        return $bytes;
    }

    private function writeAtomic(string $target, string $bytes): void
    {
        $this->ensureParentDir($target);
        $tmp = $target . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new \RuntimeException("Unable to write file: {$target}");
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to rename {$tmp} into place at {$target}");
        }
    }

    private function ensureParentDir(string $absolute): void
    {
        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create directory: {$dir}");
        }
    }
}
