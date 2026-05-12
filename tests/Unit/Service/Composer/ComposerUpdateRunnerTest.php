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

    public function testPlanIdentifiesPinKindsAndTargetsAnchor(): void
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

        $resolver = new FakeResolver([
            'semitexa/update' => ['2026.05.12.0744', '2026.05.10.1449'],
            'semitexa/core'   => ['2026.05.12.0744', '2026.05.08.1640'],
        ]);
        $executor = new FakeExecutor(available: true);
        $runner = new ComposerUpdateRunner($executor, $resolver);

        $plan = $runner->plan($this->projectRoot);

        self::assertSame('2026.05.12.0744', $plan->targetReleaseSet);
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

    public function testPlanFallsBackToPackagesOwnLatestWhenAnchorTagMissing(): void
    {
        $this->writeProject(
            declared: ['semitexa/update' => '2026.05.10.1449', 'semitexa/legacy' => '2026.05.08.1640'],
            locked: ['semitexa/update' => '2026.05.10.1449', 'semitexa/legacy' => '2026.05.08.1640'],
            installed: ['semitexa/update' => '2026.05.10.1449', 'semitexa/legacy' => '2026.05.08.1640'],
        );

        $resolver = new FakeResolver([
            'semitexa/update' => ['2026.05.12.0744'],
            'semitexa/legacy' => ['2026.05.09.0726'],  // anchor not present
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
        self::assertSame(0, $executor->callCount, 'Dry-run must not invoke composer.');
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
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => 'ok']);
        $runner = new ComposerUpdateRunner($executor, $resolver);

        $result = $runner->execute($this->projectRoot, dryRun: false);

        self::assertSame(1, $executor->callCount);
        self::assertSame(['update', 'semitexa/*', '-W', '--no-interaction'], $executor->lastArgs);

        $afterJson = json_decode((string) file_get_contents($this->projectRoot . '/composer.json'), true);
        self::assertSame('2026.05.12.0744', $afterJson['require']['semitexa/update']);
        self::assertSame('2026.05.12.0744', $afterJson['require']['semitexa/core']);

        // outcome depends on whether installed.json changed; we left it at the OLD version
        // (a real composer would have updated it; the fake didn't), so the runner sees no
        // installed version change → Updated, not UpdaterChanged.
        self::assertSame(ComposerUpdateOutcome::Updated, $result->outcome);
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
            public function run(array $args, string $projectRoot): array
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
        self::assertStringContainsString('Rerun', $result->message);
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
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => 'ok']);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false, allowPartial: true);

        self::assertSame(ComposerUpdateOutcome::Updated, $result->outcome);
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
        self::assertSame(0, $executor->callCount, 'Dry-run under --allow-partial still must not invoke composer.');
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
        $executor = new FakeExecutor(true, runReturns: ['exitCode' => 0, 'output' => '']);

        $result = (new ComposerUpdateRunner($executor, $resolver))
            ->execute($this->projectRoot, dryRun: false);

        self::assertSame(ComposerUpdateOutcome::Updated, $result->outcome, 'Path/dev packages must not block.');
        self::assertSame(1, $executor->callCount);
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

    public function run(array $args, string $projectRoot): array
    {
        $this->callCount++;
        $this->lastArgs = $args;
        return $this->runReturns;
    }
}

final class FakeResolver implements UpstreamVersionResolverInterface
{
    /**
     * @param array<string, list<string>> $versionsByPackage
     */
    public function __construct(
        private readonly array $versionsByPackage,
    ) {
    }

    public function latestStable(string $package): ?string
    {
        $versions = $this->versionsByPackage[$package] ?? [];
        return $versions[0] ?? null;
    }

    public function hasVersion(string $package, string $version): bool
    {
        return in_array($version, $this->versionsByPackage[$package] ?? [], true);
    }
}
