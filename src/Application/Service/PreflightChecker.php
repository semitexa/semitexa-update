<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Update\Domain\Model\PreflightCheck;
use Semitexa\Update\Domain\Model\PreflightReport;

/**
 * Read-only environment verification before any mutating update stage.
 * Catches the failure modes that would otherwise strand a run half-way:
 * unreachable database, a disk too full for composer/vendor churn,
 * incoherent composer files, an unwritable var/ directory.
 */
final class PreflightChecker
{
    private const MIN_FREE_DISK_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly string $projectRoot,
        private readonly int $minFreeDiskBytes = self::MIN_FREE_DISK_BYTES,
    ) {
    }

    public function check(): PreflightReport
    {
        return new PreflightReport([
            $this->checkDatabase(),
            $this->checkDiskSpace(),
            $this->checkComposerFiles(),
            $this->checkVarWritable(),
        ]);
    }

    private function checkDatabase(): PreflightCheck
    {
        try {
            $this->adapter->query('SELECT 1');
            return new PreflightCheck('database', true, 'Database reachable.');
        } catch (\Throwable $e) {
            return new PreflightCheck(
                'database',
                false,
                'Database unreachable: ' . $e->getMessage()
                . ' — patches and schema sync cannot run; fix connectivity first.',
            );
        }
    }

    private function checkDiskSpace(): PreflightCheck
    {
        $free = @disk_free_space($this->projectRoot);
        if ($free === false) {
            return new PreflightCheck('disk-space', true, 'Free disk space could not be determined; proceeding.');
        }

        if ($free < $this->minFreeDiskBytes) {
            return new PreflightCheck('disk-space', false, sprintf(
                'Only %d MB free at %s (minimum %d MB) — composer/vendor churn would likely fail half-way. Free up disk first.',
                (int) ($free / 1048576),
                $this->projectRoot,
                (int) ($this->minFreeDiskBytes / 1048576),
            ));
        }

        return new PreflightCheck('disk-space', true, sprintf('%d MB free.', (int) ($free / 1048576)));
    }

    private function checkComposerFiles(): PreflightCheck
    {
        $jsonPath = $this->projectRoot . '/composer.json';
        if (!is_file($jsonPath)) {
            return new PreflightCheck('composer-files', false, sprintf('composer.json not found at %s.', $jsonPath));
        }
        if (json_decode((string) file_get_contents($jsonPath), true) === null) {
            return new PreflightCheck('composer-files', false, 'composer.json is not valid JSON — fix it before updating.');
        }

        $lockPath = $this->projectRoot . '/composer.lock';
        if (is_file($lockPath) && json_decode((string) file_get_contents($lockPath), true) === null) {
            return new PreflightCheck('composer-files', false, 'composer.lock is not valid JSON — restore it (git checkout composer.lock or composer update) before updating.');
        }

        return new PreflightCheck('composer-files', true, 'composer.json/lock coherent.');
    }

    private function checkVarWritable(): PreflightCheck
    {
        $var = $this->projectRoot . '/var';
        if (!is_dir($var) && !@mkdir($var, 0775, true) && !is_dir($var)) {
            return new PreflightCheck('var-writable', false, sprintf('var/ does not exist and cannot be created at %s.', $var));
        }
        if (!is_writable($var)) {
            return new PreflightCheck('var-writable', false, sprintf('var/ is not writable at %s — locks, backups and logs need it.', $var));
        }

        return new PreflightCheck('var-writable', true, 'var/ writable.');
    }
}
