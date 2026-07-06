<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Packaging\Releases\Source;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\Releases\Source\PrivateGitTagSource;

/**
 * A failed upstream probe must surface as a warning, never silently as
 * "no update available".
 */
final class ReleaseSourceWarningsTest extends TestCase
{
    public function testFailedGitLsRemoteProducesAWarning(): void
    {
        $source = new PrivateGitTagSource();
        $source->resetWarnings();

        $tag = $source->latestStableTag('/nonexistent-repo-' . bin2hex(random_bytes(4)) . '.git');

        self::assertNull($tag);
        $warnings = $source->lastWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('git ls-remote failed', $warnings[0]);
        self::assertStringContainsString('may hide a newer release', $warnings[0]);
    }

    public function testResetWarningsClearsState(): void
    {
        $source = new PrivateGitTagSource();
        $source->latestStableTag('/nonexistent-repo-' . bin2hex(random_bytes(4)) . '.git');
        self::assertNotSame([], $source->lastWarnings());

        $source->resetWarnings();
        self::assertSame([], $source->lastWarnings());
    }
}
