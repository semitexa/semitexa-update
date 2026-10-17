<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Console\Command\UpdateScaffoldRebuildCommand;

/**
 * The agent docs the update scaffold ships are the ones semitexa/ultimate
 * publishes — beside this package, or as the project it is installed in. Not behind the installer check of ScaffoldSourceParityTest: the
 * docs have their own source, and a gate that cannot read a file must fail,
 * not skip, so an unreadable or missing doc is a failure here.
 */
final class AgentDocsParityTest extends TestCase
{
    public function testEveryShippedAgentDocIsUltimatesByteForByte(): void
    {
        $package = dirname(__DIR__, 4);
        $copy = $package . '/resources/scaffold';
        $source = $this->ultimate($package);

        $manifest = json_decode((string) @file_get_contents($package . '/resources/scaffold-manifest.json'), true);
        $recorded = [];
        foreach (is_array($manifest) ? (array) ($manifest['files'] ?? []) : [] as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null)) {
                $recorded[$entry['path']] = $entry['current_sha256'] ?? null;
            }
        }

        $mismatched = [];
        foreach (UpdateScaffoldRebuildCommand::AGENT_DOCS as $doc) {
            $shipped = @hash_file('sha256', $copy . '/' . $doc);
            // Without ultimate beside it (a standalone checkout) the copy is held
            // to the manifest that ships with it: still a check, never a skip.
            $expected = $source !== null ? @hash_file('sha256', $source . '/' . $doc) : ($recorded[$doc] ?? false);
            if ($shipped === false || $expected === false || $expected === null || $shipped !== $expected) {
                $mismatched[] = $doc;
            }
        }
        self::assertSame([], $mismatched, 'Agent docs missing, unreadable or diverged from ' . ($source ?? 'the shipped manifest') . ': run bin/semitexa update:scaffold:rebuild.');
    }

    /** semitexa/ultimate as a sibling package (monorepo, vendor) or as the project itself. */
    private function ultimate(string $package): ?string
    {
        foreach ([dirname($package) . '/semitexa-ultimate', dirname($package) . '/ultimate'] as $dir) {
            if (is_file($dir . '/AGENTS.md')) {
                return $dir;
            }
        }
        $project = dirname($package, 3);
        $composer = json_decode((string) @file_get_contents($project . '/composer.json'), true);

        return is_array($composer) && ($composer['name'] ?? null) === 'semitexa/ultimate' && is_file($project . '/AGENTS.md') ? $project : null;
    }
}
