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

    /** The message lands in the systemd journal, the deployment log and the run journal. */
    public function testTheMessageNamesTheUrlWithoutCredentialsOrQuery(): void
    {
        $check = (new HealthChecker())->check('http://user:secret@127.0.0.1:1/health?token=abc#x', timeoutSeconds: 2);

        self::assertStringContainsString('http://127.0.0.1:1/health', $check->message);
        self::assertStringNotContainsString('secret', $check->message);
        self::assertStringNotContainsString('token', $check->message);
        self::assertSame('HTTPS://h/x', HealthChecker::displayUrl('HTTPS://u:p@h/x?t=1'));
        self::assertSame('http://h/', HealthChecker::displayUrl('http://u:p#ss@h/'), 'a # in the password');
        self::assertSame('http://h/', HealthChecker::displayUrl('http://u:p@ss@h/'), 'an @ in the password');
    }
}
