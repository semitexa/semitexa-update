<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Composer;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Composer\ComposerExecutorInterface;
use Semitexa\Update\Application\Service\Composer\ComposerUpdateRunner;
use Semitexa\Update\Application\Service\Composer\UpstreamVersionResolverInterface;
use Semitexa\Update\Domain\Enum\ComposerUpdateOutcome;
use Semitexa\Update\Domain\Model\Composer\ComposerUpdatePlanEntry;

final class ComposerUpdateRunnerTest extends TestCase
{
    /** The whole rehearsal command: dropping --no-scripts would run the project's scripts in a dry run. */
    private const REHEARSAL_ARGS = ['update', 'semitexa/*', '-W', '--no-interaction', '--dry-run', '--no-scripts'];

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-composer-runner-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/vendor/composer', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->projectRoot);
    }

    public function testPlanIdentifiesPinKindsAndTargetsTheReleaseSet(): void
    {
        $this->writeProject(
            declared: [
                'semitexa/update' => '2026.05.10.1449',
                'semitexa/core'   => '2026.05.08.1640',
                'semitexa/platform-ui' => '@dev',
                'semitexa/foo'    => '*',
            ],
            locked: [
                'semitexa/update' => '2026.05.10.1449',
                'semitexa/core'   => '2026.05.08.1640',
                'semitexa/platform-ui' => 'dev-main',
                'semitexa/foo'    => '1.0',
            ],
            installed: [
                'semitexa/update' => '2026.05.10.1449',
                'semitexa/core'   => '2026.05.08.1640',
                'semitexa/platform-ui' => 'dev-main',
                'semitexa/foo'    => '1.0',
            ],
            pathRepoNames: ['semitexa/platform-ui'],
        );

        $resolver = FakeResolver::withReleaseSet('2026.05.12.0744', [
            'semitexa/update' => '2026.05.12.0744',
            'semitexa/core'   => '2026.05.12.0744',
        ]);
        $executor = new FakeExecutor(available: true);
        $runner = new ComposerUpdateRunner($executor, $resolver);

        $plan = $runner->plan($this->projectRoot);

        self::assertSame('2026.05.12.0744', $plan->releaseSetVersion);
        self::assertTrue($plan->inContainer);

        $update = $plan->entryByName('semitexa/update');
        self::assertNotNull($update);
        self::assertSame(ComposerUpdatePlanEntry::PIN_EXACT, $update->pinKind);
        self::assertSame('2026.05.12.0744', $update->targetVersion);
        self::assertTrue($update->willBeBumped());

        $core = $plan->entryByName('semitexa/core');
        self::assertNotNull($core);
        self::assertSame('2026.05.12.0744', $core->targetVersion);
        self::assertTrue($core->willBeBumped());

        $pathRepo = $plan->entryByName('semitexa/platform-ui');
        self::assertNotNull($pathRepo);
        self::assertSame(ComposerUpdatePlanEntry::PIN_PATH_REPO, $pathRepo->pinKind);
        self::assertNull($pathRepo->targetVersion);
        self::assertFalse($pathRepo->willBeBumped());

        $wildcard = $plan->entryByName('semitexa/foo');
        self::assertNotNull($wildcard);
        self::assertSame(ComposerUpdatePlanEntry::PIN_WILDCARD, $wildcard->pinKind);
        self::assertNull($wildcard->targetVersion);
    }

    public function testATransitiveDependencyIsNotTreatedAsAPinAndCannotBlock(): void
    {
        // In the lock and vendor, absent from composer.json: something else
        // requires it. Both constraint helpers answer false for a null
        // constraint, so it used to fall through to the exact-pin branch, and a
        // package this project pins nothing about could block the whole update.
        // Seen live with semitexa/platform-settings.
        $this->writeProject(
            declared: [
                'semitexa/update' => '2026.05.10.1449',
            ],
            locked: [
                'semitexa/update'           => '2026.05.10.1449',
                'semitexa/platform-settings' => '2026.05.08.1640',
            ],
            installed: [
                'semitexa/update'           => '2026.05.10.1449',
                'semitexa/platform-settings' => '2026.05.08.1640',
            ],
        );

        // The resolver knows nothing about the transitive package — exactly the
        // state a network blip or an unpublished tag leaves it in.
        $resolver = new FakeResolver([
            'semitexa/update' => ['2026.05.12.0744', '2026.05.10.1449'],
        ]);

        $plan = (new ComposerUpdateRunner(new FakeExecutor(available: true), $resolver))
            ->plan($this->projectRoot);

        $transitive = $plan->entryByName('semitexa/platform-settings');
        self::assertNotNull($transitive);
        self::assertSame(ComposerUpdatePlanEntry::PIN_TRANSITIVE, $transitive->pinKind);
        self::assertNull($transitive->targetVersion);
        self::assertNotSame('', $transitive->skipReason, 'A skipped entry must say why it was skipped.');

        self::assertSame(
            [],
            $plan->unresolvedEntries(),
            'A dependency this project does not require must never block the update.',
        );
    }

    public function testAPackageOutsideTheReleaseSetTargetsItsOwnLatest(): void
    {
        $this->writeProject(
            declared: ['semitexa/update' => '2026.05.10.1449', 'semitexa/legacy' => '2026.05.08.1640'],
            locked: ['semitexa/update' => '2026.05.10.1449', 'semitexa/legacy' => '2026.05.08.1640'],
            installed: ['semitexa/update' => '2026.05.10.1449', 'semitexa/legacy' => '2026.05.08.1640'],
        );

        $resolver = new FakeResolver([
            'semitexa/update' => ['2026.05.12.0744'],
            'semitexa/legacy' => ['2026.05.09.0726'],  // no release set: ultimate unreadable
        ]);
        $plan = (new ComposerUpdateRunner(new FakeExecutor(true), $resolver))->plan($this->projectRoot);

        self::assertSame('2026.05.09.0726', $plan->entryByName('semitexa/legacy')->targetVersion);
    }

    public function testPlanRefusesWhenNotInContainer(): void
    {
        $this->writeProject(declared: [], locked: [], installed: []);
        $plan = (new ComposerUpdateRunner(new FakeExecutor(false), new FakeResolver([])))->plan($this->projectRoot);

        self::assertFalse($plan->inContainer);
        self::assertStringContainsString('container', $plan->containerError);
    }

    public function testDryRunDoesNotMutateAndReportsWouldRun(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449'],
            locked:    ['semitexa/update' => '2026.05.10.1449'],
            installed: ['semitexa/update' => '2026.05.10.1449'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => '']);
        $runner = new ComposerUpdateRunner($executor, $resolver);

        $beforeJson = file_get_contents($this->projectRoot . '/composer.json');
        $result = $runner->execute($this->projectRoot, dryRun: true);

        self::assertSame(ComposerUpdateOutcome::WouldRun, $result->outcome);
        self::assertSame($beforeJson, file_get_contents($this->projectRoot . '/composer.json'));
        self::assertSame(1, $executor->callCount, 'Dry-run asks composer whether the plan resolves.');
        self::assertSame(self::REHEARSAL_ARGS, $executor->lastArgs, 'Dry-run may only invoke composer in --dry-run mode.');
    }

    public function testNoComposerOptionSkipsPhase(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449'],
            locked:    ['semitexa/update' => '2026.05.10.1449'],
            installed: ['semitexa/update' => '2026.05.10.1449'],
        );
        $executor = new FakeExecutor(true);
        $result = (new ComposerUpdateRunner($executor, new FakeResolver([])))
            ->execute($this->projectRoot, skip: true);

        self::assertSame(ComposerUpdateOutcome::Skipped, $result->outcome);
        self::assertSame(0, $executor->callCount);
    }

    public function testRealRunBumpsPinsAndInvokesComposer(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
            locked:    ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
            installed: ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
        );
        $resolver = new FakeResolver([
            'semitexa/update' => ['2026.05.12.0744'],
            'semitexa/core'   => ['2026.05.12.0744'],
        ]);
        $executor = new ApplyingFakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => 'ok']);
        $runner = new ComposerUpdateRunner($executor, $resolver);

        $result = $runner->execute($this->projectRoot, dryRun: false);

        self::assertSame(1, $executor->callCount);
        self::assertSame(['update', 'semitexa/*', '-W', '--no-interaction'], $executor->lastArgs);

        $afterJson = json_decode((string) file_get_contents($this->projectRoot . '/composer.json'), true);
        self::assertSame('2026.05.12.0744', $afterJson['require']['semitexa/update']);
        self::assertSame('2026.05.12.0744', $afterJson['require']['semitexa/core']);

        // The anchor moved with everything else, so the run stops to be rerun on
        // fresh code — and it reports the packages that actually changed version,
        // which is the whole point: a summary built from our own pin rewrites
        // would say the same thing whether or not composer did anything.
        self::assertSame(ComposerUpdateOutcome::UpdaterChanged, $result->outcome);
        self::assertSame(
            ['from' => '2026.05.08.1640', 'to' => '2026.05.12.0744'],
            $result->bumpedPackages['semitexa/core'],
        );
    }

    public function testUpdaterChangedWhenInstalledJsonVersionShiftsAcrossComposerCall(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449'],
            locked:    ['semitexa/update' => '2026.05.10.1449'],
            installed: ['semitexa/update' => '2026.05.10.1449'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);

        // The fake executor will, on its single call, rewrite installed.json
        // to simulate composer's real effect of upgrading the package.
        $project = $this->projectRoot;
        $executor = new class(true) extends FakeExecutor {
            public string $projectRoot = '';
            public function run(array $args, string $projectRoot, array $env = []): array
            {
                $this->callCount++;
                $this->lastArgs = $args;
                $installed = json_decode((string) file_get_contents($projectRoot . '/vendor/composer/installed.json'), true);
                foreach ($installed['packages'] as &$p) {
                    if ($p['name'] === 'semitexa/update') {
                        $p['version'] = '2026.05.12.0744';
                    }
                }
                file_put_contents($projectRoot . '/vendor/composer/installed.json', json_encode($installed));
                return ['exitCode' => 0, 'output' => ''];
            }
        };
        $runner = new ComposerUpdateRunner($executor, $resolver);

        $result = $runner->execute($project, dryRun: false);

        self::assertSame(ComposerUpdateOutcome::UpdaterChanged, $result->outcome);
        self::assertSame('2026.05.10.1449', $result->installedBefore);
        self::assertSame('2026.05.12.0744', $result->installedAfter);
        self::assertStringContainsString('fresh', $result->message);
    }

    public function testComposerNonZeroExitFailsTheRun(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449'],
            locked:    ['semitexa/update' => '2026.05.10.1449'],
            installed: ['semitexa/update' => '2026.05.10.1449'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 2, 'output' => 'conflict']);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome);
        self::assertSame(2, $result->composerExitCode);
        self::assertStringContainsString('conflict', $result->composerOutput);
    }

    public function testPathRepoAndDevPinsAreNeverRewritten(): void
    {
        $this->writeProject(
            declared:  [
                'semitexa/update' => '2026.05.10.1449',
                'semitexa/platform-ui' => '@dev',
                'semitexa/skins-base'  => '@dev',
            ],
            locked:    [
                'semitexa/update' => '2026.05.10.1449',
                'semitexa/platform-ui' => 'dev-main',
                'semitexa/skins-base'  => 'dev-master',
            ],
            installed: [
                'semitexa/update' => '2026.05.10.1449',
                'semitexa/platform-ui' => 'dev-main',
                'semitexa/skins-base'  => 'dev-master',
            ],
            pathRepoNames: ['semitexa/platform-ui'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => '']);

        (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot, dryRun: false);

        $after = json_decode((string) file_get_contents($this->projectRoot . '/composer.json'), true);
        self::assertSame('2026.05.12.0744', $after['require']['semitexa/update']);
        self::assertSame('@dev', $after['require']['semitexa/platform-ui'], 'Path-repo @dev pin must be untouched.');
        self::assertSame('@dev', $after['require']['semitexa/skins-base'], 'Dev @dev pin must be untouched.');
    }

    public function testRefusesToInvokeComposerOutsideContainer(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449'],
            locked:    ['semitexa/update' => '2026.05.10.1449'],
            installed: ['semitexa/update' => '2026.05.10.1449'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(available: false);
        $beforeJson = file_get_contents($this->projectRoot . '/composer.json');

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome);
        self::assertSame(0, $executor->callCount, 'composer must not be invoked outside the container.');
        self::assertSame($beforeJson, file_get_contents($this->projectRoot . '/composer.json'), 'composer.json must not be mutated when refusing to run.');
    }

    public function testUnresolvedAnchorBlocksByDefault(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
            locked:    ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
            installed: ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
        );
        $resolver = new FakeResolver([]); // resolver returns null for everything
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome);
        self::assertSame(0, $executor->callCount, 'composer must not be invoked when upstream is unresolved.');
        self::assertStringContainsString('upstream metadata could not be resolved', $result->message);
        self::assertStringContainsString('semitexa/update', $result->message);
        self::assertStringContainsString('--allow-partial-composer-update', $result->message);
    }

    public function testUnresolvedNonAnchorBlocksByDefault(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
            locked:    ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
            installed: ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
        );
        // anchor resolves, semitexa/core does not
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome);
        self::assertStringContainsString('semitexa/core', $result->message);
        self::assertSame(0, $executor->callCount);
        // composer.json must not be rewritten when we refuse to proceed.
        $after = json_decode((string) file_get_contents($this->projectRoot . '/composer.json'), true);
        self::assertSame('2026.05.10.1449', $after['require']['semitexa/update']);
        self::assertSame('2026.05.08.1640', $after['require']['semitexa/core']);
    }

    public function testAllowPartialFlagPermitsContinuationWithDegradedMessage(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
            locked:    ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
            installed: ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new ApplyingFakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => 'ok']);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false, allowPartial: true);

        // The anchor itself is what moved, so the run stops for a rerun; the
        // degraded tail must survive that path too.
        self::assertSame(ComposerUpdateOutcome::UpdaterChanged, $result->outcome);
        self::assertSame(1, $executor->callCount, 'composer must be invoked under --allow-partial.');
        self::assertStringContainsString('DEGRADED', $result->message);
        self::assertStringContainsString('semitexa/core', $result->message);
        // The resolvable pin still gets bumped.
        $after = json_decode((string) file_get_contents($this->projectRoot . '/composer.json'), true);
        self::assertSame('2026.05.12.0744', $after['require']['semitexa/update']);
        self::assertSame('2026.05.08.1640', $after['require']['semitexa/core'], 'Unresolved package keeps its pin.');
    }

    public function testDryRunReportsUnresolvedAsFailureByDefault(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449'],
            locked:    ['semitexa/update' => '2026.05.10.1449'],
            installed: ['semitexa/update' => '2026.05.10.1449'],
        );
        $resolver = new FakeResolver([]);
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: true);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome,
            'Dry-run must NOT present the Composer phase as clean when upstream is unresolved.',
        );
        self::assertStringContainsString('upstream metadata could not be resolved', $result->message);
    }

    public function testDryRunUnderPartialFlagReportsWouldRunWithDegradedTail(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
            locked:    ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
            installed: ['semitexa/update' => '2026.05.10.1449', 'semitexa/core' => '2026.05.08.1640'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: true, allowPartial: true);

        self::assertSame(ComposerUpdateOutcome::WouldRun, $result->outcome);
        self::assertStringContainsString('DEGRADED', $result->message);
        self::assertStringContainsString('semitexa/core', $result->message);
        self::assertSame(self::REHEARSAL_ARGS, $executor->lastArgs, 'Dry-run under --allow-partial still only rehearses.');
    }

    public function testNoComposerStillSkipsCleanlyEvenWhenUpstreamWouldBlock(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.10.1449'],
            locked:    ['semitexa/update' => '2026.05.10.1449'],
            installed: ['semitexa/update' => '2026.05.10.1449'],
        );
        $resolver = new FakeResolver([]); // would block by default
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, skip: true);

        self::assertSame(ComposerUpdateOutcome::Skipped, $result->outcome);
        self::assertSame(0, $executor->callCount);
    }

    public function testPathRepoAndDevConstraintsAreNotConsideredUnresolved(): void
    {
        $this->writeProject(
            declared:  [
                'semitexa/update' => '2026.05.10.1449',
                'semitexa/platform-ui' => '@dev',
                'semitexa/skins-base'  => '@dev',
            ],
            locked:    [
                'semitexa/update' => '2026.05.10.1449',
                'semitexa/platform-ui' => 'dev-main',
                'semitexa/skins-base'  => 'dev-master',
            ],
            installed: [
                'semitexa/update' => '2026.05.10.1449',
                'semitexa/platform-ui' => 'dev-main',
                'semitexa/skins-base'  => 'dev-master',
            ],
            pathRepoNames: ['semitexa/platform-ui'],
        );
        // Resolver returns null for path-repo + dev names too — they must
        // not be classified as unresolved because we never tried to bump them.
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new ApplyingFakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => '']);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        // The anchor moved, so the outcome is UpdaterChanged rather than Updated —
        // what this test is about is that the path-repo and @dev entries did not
        // stop the phase, which the call count and a non-failure outcome show.
        self::assertSame(ComposerUpdateOutcome::UpdaterChanged, $result->outcome, 'Path/dev packages must not block.');
        self::assertSame(1, $executor->callCount);
        self::assertArrayNotHasKey(
            'semitexa/platform-ui',
            $result->bumpedPackages,
            'A path repository has no version to move and must never appear as one that did.',
        );
    }

    public function testCleanStateSkipsComposerInvocationEntirely(): void
    {
        // Coherent: declared == locked == installed, anchor is the same → no bumps,
        // no lock/vendor drift, no force → composer must NOT be invoked. The whole
        // point: avoid composer.lock content-hash churn on no-op `bin/semitexa update`.
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.12.0744'],
            locked:    ['semitexa/update' => '2026.05.12.0744'],
            installed: ['semitexa/update' => '2026.05.12.0744'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        self::assertSame(ComposerUpdateOutcome::Clean, $result->outcome);
        self::assertSame(0, $executor->callCount, 'composer must NOT be invoked when nothing needs doing.');
        self::assertStringContainsString('--composer-only', $result->message, 'Operator-facing message must name the force flag.');
    }

    public function testForceFlagRunsComposerEvenWhenClean(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.12.0744'],
            locked:    ['semitexa/update' => '2026.05.12.0744'],
            installed: ['semitexa/update' => '2026.05.12.0744'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => '']);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false, force: true);

        self::assertSame(ComposerUpdateOutcome::Clean, $result->outcome);
        self::assertSame(1, $executor->callCount, 'force=true must invoke composer even in a clean state.');
    }

    public function testLockStaleVsInstalledStillRunsComposer(): void
    {
        // No bumps needed (declared == locked) BUT installed != locked → there IS
        // genuine vendor drift that only composer install/update can heal. Skip is
        // not allowed in this case.
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.12.0744'],
            locked:    ['semitexa/update' => '2026.05.12.0744'],
            installed: ['semitexa/update' => '2026.05.12.0643'],  // drift!
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => '']);

        (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        self::assertSame(1, $executor->callCount, 'composer must run when vendor != lock.');
    }

    public function testDeclaredVsLockedDriftStillRunsComposer(): void
    {
        // composer.json got hand-edited to a new pin that's already on Packagist;
        // composer.lock is behind. Even though the runner's plan() won't add this
        // to its bumps (target version IS the declared value), composer must run
        // to bring the lock and vendor up to date.
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.12.0744'],  // hand-edited pin
            locked:    ['semitexa/update' => '2026.05.12.0643'],  // lock stale
            installed: ['semitexa/update' => '2026.05.12.0643'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => '']);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        self::assertSame(1, $executor->callCount, 'composer must run when declared != locked.');
    }

    public function testDryRunCleanStateReportsCleanWithoutInvokingComposer(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.05.12.0744'],
            locked:    ['semitexa/update' => '2026.05.12.0744'],
            installed: ['semitexa/update' => '2026.05.12.0744'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: true);

        self::assertSame(ComposerUpdateOutcome::Clean, $result->outcome);
        self::assertSame(0, $executor->callCount);
    }

    /**
     * @param array<string, string> $declared
     * @param array<string, string> $locked
     * @param array<string, string> $installed
     * @param list<string> $pathRepoNames
     */
    /**
     * The case that started this: a project whose semitexa/* constraints are all
     * "*". There are no pins to rewrite, so composer resolves the new versions on
     * its own — and the phase used to describe that as "No release-pinned
     * semitexa/* package needed a bump", outcome Clean, journalled as a noop.
     * Seven packages had just moved. An operator reading that has no way to tell
     * an update from nothing at all, and will do it again by hand.
     */
    public function testWildcardProjectReportsThePackagesComposerActuallyMoved(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '*', 'semitexa/core' => '*'],
            locked:    ['semitexa/update' => '2026.05.12.0744', 'semitexa/core' => '2026.05.08.1640'],
            installed: ['semitexa/update' => '2026.05.12.0744', 'semitexa/core' => '2026.05.08.1640'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);

        // Composer resolves core forward inside its wildcard, as it would upstream.
        $executor = new class(true) extends FakeExecutor {
            public function run(array $args, string $projectRoot, array $env = []): array
            {
                $this->callCount++;
                $this->lastArgs = $args;
                foreach (['/composer.lock', '/vendor/composer/installed.json'] as $file) {
                    $data = json_decode((string) file_get_contents($projectRoot . $file), true);
                    foreach ($data['packages'] as &$pkg) {
                        if ($pkg['name'] === 'semitexa/core') {
                            $pkg['version'] = '2026.05.12.0744';
                        }
                    }
                    unset($pkg);
                    file_put_contents($projectRoot . $file, json_encode($data));
                }
                return ['exitCode' => 0, 'output' => ''];
            }
        };

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false, force: true);

        self::assertSame(ComposerUpdateOutcome::Updated, $result->outcome, 'A move is not a clean no-op.');
        self::assertSame(
            ['semitexa/core' => ['from' => '2026.05.08.1640', 'to' => '2026.05.12.0744']],
            $result->bumpedPackages,
        );
        self::assertStringContainsString('semitexa/core', $result->message);
        self::assertStringNotContainsString('needed a bump', $result->message);
    }

    /** Composer ran and genuinely changed nothing — say so, do not claim a bump. */
    public function testAForcedRunThatMovesNothingSaysNothingMoved(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '*'],
            locked:    ['semitexa/update' => '2026.05.12.0744'],
            installed: ['semitexa/update' => '2026.05.12.0744'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false, force: true);

        self::assertSame(ComposerUpdateOutcome::Clean, $result->outcome);
        self::assertSame([], $result->bumpedPackages);
        self::assertStringContainsString('no semitexa/* package changed version', $result->message);
    }

    /**
     * The other half. A wildcard cannot disagree with a lock, so the lock/vendor
     * drift check walks straight past it and a plain `bin/semitexa update` skips
     * the composer phase forever — the packages sit where they are until someone
     * runs composer by hand. A wildcard package installed behind what upstream
     * offers is the signal that does survive wildcards.
     */
    public function testAWildcardPackageBehindUpstreamIsNotSkipped(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '*', 'semitexa/core' => '*'],
            locked:    ['semitexa/update' => '2026.05.12.0744', 'semitexa/core' => '2026.05.08.1640'],
            installed: ['semitexa/update' => '2026.05.12.0744', 'semitexa/core' => '2026.05.08.1640'],
        );
        $resolver = new FakeResolver([
            'semitexa/update' => ['2026.05.12.0744'],
            'semitexa/core'   => ['2026.05.12.0744'],
        ]);
        $executor = new FakeExecutor(true);

        // No force, no pins, no lock/vendor drift — the old guard skipped here.
        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        self::assertSame(1, $executor->callCount, 'A stale wildcard set must let composer look.');
        // Clean is the honest answer once it HAS looked and this fake moved
        // nothing; what the bug was is never looking. The two states used to be
        // indistinguishable from the outside, which is why the message differs.
        self::assertStringContainsString('no semitexa/* package changed version', $result->message);
        self::assertStringNotContainsString('skipped', $result->message);
    }

    /** A coherent set still skips: this must not become "run composer every time". */
    public function testACoherentWildcardSetStillSkips(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '*', 'semitexa/core' => '*'],
            locked:    ['semitexa/update' => '2026.05.12.0744', 'semitexa/core' => '2026.05.12.0744'],
            installed: ['semitexa/update' => '2026.05.12.0744', 'semitexa/core' => '2026.05.12.0744'],
        );
        $resolver = new FakeResolver(['semitexa/update' => ['2026.05.12.0744']]);
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        self::assertSame(0, $executor->callCount);
        self::assertSame(ComposerUpdateOutcome::Clean, $result->outcome);
    }

    /**
     * semitexa.portal, 2026-09-28. The cut 2026.09.28.0444 tagged core, rbac and
     * the rest but not semitexa/update, whose latest stayed 2026.09.24.1147.
     * The runner took that tag as the "release-set anchor", aimed core — which
     * owns a 09.24.1147 tag — back at it, and aimed rbac, which does not, at
     * 09.27.0404, which requires core >= 09.27.0404. Unresolvable by
     * construction, every time a cut skips the updater. The set is what
     * ultimate pins.
     */
    public function testACutThatDoesNotRetagTheUpdaterStillTargetsTheWholeSet(): void
    {
        $this->writeProject(
            declared:  ['semitexa/update' => '2026.09.24.1147', 'semitexa/core' => '2026.09.24.1147', 'semitexa/rbac' => '2026.09.08.2003'],
            locked:    ['semitexa/update' => '2026.09.24.1147', 'semitexa/core' => '2026.09.24.1147', 'semitexa/rbac' => '2026.09.08.2003'],
            installed: ['semitexa/update' => '2026.09.24.1147', 'semitexa/core' => '2026.09.24.1147', 'semitexa/rbac' => '2026.09.08.2003'],
        );
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', [
            'semitexa/update' => '2026.09.24.1147',
            'semitexa/core'   => '2026.09.27.0404',
            'semitexa/rbac'   => '2026.09.27.0404',
        ], [
            'semitexa/update' => ['2026.09.24.1147'],
            'semitexa/core'   => ['2026.09.27.0404', '2026.09.24.1147'],
            'semitexa/rbac'   => ['2026.09.27.0404', '2026.09.08.2003'],
        ]);

        $plan = (new ComposerUpdateRunner(new FakeExecutor(true), $resolver))->plan($this->projectRoot);

        self::assertSame('2026.09.28.0444', $plan->releaseSetVersion);
        self::assertSame('2026.09.27.0404', $plan->entryByName('semitexa/core')->targetVersion);
        self::assertSame('2026.09.27.0404', $plan->entryByName('semitexa/rbac')->targetVersion);
        self::assertFalse($plan->entryByName('semitexa/update')->willBeBumped(), 'update is already at its place in the set.');
        self::assertSame([], $plan->unresolvedEntries());
    }

    /** The set is what was tested together; a stray newer tag is not part of it yet. */
    public function testTheReleaseSetWinsOverAPackagesOwnNewerTag(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.24.1147'],
            locked:    ['semitexa/core' => '2026.09.24.1147'],
            installed: ['semitexa/core' => '2026.09.24.1147'],
        );
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', ['semitexa/core' => '2026.09.27.0404'], [
            'semitexa/core' => ['2026.09.28.1200', '2026.09.27.0404', '2026.09.24.1147'],
        ]);

        $plan = (new ComposerUpdateRunner(new FakeExecutor(true), $resolver))->plan($this->projectRoot);

        self::assertSame('2026.09.27.0404', $plan->entryByName('semitexa/core')->targetVersion);
    }

    /** Same portal run: a core pin the operator had already moved ahead was rewritten back down. */
    public function testAPinAheadOfTheReleaseSetIsNeverLowered(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.27.0404'],
            locked:    ['semitexa/core' => '2026.09.27.0404'],
            installed: ['semitexa/core' => '2026.09.27.0404'],
        );
        $resolver = FakeResolver::withReleaseSet('2026.09.24.1147', ['semitexa/core' => '2026.09.24.1147']);
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot);

        self::assertSame(ComposerUpdateOutcome::Clean, $result->outcome);
        self::assertSame(0, $executor->callCount);
        $after = json_decode((string) file_get_contents($this->projectRoot . '/composer.json'), true);
        self::assertSame('2026.09.27.0404', $after['require']['semitexa/core']);
    }

    /**
     * An up-to-date project spans several release dates — a cut tags only what
     * changed. That used to count as drift, so composer ran on every update.
     */
    public function testAnUpToDateSetSpanningReleaseDatesDoesNotRunComposer(): void
    {
        $set = ['semitexa/update' => '2026.09.24.1147', 'semitexa/core' => '2026.09.27.0404', 'semitexa/ssr' => '2026.09.28.0444'];
        $this->writeProject(declared: $set, locked: $set, installed: $set);
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, FakeResolver::withReleaseSet('2026.09.28.0444', $set)))
            ->execute($this->projectRoot);

        self::assertSame(ComposerUpdateOutcome::Clean, $result->outcome);
        self::assertSame(0, $executor->callCount);
    }

    /**
     * semitexa.com production: three exact-pinned content packages live in
     * private repositories. Packagist answers 404 for them, and that blocked
     * every update with "upstream metadata could not be resolved".
     */
    public function testAnExactPinThatIsNotOnPackagistIsLeftAloneAndDoesNotBlock(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.24.1147', 'semitexa/site' => '2026.05.10.0828'],
            locked:    ['semitexa/core' => '2026.09.24.1147', 'semitexa/site' => '2026.05.10.0828'],
            installed: ['semitexa/core' => '2026.09.24.1147', 'semitexa/site' => '2026.05.10.0828'],
        );
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', ['semitexa/core' => '2026.09.27.0404'], [
            'semitexa/site' => [], // answered: nothing published
        ]);
        $executor = new ApplyingFakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot);

        self::assertSame(ComposerUpdateOutcome::Updated, $result->outcome);
        $site = (new ComposerUpdateRunner(new FakeExecutor(true), $resolver))->plan($this->projectRoot)->entryByName('semitexa/site');
        self::assertFalse($site->isUnresolved());
        self::assertNotSame('', $site->skipReason);
        $after = json_decode((string) file_get_contents($this->projectRoot . '/composer.json'), true);
        self::assertSame('2026.05.10.0828', $after['require']['semitexa/site']);
    }

    /**
     * Composer refused the set. The pins had already been rewritten, and the
     * run used to stop there — composer.json bumped against the old lock, the
     * half-state the update promised never to leave.
     */
    public function testAFailedComposerRunLeavesComposerJsonAndLockByteIdentical(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.24.1147'],
            locked:    ['semitexa/core' => '2026.09.24.1147'],
            installed: ['semitexa/core' => '2026.09.24.1147'],
        );
        $json = (string) file_get_contents($this->projectRoot . '/composer.json');
        $lock = (string) file_get_contents($this->projectRoot . '/composer.lock');
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', ['semitexa/core' => '2026.09.27.0404']);
        $output = "Your requirements could not be resolved to an installable set of packages.\n\n  Problem 1\n    - semitexa/rbac requires semitexa/core >=2026.09.27.0404";
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 2, 'output' => $output]);

        $result = (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome);
        self::assertSame($json, file_get_contents($this->projectRoot . '/composer.json'));
        self::assertSame($lock, file_get_contents($this->projectRoot . '/composer.lock'));
        self::assertStringContainsString('restored', $result->message);
        self::assertStringContainsString('Problem 1', $result->message, 'The summary must carry composer\'s reason.');
        self::assertSame(1, $executor->callCount, 'Nothing moved in vendor/, so nothing to reinstall.');
    }

    /** A failure halfway through the install: vendor/ moved, so it is reinstalled from the restored lock. */
    public function testAFailureAfterVendorMovedReinstallsFromTheRestoredLock(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.24.1147'],
            locked:    ['semitexa/core' => '2026.09.24.1147'],
            installed: ['semitexa/core' => '2026.09.24.1147'],
        );
        $json = (string) file_get_contents($this->projectRoot . '/composer.json');
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', ['semitexa/core' => '2026.09.27.0404']);
        $executor = new class(true) extends FakeExecutor {
            /** @var list<list<string>> */
            public array $calls = [];
            public function run(array $args, string $projectRoot, array $env = []): array
            {
                $this->callCount++;
                $this->calls[] = $args;
                // update: moves vendor, then dies; install: puts vendor back to the lock.
                $lock = json_decode((string) file_get_contents($projectRoot . '/composer.lock'), true);
                $version = $args[0] === 'update' ? '2026.09.27.0404' : $lock['packages'][0]['version'];
                file_put_contents($projectRoot . '/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'semitexa/core', 'version' => $version]]]));
                return ['exitCode' => $args[0] === 'update' ? 1 : 0, 'output' => 'download failed'];
            }
        };

        $result = (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome);
        self::assertSame(['install', '--no-interaction'], $executor->calls[1] ?? null);
        self::assertSame($json, file_get_contents($this->projectRoot . '/composer.json'));
        self::assertSame([], $result->bumpedPackages, 'After the reinstall nothing is left moved.');
        self::assertStringContainsString('reinstalled', $result->message);
    }

    /**
     * installed.json is written when the install finishes, so a download that
     * dies halfway leaves it saying nothing moved while vendor/ has. Only a
     * resolution failure (exit 2) is known to leave vendor/ alone.
     */
    public function testAFailureOtherThanResolutionReinstallsEvenWhenNothingLooksMoved(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.24.1147'],
            locked:    ['semitexa/core' => '2026.09.24.1147'],
            installed: ['semitexa/core' => '2026.09.24.1147'],
        );
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', ['semitexa/core' => '2026.09.27.0404']);
        $executor = new class(true) extends FakeExecutor {
            /** @var list<list<string>> */
            public array $calls = [];
            public function run(array $args, string $projectRoot, array $env = []): array
            {
                $this->callCount++;
                $this->calls[] = $args;
                return ['exitCode' => $args[0] === 'update' ? 1 : 0, 'output' => 'The "https://..." file could not be downloaded'];
            }
        };

        $result = (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome);
        self::assertSame(['install', '--no-interaction'], $executor->calls[1] ?? null);
    }

    /**
     * The dry run asks composer — against a scratch copy carrying the new pins,
     * named through COMPOSER= — and the project's own files are never written.
     */
    public function testDryRunRehearsesAgainstAScratchCopy(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.24.1147'],
            locked:    ['semitexa/core' => '2026.09.24.1147'],
            installed: ['semitexa/core' => '2026.09.24.1147'],
        );
        $json = (string) file_get_contents($this->projectRoot . '/composer.json');
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', ['semitexa/core' => '2026.09.27.0404']);
        $executor = new class(true) extends FakeExecutor {
            public ?array $scratch = null;
            public bool $scratchLock = false;
            public function run(array $args, string $projectRoot, array $env = []): array
            {
                $this->callCount++;
                $this->lastArgs = $args;
                $this->lastEnv = $env;
                $file = $projectRoot . '/' . ($env['COMPOSER'] ?? 'composer.json');
                $this->scratch = json_decode((string) file_get_contents($file), true);
                $this->scratchLock = is_file(substr($file, 0, -5) . '.lock');
                return ['exitCode' => 0, 'output' => ''];
            }
        };

        $result = (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot, dryRun: true);

        self::assertSame(ComposerUpdateOutcome::WouldRun, $result->outcome);
        self::assertSame(self::REHEARSAL_ARGS, $executor->lastArgs);
        self::assertArrayHasKey('COMPOSER', $executor->lastEnv);
        self::assertNotSame('composer.json', $executor->lastEnv['COMPOSER']);
        self::assertSame('2026.09.27.0404', $executor->scratch['require']['semitexa/core'], 'Composer must see the planned pins.');
        self::assertTrue($executor->scratchLock, 'Composer must rehearse from the current lock.');
        self::assertSame($json, file_get_contents($this->projectRoot . '/composer.json'));
        self::assertSame(['composer.json', 'composer.lock', 'vendor'], $this->rootEntries(), 'Scratch files must be cleaned up.');
    }

    /** A dry run that used to say WouldRun for a set composer would refuse. */
    public function testDryRunReportsAnUnresolvableSetAsAFailure(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.24.1147'],
            locked:    ['semitexa/core' => '2026.09.24.1147'],
            installed: ['semitexa/core' => '2026.09.24.1147'],
        );
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', ['semitexa/core' => '2026.09.27.0404']);
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 2, 'output' => "  Problem 1\n    - conflict"]);

        $result = (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot, dryRun: true);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome);
        self::assertStringContainsString('does not resolve', $result->message);
        self::assertStringContainsString('Problem 1', $result->message);
        self::assertSame(['composer.json', 'composer.lock', 'vendor'], $this->rootEntries());
    }

    /**
     * Review of #47: with ultimate unreachable the plan fell back to each
     * package's own latest — the stray tag the set exists to keep out — and
     * "never lower" then held it there. Unreachable blocks, like any registry.
     */
    public function testAnUnreachableReleaseSetBlocksInsteadOfFallingBackToEachLatest(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.24.1147'],
            locked:    ['semitexa/core' => '2026.09.24.1147'],
            installed: ['semitexa/core' => '2026.09.24.1147'],
        );
        $resolver = new FakeResolver([
            'semitexa/ultimate' => null,
            'semitexa/core'     => ['2026.09.28.1200', '2026.09.27.0404'],
        ]);
        $executor = new FakeExecutor(true);

        $result = (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome);
        self::assertSame(0, $executor->callCount);
        self::assertStringContainsString('semitexa/core', $result->message);
        $after = json_decode((string) file_get_contents($this->projectRoot . '/composer.json'), true);
        self::assertSame('2026.09.24.1147', $after['require']['semitexa/core']);
    }

    /**
     * Review of #47: without a lock to restore, `composer install` resolves a
     * fresh set — the rollback would itself change the project.
     */
    public function testAProjectWithoutALockIsNotReinstalledAfterAFailure(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.24.1147'],
            locked:    [],
            installed: ['semitexa/core' => '2026.09.24.1147'],
        );
        unlink($this->projectRoot . '/composer.lock');
        $json = (string) file_get_contents($this->projectRoot . '/composer.json');
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', ['semitexa/core' => '2026.09.27.0404']);
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 1, 'output' => 'download failed']);

        $result = (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot);

        self::assertSame(ComposerUpdateOutcome::Failed, $result->outcome);
        self::assertSame(1, $executor->callCount, 'No composer install without a lock to install from.');
        self::assertFileDoesNotExist($this->projectRoot . '/composer.lock');
        self::assertSame($json, file_get_contents($this->projectRoot . '/composer.json'));
        self::assertStringContainsString('There was no composer.lock before this run', $result->message);
    }

    /** Review of #47: a package just added as "*" is in neither lock nor vendor, and was reported clean. */
    public function testANewlyDeclaredWildcardPackageRunsComposer(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.27.0404', 'semitexa/foo' => '*'],
            locked:    ['semitexa/core' => '2026.09.27.0404'],
            installed: ['semitexa/core' => '2026.09.27.0404'],
        );
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', ['semitexa/core' => '2026.09.27.0404'], [
            'semitexa/foo' => ['2026.09.27.0404'],
        ]);
        $executor = new FakeExecutor(true);

        (new ComposerUpdateRunner($executor, $resolver))->execute($this->projectRoot);

        self::assertSame(1, $executor->callCount);
        self::assertSame(['update', 'semitexa/*', '-W', '--no-interaction'], $executor->lastArgs);
    }

    /**
     * Review of #47: dry runs take no update lock, and one shared scratch name
     * let concurrent runs delete each other's files — or a file of the
     * project's own that happened to carry it.
     */
    public function testEachRehearsalOwnsItsScratchFiles(): void
    {
        $this->writeProject(
            declared:  ['semitexa/core' => '2026.09.24.1147'],
            locked:    ['semitexa/core' => '2026.09.24.1147'],
            installed: ['semitexa/core' => '2026.09.24.1147'],
        );
        file_put_contents($this->projectRoot . '/composer.semitexa-update-plan.json', 'the project\'s own file');
        $resolver = FakeResolver::withReleaseSet('2026.09.28.0444', ['semitexa/core' => '2026.09.27.0404']);
        $executor = new FakeExecutor(true);
        $runner = new ComposerUpdateRunner($executor, $resolver);

        $runner->execute($this->projectRoot, dryRun: true);
        $first = $executor->lastEnv['COMPOSER'] ?? '';
        $runner->execute($this->projectRoot, dryRun: true);
        $second = $executor->lastEnv['COMPOSER'] ?? '';

        self::assertMatchesRegularExpression('/^composer\.semitexa-update-plan-[0-9a-f]{12}\.json$/', $first);
        self::assertNotSame($first, $second);
        self::assertSame('the project\'s own file', file_get_contents($this->projectRoot . '/composer.semitexa-update-plan.json'));
    }

    /**
     * @return list<string>
     */
    private function rootEntries(): array
    {
        $entries = array_values(array_diff(scandir($this->projectRoot) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    private function writeProject(
        array $declared,
        array $locked,
        array $installed,
        array $pathRepoNames = [],
    ): void {
        file_put_contents(
            $this->projectRoot . '/composer.json',
            json_encode(['require' => $declared], JSON_PRETTY_PRINT) . "\n",
        );

        $lockPackages = [];
        foreach ($locked as $name => $version) {
            $entry = ['name' => $name, 'version' => $version];
            if (in_array($name, $pathRepoNames, true)) {
                $entry['dist'] = ['type' => 'path', 'url' => 'packages/' . str_replace('semitexa/', 'semitexa-', $name)];
            }
            $lockPackages[] = $entry;
        }
        file_put_contents(
            $this->projectRoot . '/composer.lock',
            json_encode(['packages' => $lockPackages, 'packages-dev' => []]),
        );

        $installedPackages = [];
        foreach ($installed as $name => $version) {
            $entry = ['name' => $name, 'version' => $version];
            if (in_array($name, $pathRepoNames, true)) {
                $entry['dist'] = ['type' => 'path', 'url' => '../../packages/' . str_replace('semitexa/', 'semitexa-', $name)];
            }
            $installedPackages[] = $entry;
        }
        file_put_contents(
            $this->projectRoot . '/vendor/composer/installed.json',
            json_encode(['packages' => $installedPackages]),
        );
    }

    private function rrm(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }
}

class FakeExecutor implements ComposerExecutorInterface
{
    public int $callCount = 0;
    /** @var list<string> */
    public array $lastArgs = [];
    /** @var array<string, string> */
    public array $lastEnv = [];

    /**
     * @param array{exitCode: int, output: string} $runReturns
     */
    public function __construct(
        private readonly bool $available,
        public array $runReturns = ['exitCode' => 0, 'output' => ''],
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function containerError(): string
    {
        return $this->available ? '' : 'Test executor reports not-in-container.';
    }

    public function run(array $args, string $projectRoot, array $env = []): array
    {
        $this->callCount++;
        $this->lastArgs = $args;
        $this->lastEnv = $env;
        return $this->runReturns;
    }
}

/**
 * A fake that does what `composer update` does: brings composer.lock and
 * vendor/composer/installed.json into line with the constraints in
 * composer.json.
 *
 * The plain FakeExecutor returns an exit code and touches nothing, which was
 * fine while the runner reported the pins IT rewrote. Now that the runner
 * reports what actually moved, a fake that changes nothing means nothing
 * moved — and a test asserting an update against it would be asserting the
 * very fiction this class exists to remove.
 */
class ApplyingFakeExecutor extends FakeExecutor
{
    public function run(array $args, string $projectRoot, array $env = []): array
    {
        $this->callCount++;
        $this->lastArgs = $args;

        $declared = json_decode((string) file_get_contents($projectRoot . '/composer.json'), true)['require'] ?? [];

        foreach (['/composer.lock' => ['packages', 'packages-dev'], '/vendor/composer/installed.json' => ['packages']] as $file => $buckets) {
            $path = $projectRoot . $file;
            $data = json_decode((string) file_get_contents($path), true);
            foreach ($buckets as $bucket) {
                if (!isset($data[$bucket]) || !is_array($data[$bucket])) {
                    continue;
                }
                foreach ($data[$bucket] as &$pkg) {
                    $want = $declared[$pkg['name']] ?? null;
                    // Only concrete pins resolve to themselves; "*" and dev
                    // constraints are left where they are, as composer would
                    // when nothing newer is published.
                    if (is_string($want) && preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $want) === 1) {
                        $pkg['version'] = $want;
                    }
                }
                unset($pkg);
            }
            file_put_contents($path, json_encode($data));
        }

        return $this->runReturns;
    }
}

/**
 * A package absent from `$versionsByPackage` is UNREACHABLE (null) — the state
 * that blocks. Map it to [] to say "the registry answered: nothing published".
 * semitexa/ultimate is the exception: unconfigured, it is unpublished.
 */
final class FakeResolver implements UpstreamVersionResolverInterface
{
    /**
     * @param array<string, list<string>|null> $versionsByPackage newest first; null = unreachable
     * @param array<string, array<string, array<string, string>>> $requires package → version → require map
     */
    public function __construct(
        private readonly array $versionsByPackage,
        private readonly array $requires = [],
    ) {
    }

    public function stableVersions(string $package): ?array
    {
        if (array_key_exists($package, $this->versionsByPackage)) {
            return $this->versionsByPackage[$package];
        }

        // Ultimate unconfigured means "not published", so a test that is not
        // about the release set falls back to each package's own latest.
        // Map it to null to make the registry unreachable for it.
        return $package === 'semitexa/ultimate' ? [] : null;
    }

    public function requiresOf(string $package, string $version): ?array
    {
        return $this->requires[$package][$version] ?? null;
    }

    /**
     * A resolver whose semitexa/ultimate latest release pins `$set`.
     *
     * @param array<string, string> $set
     * @param array<string, list<string>> $versionsByPackage
     */
    public static function withReleaseSet(string $ultimate, array $set, array $versionsByPackage = []): self
    {
        return new self(
            ['semitexa/ultimate' => [$ultimate]] + $versionsByPackage,
            ['semitexa/ultimate' => [$ultimate => ['php' => '^8.4'] + $set]],
        );
    }
}
