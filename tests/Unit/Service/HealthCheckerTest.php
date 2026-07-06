<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\HealthChecker;

final class HealthCheckerTest extends TestCase
{
    public function testReportsUnreachableUrl(): void
    {
        // Port 1 is never listening; the check must fail with a clear message.
        $check = (new HealthChecker())->check('http://127.0.0.1:1/health', timeoutSeconds: 2);

        self::assertFalse($check->ok);
        self::assertSame('http', $check->name);
        self::assertStringContainsString('Health check failed', $check->message);
    }
}
