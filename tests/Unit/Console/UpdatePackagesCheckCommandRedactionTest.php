<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Update\Application\Console\Command\UpdatePackagesCheckCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * update:packages:check prints the configured private repository URL; the
 * credentials it may carry must never reach the output
 * (tk-update-private-git-tag-arg-injection).
 *
 * The URL used here has a space in it, so the guard refuses it before git
 * runs, and the source mode is "private", so Packagist is not asked: the
 * command touches no network. The project root is a throwaway directory.
 */
final class UpdatePackagesCheckCommandRedactionTest extends TestCase
{
    private const URL = 'https://deploybot:s3cr3t-pass@git.invalid/semitexa-core.git?private_token=t0k3n-value extra';
    private const ENV = [
        'SEMITEXA_AUTO_DEPLOY_SOURCE' => 'private',
        'SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL' => self::URL,
    ];

    private string $root = '';
    private string $previousCwd = '';
    /** @var array<string, string|false> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-check-redaction-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/modules', 0o777, true);
        file_put_contents($this->root . '/composer.json', '{}');

        foreach (self::ENV as $name => $value) {
            $this->previousEnv[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        $this->previousCwd = (string) getcwd();
        chdir($this->root);
        ProjectRoot::reset();

        // Stops here, before the command runs, if this process has a fixed root: it would read the real project.
        self::assertSame($this->root, ProjectRoot::get(), 'the command must run on the throwaway project root');
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== '') {
            chdir($this->previousCwd);
        }
        foreach ($this->previousEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        ProjectRoot::reset();
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /**
     * @return iterable<string, array{array<string, bool>}>
     */
    public static function modes(): iterable
    {
        yield 'text' => [[]];
        yield 'json' => [['--json' => true]];
    }

    /**
     * @param array<string, bool> $input
     */
    #[DataProvider('modes')]
    public function testOutputCarriesNoCredentialsOfThePrivateRepositoryUrl(array $input): void
    {
        $tester = new CommandTester(new UpdatePackagesCheckCommand());

        $tester->execute($input);
        // The text mode wraps long lines; the URL is looked for with the wrapping taken out.
        $output = (string) preg_replace('~\s+~', '', $tester->getDisplay());

        self::assertStringContainsString('https://***@git.invalid/semitexa-core.git?private_token=***', $output);
        self::assertStringContainsString('SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL', $output, 'the refusal warning is shown too');
        foreach (['deploybot', 's3cr3t', 't0k3n'] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }
}
