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

    /** Variables that make git act on another repository than the one `-C` names. */
    private const REPOSITORY_SELECTION = [
        'GIT_DIR',
        'GIT_WORK_TREE',
        'GIT_COMMON_DIR',
        'GIT_INDEX_FILE',
        'GIT_OBJECT_DIRECTORY',
        'GIT_ALTERNATE_OBJECT_DIRECTORIES',
        'GIT_PREFIX',
    ];

    private string $root = '';
    private string $previousCwd = '';
    private bool $allowProtocolChanged = false;
    private string|false $previousAllow = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-git-guard-' . bin2hex(random_bytes(6));
        $this->buildFixture($this->root);

        // State of the test process changes only after the fixture stands, and tearDown undoes what was changed.
        $this->previousAllow = getenv('GIT_ALLOW_PROTOCOL');
        putenv('GIT_ALLOW_PROTOCOL=file');
        $this->allowProtocolChanged = true;
        $this->previousCwd = (string) getcwd();
        chdir($this->root . '/work');
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== '') {
            chdir($this->previousCwd);
        }
        if ($this->allowProtocolChanged) {
            putenv($this->previousAllow === false ? 'GIT_ALLOW_PROTOCOL' : 'GIT_ALLOW_PROTOCOL=' . $this->previousAllow);
        }
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testFixtureLeavesTheRepositoryNamedByGitDirAlone(): void
    {
        $decoy = $this->root . '/decoy';
        mkdir($decoy);
        $this->git($decoy, ['init', '-q']);

        putenv('GIT_DIR=' . $decoy . '/.git');
        try {
            $this->buildFixture($this->root . '/second');
        } finally {
            putenv('GIT_DIR');
        }

        self::assertSame([], $this->git($decoy, ['tag']), 'the fixture tag must not land in the GIT_DIR repository');
        self::assertSame([], $this->git($decoy, ['remote']), 'the fixture remote must not land in the GIT_DIR repository');
        self::assertSame([self::TAG], $this->git($this->root . '/second/origin', ['tag']));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function urlsWithSecrets(): iterable
    {
        yield 'https userinfo' => ['https://deploybot:s3cr3t-pass@git.invalid/semitexa-core.git extra', ['deploybot', 's3cr3t']];
        yield 'ssh userinfo' => ['ssh://deploybot:s3cr3t-pass@git.invalid/semitexa-core.git extra', ['deploybot', 's3cr3t']];
        yield 'scp-like userinfo' => ['deploybot:s3cr3t-pass@git.invalid:semitexa-core.git extra', ['deploybot', 's3cr3t']];
        yield 'userinfo with a slash' => ['https://deploybot:s3cr3t/pass@git.invalid/semitexa-core.git extra', ['deploybot', 's3cr3t', '/pass']];
        yield 'query token' => ['https://git.invalid/semitexa-core.git?private_token=s3cr3t-pass&ref=x extra', ['s3cr3t']];
    }

    /**
     * @param list<string> $secrets
     */
    #[DataProvider('urlsWithSecrets')]
    public function testRefusalWarningCarriesNoCredentials(string $url, array $secrets): void
    {
        $calls = [];
        $source = new PrivateGitTagSource($this->recorder($calls));

        self::assertNull($source->latestStableTag($url, 'SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL'));

        self::assertSame([], $calls);
        $warnings = $source->lastWarnings();
        self::assertCount(1, $warnings);
        $warning = $warnings[0];
        self::assertStringContainsString('Refused repository URL', $warning);
        self::assertStringContainsString('git.invalid', $warning);
        self::assertStringContainsString('SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL', $warning);
        self::assertStringContainsString('it contains whitespace or control characters', $warning);
        self::assertNotSame([], $secrets);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $warning);
        }
    }

    public function testFailureWarningCarriesNoCredentialsFromTheUrlOrFromGit(): void
    {
        $url = 'https://deploybot:s3cr3t-pass@git.invalid/semitexa-core.git?private_token=t0k3n-value';
        $source = new PrivateGitTagSource(static fn(array $argv): array => [128, [
            "fatal: unable to access '" . $url . "/': Could not resolve host: git.invalid",
            'remote: https://other:0ther-s3cret@mirror.invalid/x.git?access_token=m1rr0r denied',
        ]]);

        self::assertNull($source->latestStableTag($url));

        $warnings = $source->lastWarnings();
        self::assertCount(1, $warnings);
        $warning = $warnings[0];
        self::assertStringContainsString('git ls-remote failed for https://***@git.invalid/semitexa-core.git?private_token=***', $warning);
        self::assertStringContainsString('Could not resolve host', $warning);
        self::assertStringContainsString('https://***@mirror.invalid/x.git?access_token=*** denied', $warning);
        foreach (['deploybot', 's3cr3t', 't0k3n', '0ther', 'm1rr0r'] as $secret) {
            self::assertStringNotContainsString($secret, $warning);
        }
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

    private function buildFixture(string $root): void
    {
        $origin = $root . '/origin';
        $work = $root . '/work';
        mkdir($origin, 0o777, true);
        mkdir($work, 0o777, true);

        $this->git($origin, ['init', '-q']);
        $this->git($origin, ['-c', 'user.name=t', '-c', 'user.email=t@git.invalid', 'commit', '-q', '--allow-empty', '-m', 'init']);
        $this->git($origin, ['tag', self::TAG]);
        $this->git($work, ['init', '-q']);
        $this->git($work, ['remote', 'add', 'origin', $origin]);
    }

    /**
     * Runs git in $directory with an environment of its own: the variables that
     * select a repository are left out, so the test process's are never touched
     * and never obeyed.
     *
     * @param list<string> $arguments
     * @return list<string> output lines
     */
    private function git(string $directory, array $arguments): array
    {
        $env = array_diff_key(getenv(), array_flip(self::REPOSITORY_SELECTION));
        $capture = tmpfile();
        self::assertIsResource($capture);

        $process = proc_open(['git', '-C', $directory, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => $capture, 2 => $capture], $pipes, null, $env);
        self::assertIsResource($process);
        $exitCode = proc_close($process);
        rewind($capture);
        $raw = rtrim((string) stream_get_contents($capture), "\n");
        fclose($capture);

        self::assertSame(0, $exitCode, 'git ' . implode(' ', $arguments) . ': ' . $raw);

        return $raw === '' ? [] : explode("\n", $raw);
    }
}
