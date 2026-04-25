<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\RemoteBootstrap;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteOsReleaseParser;

final class RemoteOsReleaseParserTest extends TestCase
{
    public function testParsesOsReleaseContent(): void
    {
        $info = (new RemoteOsReleaseParser())->parse(<<<TEXT
ID=ubuntu
VERSION_ID="22.04"
PRETTY_NAME="Ubuntu 22.04.5 LTS"
TEXT);

        self::assertSame('ubuntu', $info->id);
        self::assertSame('22.04', $info->versionId);
        self::assertSame('Ubuntu 22.04.5 LTS', $info->prettyName);
    }
}
