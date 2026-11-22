<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\Releases\Source\ComposerVcsTagReleaseSource;
use Semitexa\Update\Application\Service\Packaging\Releases\Source\PrivateGitTagSource;

/**
 * A configured repository URL must never reach git as an option or as a
 * transport helper (tk-update-private-git-tag-arg-injection).
 *
 * Every test runs inside a throwaway repository whose origin is another
 * throwaway repository, with git limited to the file transport, so no code
 * path here, guarded or not, can reach a real remote.
 */
final class PrivateGitTagSourceUrlGuardTest extends TestCase
{
    private const TAG = '2026.01.02.0304';

    private string $root = '';
    private string $previousCwd = '';
    private string|false $previousAllow = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-git-guard-' . bin2hex(random_bytes(6));
        $origin = $this->root . '/origin';
        $work = $this->root . '/work';
        mkdir($origin, 0o777, true);
        mkdir($work, 0o777, true);

        $this->git($origin, 'init -q');
        $this->git($origin, '-c user.name=t -c user.email=t@git.invalid commit -q --allow-empty -m init');
        $this->git($origin, 'tag ' . self::TAG);
        $this->git($work, 'init -q');
        $this->git($work, 'remote add origin ' . escapeshellarg($origin));

        $this->previousCwd = (string) getcwd();
        chdir($work);
        $this->previousAllow = getenv('GIT_ALLOW_PROTOCOL');
        putenv('GIT_ALLOW_PROTOCOL=file');
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        putenv($this->previousAllow === false ? 'GIT_ALLOW_PROTOCOL' : 'GIT_ALLOW_PROTOCOL=' . $this->previousAllow);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'option that prints the origin' => ['--get-url'];
        // Names git's own default program: unguarded, git honours it and lists the origin's tags.
        yield 'option that names the remote program' => ['--upload-pack=git-upload-pack'];
        yield 'short option' => ['-q'];
        yield 'ext transport helper' => ['ext::/nonexistent/semitexa-never-run'];
        yield 'fd transport helper' => ['fd::17'];
        yield 'unknown scheme' => ['git://git.invalid/semitexa/semitexa-core.git'];
        yield 'relative path' => ['origin'];
        yield 'whitespace inside' => ['https://git.invalid/semitexa-core.git --upload-pack=git-upload-pack'];
        yield 'option as ssh user' => ['ssh://-oProxyCommand=x@git.invalid/semitexa-core.git'];
        yield 'option as scp host' => ['git@-oProxyCommand=x:semitexa-core.git'];
    }

    #[DataProvider('refusedUrls')]
    public function testUnsafeUrlIsRefusedBeforeGitRuns(string $url): void
    {
        $calls = [];
        $source = new PrivateGitTagSource($this->recorder($calls));

        $tag = $source->latestStableTag($url, 'SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL');

        self::assertNull($tag, 'an unsafe URL must yield no tag');
        self::assertSame([], $calls, 'git must not run for an unsafe URL');
        $warnings = $source->lastWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Refused repository URL', $warnings[0]);
        self::assertStringContainsString('SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL', $warnings[0]);
        self::assertStringContainsString('git was not run', $warnings[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ordinaryUrls(): iterable
    {
        yield 'https' => ['https://git.invalid/semitexa/semitexa-core.git'];
        yield 'https with credentials' => ['https://user:token@git.invalid/semitexa/semitexa-core.git'];
        yield 'ssh' => ['ssh://git@git.invalid:22/semitexa/semitexa-core.git'];
        yield 'scp-like ssh' => ['git@git.invalid:semitexa/semitexa-core.git'];
    }

    #[DataProvider('ordinaryUrls')]
    public function testOrdinaryUrlReachesGitAfterTheEndOfOptions(string $url): void
    {
        $calls = [];
        $source = new PrivateGitTagSource($this->recorder($calls));

        $tag = $source->latestStableTag($url);

        self::assertSame([['git', 'ls-remote', '--tags', '--', $url]], $calls);
        self::assertSame(self::TAG, $tag);
        self::assertSame([], $source->lastWarnings());
    }

    public function testLocalRepositoryIsReadByTheRealGit(): void
    {
        putenv('GIT_ALLOW_PROTOCOL');
        $source = new PrivateGitTagSource();

        self::assertSame(self::TAG, $source->latestStableTag($this->root . '/origin'));
        self::assertSame(self::TAG, $source->latestStableTag('file://' . $this->root . '/origin'));
        self::assertSame([], $source->lastWarnings());
    }

    public function testComposerRepositoryUrlIsRefusedAndNamesItsSetting(): void
    {
        $project = $this->root . '/project';
        mkdir($project);
        file_put_contents($project . '/composer.json', json_encode([
            'repositories' => [['type' => 'vcs', 'url' => '--refs/semitexa-ssr']],
        ], JSON_THROW_ON_ERROR));
        $calls = [];
        $source = new ComposerVcsTagReleaseSource(new PrivateGitTagSource($this->recorder($calls)));

        $updates = $source->discoverUpdates($project, ['semitexa/ssr' => '2026.01.01.0000']);

        self::assertSame([], $updates);
        self::assertSame([], $calls, 'git must not run for an unsafe URL');
        $warnings = $source->lastWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Refused repository URL "--refs/semitexa-ssr"', $warnings[0]);
        self::assertStringContainsString('composer.json repositories[].url', $warnings[0]);
    }

    /**
     * @param list<list<string>> $calls
     * @return \Closure(list<string>): array{int, list<string>}
     */
    private function recorder(array &$calls): \Closure
    {
        return static function (array $argv) use (&$calls): array {
            $calls[] = $argv;

            return [0, ["1111111111111111111111111111111111111111\trefs/tags/" . self::TAG]];
        };
    }

    private function git(string $directory, string $arguments): void
    {
        $output = [];
        exec(sprintf('git -C %s %s 2>&1', escapeshellarg($directory), $arguments), $output, $exitCode);
        self::assertSame(0, $exitCode, 'git ' . $arguments . ': ' . implode("\n", $output));
    }
}
