<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * --force-reinitialize removes the deploy path. Only the 24.04 bootstrap
 * refused a relative path, traversal or a system directory; 20.04 and 22.04
 * would have run rm -rf on whatever they were given, and none of them
 * refused a directory that held something else (/var/lib, a home).
 *
 * The scripts run with a PATH of a readlink shim, an `id` that says root, and
 * ls only: a guard that regressed reaches apt-get and the rest, which are not
 * there, instead of provisioning the test machine.
 */
final class RemoteBootstrapPathGuardTest extends TestCase
{
    private string $bin;

    protected function setUp(): void
    {
        $this->bin = sys_get_temp_dir() . '/semitexa-bootstrap-guard-' . bin2hex(random_bytes(6));
        mkdir($this->bin);
        // GNU `readlink -m` normalises; the paths below already are, so the
        // shim (a busybox image has no -m) prints its last argument.
        file_put_contents($this->bin . '/readlink', "#!/bin/sh\nfor a; do last=\$a; done\nprintf '%s\\n' \"\$last\"\n");
        chmod($this->bin . '/readlink', 0755);
        // The reinitialize guard lists the directory as root, so it runs after
        // the sudo check: as "root", the script runs ls itself.
        file_put_contents($this->bin . '/id', "#!/bin/sh\necho 0\n");
        chmod($this->bin . '/id', 0755);
        $ls = trim((string) shell_exec('command -v ls'));
        symlink($ls, $this->bin . '/ls');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->bin));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function unsafePaths(): iterable
    {
        foreach (['20.04', '22.04', '24.04'] as $version) {
            yield "$version system dir" => [$version, '/etc', 'Refusing unsafe deploy path'];
            yield "$version root" => [$version, '/', 'Refusing unsafe deploy path'];
            yield "$version relative" => [$version, 'srv/app', 'must be an absolute path'];
            yield "$version traversal" => [$version, '/srv/app/../../etc', 'must not contain traversal'];
        }
    }

    #[Test]
    #[DataProvider('unsafePaths')]
    public function every_bootstrap_refuses_an_unsafe_deploy_path(string $version, string $path, string $message): void
    {
        [$exit, $output] = $this->bootstrap($version, $path);

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString($message, $output);
    }

    /** @return iterable<string, array{string}> */
    public static function versions(): iterable
    {
        foreach (['20.04', '22.04', '24.04'] as $version) {
            yield $version => [$version];
        }
    }

    #[Test]
    #[DataProvider('versions')]
    public function reinitializing_refuses_a_directory_an_earlier_bootstrap_did_not_make(string $version): void
    {
        $dir = sys_get_temp_dir() . '/semitexa-not-a-deploy-' . bin2hex(random_bytes(6));
        mkdir($dir);
        touch($dir . '/somebody-elses-file');

        try {
            [$exit, $output] = $this->bootstrap($version, $dir, ['SEMITEXA_FORCE_REINITIALIZE' => '1']);

            self::assertSame(1, $exit, $output);
            self::assertStringContainsString('Refusing to reinitialize', $output);
            self::assertFileExists($dir . '/somebody-elses-file');
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /** A directory this user may enter but not read listed as empty, and the guard let it through to rm -rf. */
    #[Test]
    #[DataProvider('versions')]
    public function reinitializing_refuses_a_directory_it_cannot_list(string $version): void
    {
        // As root the listing works, shows the file, and refuses all the same.
        $dir = sys_get_temp_dir() . '/semitexa-unlistable-' . bin2hex(random_bytes(6));
        mkdir($dir);
        touch($dir . '/somebody-elses-file');
        chmod($dir, 0311);

        try {
            [$exit, $output] = $this->bootstrap($version, $dir, ['SEMITEXA_FORCE_REINITIALIZE' => '1']);

            self::assertSame(1, $exit, $output);
            self::assertStringContainsString('Refusing to reinitialize', $output);
        } finally {
            chmod($dir, 0755);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private function bootstrap(string $version, string $path, array $env = []): array
    {
        $script = dirname(__DIR__, 2) . "/resources/remote-deploy/ubuntu/{$version}/bootstrap.sh";
        $vars = ['PATH' => $this->bin, 'SEMITEXA_DEPLOY_PATH' => $path, 'SEMITEXA_ARTIFACT_PATH' => '/nonexistent'] + $env;
        $prefix = implode(' ', array_map(static fn (string $k, string $v): string => $k . '=' . escapeshellarg($v), array_keys($vars), $vars));
        exec(sprintf('env -i %s %s %s 2>&1', $prefix, escapeshellarg(trim((string) shell_exec('command -v bash'))), escapeshellarg($script)), $output, $exit);

        return [$exit, implode("\n", $output)];
    }
}
