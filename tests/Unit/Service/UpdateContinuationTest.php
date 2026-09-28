<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\UpdateContinuation;

final class UpdateContinuationTest extends TestCase
{
    private string $dir;
    private string|false $inheritedMarker;

    protected function setUp(): void
    {
        // Start from a known environment, and give back the one we found.
        $this->inheritedMarker = getenv(UpdateContinuation::MARKER);
        putenv(UpdateContinuation::MARKER);

        $this->dir = sys_get_temp_dir() . '/semitexa-continuation-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        putenv($this->inheritedMarker === false
            ? UpdateContinuation::MARKER
            : UpdateContinuation::MARKER . '=' . $this->inheritedMarker);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** A real child: it gets the same arguments, the marker, and its exit code comes back. */
    public function testRunsTheSameCommandInAFreshProcessCarryingTheMarker(): void
    {
        $script = $this->dir . '/child.php';
        $seen = $this->dir . '/seen.json';
        file_put_contents($script, sprintf(
            '<?php file_put_contents(%s, json_encode([getenv(%s), array_slice($argv, 1)])); exit(3);',
            var_export($seen, true),
            var_export(UpdateContinuation::MARKER, true),
        ));

        $exit = (new UpdateContinuation([$script, 'update', '--allow-destructive']))->run();

        self::assertSame(3, $exit);
        self::assertSame(['1', ['update', '--allow-destructive']], json_decode((string) file_get_contents($seen), true));
    }

    /** Inside a continuation the updater moving again must stop, not loop. */
    public function testIsNotPossibleInsideAContinuation(): void
    {
        putenv(UpdateContinuation::MARKER . '=1');

        self::assertFalse((new UpdateContinuation(['bin/semitexa', 'update']))->isPossible());
    }

    public function testIsNotPossibleWithoutACommandLine(): void
    {
        self::assertFalse((new UpdateContinuation([]))->isPossible());
        self::assertTrue((new UpdateContinuation(['bin/semitexa', 'update']))->isPossible());
    }
}
