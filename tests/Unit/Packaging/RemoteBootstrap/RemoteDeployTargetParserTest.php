<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\RemoteBootstrap;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support\RemoteDeployTargetParser;

final class RemoteDeployTargetParserTest extends TestCase
{
    public function testParsesCommaSeparatedTargets(): void
    {
        $targets = (new RemoteDeployTargetParser())->parseList('root@203.0.113.10, deploy@example.com');

        self::assertCount(2, $targets);
        self::assertSame('root@203.0.113.10', $targets[0]->toConnectionString());
        self::assertSame('deploy@example.com', $targets[1]->toConnectionString());
    }

    public function testRejectsInvalidTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RemoteDeployTargetParser())->parseOne('bad-target');
    }

    /** IPv6 targets were refused outright. */
    public function testParsesABracketedIpv6Target(): void
    {
        $target = (new RemoteDeployTargetParser())->parseOne('deploy@[2001:db8::10]');

        self::assertSame('2001:db8::10', $target->host);
        self::assertSame('deploy@[2001:db8::10]', $target->toConnectionString());
        self::assertSame('deploy@2001:db8::10', $target->sshDestination());
        self::assertSame('deploy@[2001:db8::10]', (new RemoteDeployTargetParser())->parseOne($target->toConnectionString())->toConnectionString(), 'what the settings store parses back');
    }

    public function testRejectsABracketedHostThatIsNotIpv6(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RemoteDeployTargetParser())->parseOne('deploy@[1.2.3]');
    }
}
