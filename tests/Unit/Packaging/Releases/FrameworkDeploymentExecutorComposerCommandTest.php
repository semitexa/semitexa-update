<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentExecutor;

/**
 * Pins the production composer-update incantation so future edits cannot silently
 * regress the deployment behaviour. The flag set was chosen after the
 * 2026-04-26 production failure where composer chose source mode for
 * vendor/semitexa/* packages that had been installed from dist (no .git/),
 * triggering "GitDownloader.php line 155: The .git directory is missing".
 */
final class FrameworkDeploymentExecutorComposerCommandTest extends TestCase
{
    public function testComposerUpdateCommandIncludesPreferDist(): void
    {
        $command = $this->buildCommand();

        self::assertStringContainsString(
            '--prefer-dist',
            $command,
            'Production composer update must run in dist mode to avoid GitDownloader source-mode failures.',
        );
    }

    public function testComposerUpdateCommandTargetsSemitexaWildcardWithExpectedFlags(): void
    {
        $command = $this->buildCommand();

        self::assertStringContainsString("'semitexa/*'", $command);
        self::assertStringContainsString('--with-all-dependencies', $command);
        self::assertStringContainsString('--no-dev', $command);
        self::assertStringContainsString('--no-interaction', $command);
        self::assertStringContainsString('--optimize-autoloader', $command);
    }

    public function testComposerUpdateCommandDoesNotForceSourceMode(): void
    {
        $command = $this->buildCommand();

        self::assertStringNotContainsString('--prefer-source', $command);
    }

    private function buildCommand(): string
    {
        $executor = new FrameworkDeploymentExecutor();
        $method = new ReflectionMethod($executor, 'composerUpdateCommand');
        $method->setAccessible(true);

        return (string) $method->invoke($executor, sys_get_temp_dir());
    }
}
