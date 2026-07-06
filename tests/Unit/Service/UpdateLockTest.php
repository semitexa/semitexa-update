<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\UpdateLock;

final class UpdateLockTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-lock-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot . '/var/lock/semitexa-update.lock');
        @rmdir($this->projectRoot . '/var/lock');
        @rmdir($this->projectRoot . '/var');
        @rmdir($this->projectRoot);
    }

    public function testSecondAcquireFromAnotherHandleFails(): void
    {
        $first = new UpdateLock($this->projectRoot);
        $second = new UpdateLock($this->projectRoot);

        self::assertTrue($first->acquire('cli:first'));
        self::assertFalse($second->acquire('auto-deploy'));

        $description = $second->holderDescription();
        self::assertStringContainsString('cli:first', $description);
        self::assertStringContainsString('pid', $description);
    }

    public function testReleaseAllowsReacquire(): void
    {
        $first = new UpdateLock($this->projectRoot);
        $second = new UpdateLock($this->projectRoot);

        self::assertTrue($first->acquire('cli:first'));
        $first->release();

        self::assertTrue($second->acquire('cli:second'));
        $second->release();
    }

    public function testAcquireIsIdempotentForTheSameInstance(): void
    {
        $lock = new UpdateLock($this->projectRoot);

        self::assertTrue($lock->acquire('cli:me'));
        self::assertTrue($lock->acquire('cli:me'));
        $lock->release();
    }
}
