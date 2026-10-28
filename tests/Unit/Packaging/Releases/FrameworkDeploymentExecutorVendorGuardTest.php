<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentExecutor;

/**
 * The composer stage can succeed and still leave vendor/ untouched — a dist
 * that 404s on a private repository, an expired token, a process killed
 * mid-install. semitexa.com served an eight-day-old framework that way while
 * every auto-deploy run reported a clean result, because nothing compared
 * what was locked against what was installed.
 */
final class FrameworkDeploymentExecutorVendorGuardTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-vendor-guard-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/vendor/composer', 0777, true);
        file_put_contents($this->projectRoot . '/composer.json', '{"require":{"semitexa/core":"*"}}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectRoot));
    }

    public function testFailsWhenVendorStillCarriesThePreviousRelease(): void
    {
        $this->writeLock(['semitexa/core' => '2026.09.19.1020']);
        $this->writeInstalled(['semitexa/core' => '2026.09.14.0605']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/vendor\/ still disagrees with composer\.lock/');
        $this->expectExceptionMessageMatches('/semitexa\/core \(lock 2026\.09\.19\.1020, vendor 2026\.09\.14\.0605\)/');

        $this->guard();
    }

    public function testFailsWhenALockedPackageNeverReachedVendor(): void
    {
        $this->writeLock(['semitexa/core' => '2026.09.19.1020']);
        $this->writeInstalled([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/semitexa\/core \(lock 2026\.09\.19\.1020, vendor absent\)/');

        $this->guard();
    }

    public function testPassesWhenVendorMatchesTheLock(): void
    {
        $this->writeLock(['semitexa/core' => '2026.09.19.1020']);
        $this->writeInstalled(['semitexa/core' => '2026.09.19.1020']);

        $this->guard();

        $this->addToAssertionCount(1);
    }

    /**
     * Production legitimately spans several release dates — packages are
     * tagged as they change, not in lockstep — so a mixed set is not drift
     * and must not fail a deploy.
     */
    public function testAcceptsASetSpanningSeveralReleaseDates(): void
    {
        $this->writeLock([
            'semitexa/core' => '2026.09.19.1020',
            'semitexa/docs' => '2026.09.22.0645',
            'semitexa/api' => '2026.09.11.0529',
        ]);
        $this->writeInstalled([
            'semitexa/core' => '2026.09.19.1020',
            'semitexa/docs' => '2026.09.22.0645',
            'semitexa/api' => '2026.09.11.0529',
        ]);

        $this->guard();

        $this->addToAssertionCount(1);
    }

    /**
     * A vendor tree Composer has never written cannot testify either way;
     * reading that as "every package missing" would fail every first deploy.
     */
    public function testStaysSilentWhenVendorWasNeverInstalled(): void
    {
        $this->writeLock(['semitexa/core' => '2026.09.19.1020']);

        $this->guard();

        $this->addToAssertionCount(1);
    }

    /**
     * The deploy installs --no-dev: a project made from semitexa/ultimate
     * requires semitexa/testing for dev, and every deploy was rolled back
     * for it being "missing".
     */
    public function testARequireDevPackageLeftOutByANoDevInstallIsNotDrift(): void
    {
        file_put_contents($this->projectRoot . '/composer.lock', json_encode([
            'packages' => $this->packages(['semitexa/core' => '2026.09.19.1020']),
            'packages-dev' => $this->packages(['semitexa/testing' => '2026.09.19.1020']),
        ], JSON_THROW_ON_ERROR));
        $this->writeInstalled(['semitexa/core' => '2026.09.19.1020']);

        $this->guard();

        $this->addToAssertionCount(1);
    }

    /** With dev packages installed, one that is missing is still drift. */
    public function testARequireDevPackageMissingFromADevInstallStillFails(): void
    {
        file_put_contents($this->projectRoot . '/composer.lock', json_encode([
            'packages' => $this->packages(['semitexa/core' => '2026.09.19.1020']),
            'packages-dev' => $this->packages(['semitexa/testing' => '2026.09.19.1020']),
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->projectRoot . '/vendor/composer/installed.json',
            json_encode(['packages' => $this->packages(['semitexa/core' => '2026.09.19.1020']), 'dev' => true], JSON_THROW_ON_ERROR),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/semitexa\/testing \(lock 2026\.09\.19\.1020, vendor absent\)/');

        $this->guard();
    }

    /** The exemption is for require-dev only: a missing runtime package still fails a --no-dev install. */
    public function testARuntimePackageMissingFromANoDevInstallStillFails(): void
    {
        file_put_contents($this->projectRoot . '/composer.lock', json_encode([
            'packages' => $this->packages(['semitexa/core' => '2026.09.19.1020']),
            'packages-dev' => $this->packages(['semitexa/testing' => '2026.09.19.1020']),
        ], JSON_THROW_ON_ERROR));
        $this->writeInstalled([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/semitexa\/core \(lock 2026\.09\.19\.1020, vendor absent\)/');

        $this->guard();
    }

    /** An installed.json that does not say "dev": false is not taken for a --no-dev install. */
    public function testAnInstalledJsonWithoutTheDevFlagGetsNoExemption(): void
    {
        file_put_contents($this->projectRoot . '/composer.lock', json_encode([
            'packages' => $this->packages(['semitexa/core' => '2026.09.19.1020']),
            'packages-dev' => $this->packages(['semitexa/testing' => '2026.09.19.1020']),
        ], JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->projectRoot . '/vendor/composer/installed.json',
            json_encode(['packages' => $this->packages(['semitexa/core' => '2026.09.19.1020'])], JSON_THROW_ON_ERROR),
        );

        $this->expectException(RuntimeException::class);

        $this->guard();
    }

    private function guard(): void
    {
        $method = new ReflectionMethod(FrameworkDeploymentExecutor::class, 'assertVendorMatchesLock');
        $method->setAccessible(true);
        $method->invoke(new FrameworkDeploymentExecutor(), $this->projectRoot);
    }

    /**
     * @param array<string, string> $versions
     */
    private function writeLock(array $versions): void
    {
        file_put_contents(
            $this->projectRoot . '/composer.lock',
            json_encode(['packages' => $this->packages($versions)], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, string> $versions
     */
    private function writeInstalled(array $versions): void
    {
        file_put_contents(
            $this->projectRoot . '/vendor/composer/installed.json',
            json_encode(['packages' => $this->packages($versions), 'dev' => false], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  array<string, string> $versions
     * @return list<array<string, mixed>>
     */
    private function packages(array $versions): array
    {
        $packages = [];
        foreach ($versions as $name => $version) {
            $packages[] = ['name' => $name, 'version' => $version];
        }

        return $packages;
    }
}
