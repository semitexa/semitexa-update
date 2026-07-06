<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Service\PreflightChecker;
use Semitexa\Update\Domain\Model\PreflightCheck;

final class PreflightCheckerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-preflight-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, 0777, true);
        file_put_contents($this->projectRoot . '/composer.json', '{"name":"acme/app"}');
        file_put_contents($this->projectRoot . '/composer.lock', '{"packages":[]}');
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot . '/composer.json');
        @unlink($this->projectRoot . '/composer.lock');
        @rmdir($this->projectRoot . '/var');
        @rmdir($this->projectRoot);
    }

    public function testAllChecksPassOnHealthyEnvironment(): void
    {
        $checker = new PreflightChecker(new SqliteAdapter('sqlite::memory:'), $this->projectRoot);

        $report = $checker->check();

        self::assertTrue($report->isSuccess(), $this->describe($report->failedChecks()));
        self::assertCount(4, $report->checks);
    }

    public function testUnreachableDatabaseFails(): void
    {
        $broken = new SqliteAdapter('sqlite:/nonexistent-' . bin2hex(random_bytes(4)) . '/x.db');
        $checker = new PreflightChecker($broken, $this->projectRoot);

        $report = $checker->check();

        self::assertFalse($report->isSuccess());
        $names = array_map(static fn (PreflightCheck $c): string => $c->name, $report->failedChecks());
        self::assertContains('database', $names);
    }

    public function testInsufficientDiskSpaceFails(): void
    {
        $checker = new PreflightChecker(
            new SqliteAdapter('sqlite::memory:'),
            $this->projectRoot,
            minFreeDiskBytes: PHP_INT_MAX,
        );

        $report = $checker->check();

        $names = array_map(static fn (PreflightCheck $c): string => $c->name, $report->failedChecks());
        self::assertContains('disk-space', $names);
    }

    public function testCorruptComposerJsonFails(): void
    {
        file_put_contents($this->projectRoot . '/composer.json', '{not json');
        $checker = new PreflightChecker(new SqliteAdapter('sqlite::memory:'), $this->projectRoot);

        $report = $checker->check();

        $names = array_map(static fn (PreflightCheck $c): string => $c->name, $report->failedChecks());
        self::assertContains('composer-files', $names);
    }

    /**
     * @param list<PreflightCheck> $failed
     */
    private function describe(array $failed): string
    {
        return implode('; ', array_map(
            static fn (PreflightCheck $c): string => $c->name . ': ' . $c->message,
            $failed,
        ));
    }
}
