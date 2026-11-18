<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Console\Command\UpdateCommand;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldFileClassifier;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestBuilder;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldSyncEngine;
use Semitexa\Update\Domain\Enum\ScaffoldSyncAction;
use Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome;
use Semitexa\Update\Domain\Enum\ScaffoldSyncStatus;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncPlanEntry;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncReport;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * tk-update-removes-retired-scaffold-files: a project made from an older
 * semitexa/ultimate kept the framework's own files (its CI, its maintainer
 * tools, its installer and security policy, the skeleton's tests, the retired
 * init command) after the framework stopped shipping them, and the skeleton's
 * tests went red there after every update that changed what they check.
 * `bin/semitexa update` removes a copy that is byte for byte a version the
 * framework shipped and keeps any other, with a note.
 */
final class UpdateRemovesRetiredScaffoldFilesTest extends TestCase
{
    private const V1 = "#!/bin/sh\necho 'install semitexa, v1'\n";
    private const V2 = "#!/bin/sh\necho 'install semitexa, v2'\n";

    private string $base;
    private string $project;
    private string $scaffold;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/semitexa-retired-' . bin2hex(random_bytes(8));
        $this->project = $this->base . '/project';
        $this->scaffold = $this->base . '/scaffold';
        mkdir($this->project, 0777, true);
        mkdir($this->scaffold, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->base);
    }

    #[Test]
    public function an_untouched_retired_file_is_removed_and_the_report_says_so(): void
    {
        $this->writeProject('install.sh', self::V1);

        $entry = $this->planEntry('install.sh');
        self::assertSame(ScaffoldSyncStatus::Retired, $entry->status);
        self::assertSame(ScaffoldSyncAction::Remove, $entry->action);

        $result = $this->execute()->resultByPath('install.sh');
        self::assertNotNull($result);
        self::assertSame(ScaffoldSyncOutcome::Applied, $result->outcome);
        self::assertStringStartsWith('Removed: ', $result->message);
        self::assertFileDoesNotExist($this->project . '/install.sh');
    }

    #[Test]
    public function an_edited_retired_file_stays_with_a_note(): void
    {
        $edited = self::V2 . "echo 'and a step of our own'\n";
        $this->writeProject('install.sh', $edited);

        $entry = $this->planEntry('install.sh');
        self::assertSame(ScaffoldSyncAction::ManualReview, $entry->action);
        self::assertStringStartsWith('Kept: ', $entry->reason);

        $result = $this->execute()->resultByPath('install.sh');
        self::assertNotNull($result);
        self::assertSame(ScaffoldSyncOutcome::ManualReview, $result->outcome);
        self::assertSame($edited, file_get_contents($this->project . '/install.sh'), 'byte for byte as the project left it');
    }

    #[Test]
    public function a_projects_own_file_of_the_same_name_stays(): void
    {
        $policy = "# Security policy\n\nReport to security@example.test.\n";
        $this->writeProject('SECURITY.md', $policy);

        $entry = $this->planEntry('SECURITY.md');
        self::assertSame(ScaffoldSyncAction::ManualReview, $entry->action);

        $this->execute();
        self::assertSame($policy, file_get_contents($this->project . '/SECURITY.md'));
    }

    #[Test]
    public function nothing_is_removed_beside_the_frameworks_own_checkout(): void
    {
        // The framework's workspace and the release clone: there these paths are the sources.
        mkdir($this->project . '/packages/semitexa-ultimate', 0777, true);
        $this->writeProject('install.sh', self::V1);

        self::assertSame([], $this->plan()->entries);
        $this->execute();
        self::assertSame(self::V1, file_get_contents($this->project . '/install.sh'));
    }

    #[Test]
    public function a_dry_run_removes_nothing(): void
    {
        $this->writeProject('install.sh', self::V1);

        $result = $this->execute(dryRun: true)->resultByPath('install.sh');

        self::assertNotNull($result);
        self::assertSame(ScaffoldSyncOutcome::WouldApply, $result->outcome);
        self::assertStringStartsWith('Would remove install.sh: ', $result->message);
        self::assertSame(self::V1, file_get_contents($this->project . '/install.sh'));
    }

    #[Test]
    public function a_link_at_a_retired_path_is_left_alone(): void
    {
        $this->writeProject('kept/install.sh', self::V1);
        symlink($this->project . '/kept/install.sh', $this->project . '/install.sh');

        self::assertSame([], $this->plan()->entries);
        $this->execute();
        self::assertTrue(is_link($this->project . '/install.sh'));
        self::assertSame(self::V1, file_get_contents($this->project . '/kept/install.sh'));
    }

    #[Test]
    public function a_retired_path_through_a_directory_that_leads_out_of_the_project_is_left_alone(): void
    {
        mkdir($this->base . '/shared-tools', 0777, true);
        file_put_contents($this->base . '/shared-tools/stack-smoke.sh', self::V1);
        symlink($this->base . '/shared-tools', $this->project . '/tools');

        self::assertSame([], $this->plan(['tools/stack-smoke.sh' => [hash('sha256', self::V1)]])->entries);
        $this->execute(retired: ['tools/stack-smoke.sh' => [hash('sha256', self::V1)]]);
        self::assertSame(self::V1, file_get_contents($this->base . '/shared-tools/stack-smoke.sh'));
    }

    #[Test]
    public function a_retired_path_through_a_link_inside_the_project_is_left_alone(): void
    {
        // The project pointed tools/ at its own scripts/: what is there is reached through its link.
        $this->writeProject('scripts/stack-smoke.sh', self::V1);
        symlink($this->project . '/scripts', $this->project . '/tools');

        self::assertSame([], $this->plan(['tools/stack-smoke.sh' => [hash('sha256', self::V1)]])->entries);
        $this->execute(retired: ['tools/stack-smoke.sh' => [hash('sha256', self::V1)]]);
        self::assertSame(self::V1, file_get_contents($this->project . '/scripts/stack-smoke.sh'));
    }

    #[Test]
    public function a_file_gone_after_the_plan_is_already_gone(): void
    {
        $this->writeProject('install.sh', self::V1);
        $plan = $this->plan();
        unlink($this->project . '/install.sh');

        $result = (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold)->resultByPath('install.sh');

        self::assertNotNull($result);
        self::assertSame(ScaffoldSyncOutcome::NoOp, $result->outcome);
        self::assertSame('Already gone.', $result->message);
    }

    #[Test]
    public function a_file_changed_after_the_plan_is_kept(): void
    {
        $this->writeProject('install.sh', self::V1);
        $plan = $this->plan();
        $this->writeProject('install.sh', self::V1 . "# edited meanwhile\n");

        $result = (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold)->resultByPath('install.sh');

        self::assertNotNull($result);
        self::assertSame(ScaffoldSyncOutcome::ManualReview, $result->outcome);
        self::assertSame(self::V1 . "# edited meanwhile\n", file_get_contents($this->project . '/install.sh'));
    }

    #[Test]
    public function the_directory_a_removal_empties_stays_and_a_second_run_has_nothing_to_do(): void
    {
        $retired = ['tests/Unit/Scaffold/OldSkeletonTest.php' => [hash('sha256', self::V1)]];
        $this->writeProject('tests/Unit/Scaffold/OldSkeletonTest.php', self::V1);

        $this->execute(retired: $retired);

        self::assertFileDoesNotExist($this->project . '/tests/Unit/Scaffold/OldSkeletonTest.php');
        self::assertDirectoryExists($this->project . '/tests/Unit/Scaffold', 'a phpunit.xml may still name it');
        self::assertSame([], $this->plan($retired)->entries);
    }

    #[Test]
    public function the_stage_report_names_what_was_removed_and_what_was_kept(): void
    {
        $this->writeProject('install.sh', self::V1);
        $this->writeProject('SECURITY.md', "# Our policy\n");

        $output = new BufferedOutput();
        $render = new \ReflectionMethod(UpdateCommand::class, 'renderScaffoldStage');
        $render->invoke(new UpdateCommand(), new SymfonyStyle(new ArrayInput([]), $output), $this->execute());
        $text = $output->fetch();

        self::assertStringContainsString('[applied] install.sh  (remove → retired)', $text);
        self::assertStringContainsString('Removed: semitexa/ultimate no longer ships it', $text);
        self::assertStringContainsString('[manual_review] SECURITY.md  (manual_review → retired)', $text);
        self::assertStringContainsString('Kept: semitexa/ultimate no longer ships it', $text);
        self::assertStringNotContainsString('--write-scaffold-candidates', $text, 'a retired file has no newer version to offer');
    }

    #[Test]
    public function the_loader_reads_the_retired_list_and_a_manifest_without_one_retires_nothing(): void
    {
        $loader = new ScaffoldManifestLoader();
        $hash = hash('sha256', self::V1);

        $manifest = $loader->fromArray($this->manifestArray([['path' => 'install.sh', 'shipped_sha256' => [$hash, $hash]]]));
        self::assertSame(['install.sh' => [$hash]], $manifest->retired);

        $without = $this->manifestArray([]);
        unset($without['retired']);
        self::assertSame([], $loader->fromArray($without)->retired);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unsafeRetiredEntries(): iterable
    {
        $hash = hash('sha256', self::V1);
        yield 'a parent segment' => [['path' => '../outside.sh', 'shipped_sha256' => [$hash]]];
        yield 'an absolute path' => [['path' => '/etc/passwd', 'shipped_sha256' => [$hash]]];
        yield 'an empty segment' => [['path' => 'tools//stack-smoke.sh', 'shipped_sha256' => [$hash]]];
        yield 'a dot segment' => [['path' => 'tools/./stack-smoke.sh', 'shipped_sha256' => [$hash]]];
        yield 'a backslash' => [['path' => 'tools\\stack-smoke.sh', 'shipped_sha256' => [$hash]]];
        yield 'no path' => [['shipped_sha256' => [$hash]]];
        yield 'no hashes' => [['path' => 'install.sh', 'shipped_sha256' => []]];
        yield 'a hash that is none' => [['path' => 'install.sh', 'shipped_sha256' => ['abc']]];
        yield 'a path the scaffold ships' => [['path' => 'bin/semitexa', 'shipped_sha256' => [$hash]]];
    }

    #[Test]
    #[DataProvider('unsafeRetiredEntries')]
    public function the_loader_refuses_a_retired_entry_it_cannot_trust(mixed $entry): void
    {
        $this->expectException(\RuntimeException::class);

        (new ScaffoldManifestLoader())->fromArray($this->manifestArray([$entry]));
    }

    #[Test]
    public function the_builder_carries_the_retired_list_over_and_a_path_shipped_again_leaves_it(): void
    {
        file_put_contents($this->base . '/semitexa', "#!/bin/sh\n");
        $hash = hash('sha256', self::V1);
        $prior = new ScaffoldManifest(ScaffoldManifest::SCHEMA_VERSION, '2026-11-18T00:00:00+00:00', [], [
            'install.sh' => [$hash],
            'bin/semitexa' => [$hash],
        ]);

        $built = (new ScaffoldManifestBuilder())->buildFromFiles(['bin/semitexa' => $this->base . '/semitexa'], $prior);

        self::assertSame(['install.sh' => [$hash]], $built->retired);
        self::assertSame([['path' => 'install.sh', 'shipped_sha256' => [$hash]]], $built->toArray()['retired']);
    }

    #[Test]
    public function the_shipped_manifest_retires_the_frameworks_own_files(): void
    {
        $manifest = (new ScaffoldManifestLoader())->load(dirname(__DIR__, 4) . '/resources/scaffold-manifest.json');

        foreach (['install.sh', 'SECURITY.md', 'src/Capabilities.php', 'src/Application/Console/Command/InitCommand.php', '.github/workflows/stack-smoke.yml', 'tools/stack-smoke.sh', 'tests/Unit/Scaffold/SwoolePinnedTest.php'] as $path) {
            self::assertArrayHasKey($path, $manifest->retired, $path);
            self::assertNotSame([], $manifest->retired[$path], $path);
        }
        self::assertCount(30, $manifest->retired['install.sh'], 'every version its history has');
        self::assertArrayHasKey('src/Console/Command/InitCommand.php', $manifest->retired, 'the init command where it lived before');
        self::assertSame([], array_intersect_key($manifest->retired, $manifest->entries), 'nothing both shipped and retired');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array<string, list<string>> */
    private function retired(): array
    {
        return [
            'install.sh' => [hash('sha256', self::V1), hash('sha256', self::V2)],
            'SECURITY.md' => [hash('sha256', "# Security\n\nReport to the framework.\n")],
        ];
    }

    /** @param array<string, list<string>>|null $retired */
    private function manifest(?array $retired = null): ScaffoldManifest
    {
        return new ScaffoldManifest(ScaffoldManifest::SCHEMA_VERSION, '2026-11-18T00:00:00+00:00', [], $retired ?? $this->retired());
    }

    /** @param array<string, list<string>>|null $retired */
    private function plan(?array $retired = null): \Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncPlan
    {
        return (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $this->manifest($retired));
    }

    private function planEntry(string $path): ScaffoldSyncPlanEntry
    {
        $entry = $this->plan()->entryByPath($path);
        self::assertNotNull($entry, $path . ' is planned');

        return $entry;
    }

    /** @param array<string, list<string>>|null $retired */
    private function execute(bool $dryRun = false, ?array $retired = null): ScaffoldSyncReport
    {
        return (new ScaffoldSyncEngine())->execute($this->plan($retired), $this->project, $this->scaffold, $dryRun);
    }

    /**
     * A valid manifest with one shipped file, bin/semitexa, and the retired list given.
     *
     * @param list<mixed> $retired
     * @return array<string, mixed>
     */
    private function manifestArray(array $retired): array
    {
        return [
            'schema_version' => ScaffoldManifest::SCHEMA_VERSION,
            'generated_at' => '2026-11-18T00:00:00+00:00',
            'files' => [[
                'path' => 'bin/semitexa',
                'current_sha256' => hash('sha256', "#!/bin/sh\n"),
                'previous_sha256' => [],
                'category' => 'executable',
                'critical' => true,
                'auto_update' => true,
                'preserve_executable' => true,
            ]],
            'retired' => $retired,
        ];
    }

    private function writeProject(string $relative, string $bytes): void
    {
        $absolute = $this->project . '/' . $relative;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }
        file_put_contents($absolute, $bytes);
    }

    private function rrm(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->rrm($path . '/' . $name);
            }
        }
        @rmdir($path);
    }
}
