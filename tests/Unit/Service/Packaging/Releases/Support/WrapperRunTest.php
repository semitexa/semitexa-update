<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Packaging\Releases\Support;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\WrapperRun;

/**
 * The systemd wrapper holds a shared lock while the deploy runs; the deploy
 * reads it to say who makes the restart. Anything else is "not the wrapper".
 */
final class WrapperRunTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-wrapper-run-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/var/lock', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectRoot));
    }

    public function testAHeldSharedLockIsTheWrapper(): void
    {
        $path = $this->projectRoot . '/' . WrapperRun::RELATIVE_PATH;
        touch($path);
        // As the wrapper holds it: `flock -s <file> <command>`, another process.
        $holder = proc_open(['flock', '-s', $path, 'sh', '-c', 'echo held; read _'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($holder);

        try {
            self::assertSame("held\n", fgets($pipes[1]));
            self::assertTrue(WrapperRun::holds($this->projectRoot));
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_close($holder);
        }

        self::assertFalse(WrapperRun::holds($this->projectRoot), 'the wrapper ended: the file stays, the lock does not');
    }

    public function testNoLockFileIsAManualRunAndCreatesNone(): void
    {
        self::assertFalse(WrapperRun::holds($this->projectRoot));
        self::assertFileDoesNotExist($this->projectRoot . '/' . WrapperRun::RELATIVE_PATH);
    }

    public function testAProjectWithoutVarIsAManualRun(): void
    {
        self::assertFalse(WrapperRun::holds($this->projectRoot . '/missing'));
    }
}
