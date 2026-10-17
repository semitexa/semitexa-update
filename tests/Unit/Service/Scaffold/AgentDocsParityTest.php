<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Console\Command\UpdateScaffoldRebuildCommand;

/**
 * The agent docs the update scaffold ships are the ones semitexa/ultimate
 * publishes. Not behind the installer check of ScaffoldSourceParityTest: the
 * docs have their own source, and a gate that cannot read a file must fail,
 * not skip, so an unreadable or missing doc is a failure here.
 */
final class AgentDocsParityTest extends TestCase
{
    public function testEveryShippedAgentDocIsUltimatesByteForByte(): void
    {
        $package = dirname(__DIR__, 4);
        $copy = $package . '/resources/scaffold';
        // dev monorepo: packages/semitexa-ultimate; vendor: semitexa/ultimate
        $sources = array_values(array_filter(
            [dirname($package) . '/semitexa-ultimate', dirname($package) . '/ultimate'],
            static fn (string $dir): bool => is_file($dir . '/AGENTS.md'),
        ));
        self::assertNotSame([], $sources, 'semitexa/ultimate, the agent docs\' source, is not beside this package');

        $mismatched = [];
        foreach (UpdateScaffoldRebuildCommand::AGENT_DOCS as $doc) {
            $shipped = @hash_file('sha256', $copy . '/' . $doc);
            $source = @hash_file('sha256', $sources[0] . '/' . $doc);
            if ($shipped === false || $source === false || $shipped !== $source) {
                $mismatched[] = $doc;
            }
        }
        self::assertSame([], $mismatched, 'Agent docs missing, unreadable or diverged from semitexa/ultimate: run bin/semitexa update:scaffold:rebuild.');
    }
}
