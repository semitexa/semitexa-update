<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\RemoteBootstrap;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteDeployTargetParser;

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
}
