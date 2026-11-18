<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-setup-decides-from-composer-json-not-the-marker: the scaffold's setup
 * service bootstraps a project on its first `docker compose up`. It decided by
 * its marker (var/install/bootstrap-complete.json). A clone that carried the
 * marker never got vendor/, so the app crash-looped. A project without the
 * marker had the skeleton copied over it: composer.json, composer.lock and
 * README were overwritten (a consumer's server deploy, 16.11). Now
 * composer.json decides: a project is never overwritten, and a project without
 * vendor/ gets its own dependencies installed in place.
 *
 * The test runs the service's own script, as shipped in this package's
 * scaffold copy, against a throwaway directory with a composer stub that
 * records each call.
 */
final class SetupNeverOverwritesAProjectTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/setup-service-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/target', 0777, true);
        mkdir($this->tmp . '/bin');
        file_put_contents($this->tmp . '/bin/composer', <<<'SH'
            #!/bin/sh
            printf '%s\n' "$*" >> "$COMPOSER_STUB_LOG"
            case "$1" in
              install)
                for a in "$@"; do case "$a" in --working-dir=*) dir="${a#--working-dir=}" ;; esac; done
                mkdir -p "$dir/vendor" && echo '<?php' > "$dir/vendor/autoload.php"
                # A script hook that fails after the autoloader was written.
                [ "${COMPOSER_STUB_FAIL_INSTALL:-}" != 1 ] || exit 1 ;;
              create-project)
                mkdir -p "$3" && echo '{"name": "semitexa/ultimate"}' > "$3/composer.json" && echo 'the skeleton' > "$3/README.md"
                # The skeleton carries its own copies of the installer's files.
                echo "the skeleton's Dockerfile" > "$3/Dockerfile" && echo "the skeleton's .env.default" > "$3/.env.default" ;;
            esac
            exit 0
            SH);
        chmod($this->tmp . '/bin/composer', 0755);
        // A copy cut off midway: the skeleton's files are written over the target,
        // then cp fails, once, as a container stopped mid-copy would leave it.
        file_put_contents($this->tmp . '/bin/cp', <<<'SH'
            #!/bin/sh
            /bin/cp "$@"; rc=$?
            case "$*" in
              */temp/.*) if [ -n "${CP_STUB_FAIL_ONCE:-}" ] && [ ! -e "$CP_STUB_FAIL_ONCE" ]; then : > "$CP_STUB_FAIL_ONCE"; exit 1; fi ;;
            esac
            exit $rc
            SH);
        chmod($this->tmp . '/bin/cp', 0755);
        // Ownership cannot change without root: the stub records what setup hands back.
        file_put_contents($this->tmp . '/bin/chown', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$CHOWN_STUB_LOG\"\n");
        chmod($this->tmp . '/bin/chown', 0755);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    #[Test]
    public function a_clone_without_vendor_gets_its_own_dependencies_and_keeps_its_files(): void
    {
        $this->project(withMarker: true, withVendor: false);

        [$exit, $out] = $this->runSetup();

        self::assertSame(0, $exit, $out);
        self::assertSame(['install --working-dir=' . $this->target() . ' --no-interaction --prefer-dist --optimize-autoloader'], $this->composerCalls());
        self::assertFileExists($this->target() . '/vendor/autoload.php', 'the app can start');
        $this->assertTheProjectIsUntouched();
    }

    #[Test]
    public function a_project_without_the_marker_is_never_overwritten(): void
    {
        $this->project(withMarker: false, withVendor: true);

        [$exit, $out] = $this->runSetup();

        self::assertSame(0, $exit, $out);
        self::assertSame([], $this->composerCalls(), 'no skeleton is fetched');
        $this->assertTheProjectIsUntouched();
        self::assertFileExists($this->target() . '/var/install/bootstrap-complete.json');
    }

    #[Test]
    public function an_install_that_fails_leaves_no_autoloader_to_read_as_done(): void
    {
        $this->project(withMarker: true, withVendor: false);

        [$exit, $out] = $this->runSetup(['COMPOSER_STUB_FAIL_INSTALL' => '1']);

        self::assertSame(2, $exit, $out);
        self::assertStringContainsString('composer install failed', $out);
        self::assertFileDoesNotExist($this->target() . '/vendor/autoload.php', 'the next start installs again');
        $this->assertTheProjectIsUntouched();
    }

    #[Test]
    public function a_skeleton_copy_cut_off_midway_is_done_again(): void
    {
        // What an interrupted first install leaves: the skeleton's composer.json, no marker.
        file_put_contents($this->target() . '/composer.json', '{"name": "semitexa/ultimate"}' . "\n");
        mkdir($this->target() . '/var/install', 0777, true);
        touch($this->target() . '/var/install/bootstrap-copying');

        [$exit, $out] = $this->runSetup();

        self::assertSame(0, $exit, $out);
        self::assertStringStartsWith('create-project semitexa/ultimate ', $this->composerCalls()[0] ?? '', 'the bootstrap runs again');
        self::assertFileExists($this->target() . '/README.md', 'the rest of the skeleton is there now');
        self::assertFileExists($this->target() . '/var/install/bootstrap-complete.json');
        self::assertFileDoesNotExist($this->target() . '/var/install/bootstrap-copying');
    }

    /**
     * tk-setup-retry-keeps-the-first-backup (CodeRabbit, installer#101): the
     * backup of the installer's own files was in the container's /tmp, and
     * fail() and every retry removed it, so the retry backed up what the
     * cut-off copy had written over them and "restored" the skeleton's.
     */
    #[Test]
    public function a_retry_after_a_copy_cut_off_midway_restores_the_installers_files(): void
    {
        file_put_contents($this->target() . '/Dockerfile', "the installer's Dockerfile\n");
        $failOnce = ['CP_STUB_FAIL_ONCE' => $this->tmp . '/cp-failed'];

        [$exit, $out] = $this->runSetup($failOnce);
        self::assertSame(2, $exit, 'the first try stops at the copy: ' . $out);
        self::assertSame("the skeleton's Dockerfile\n", file_get_contents($this->target() . '/Dockerfile'), 'the cut-off copy wrote over it');
        self::assertFileExists($this->target() . '/var/install/bootstrap-copying');

        [$exit, $out] = $this->runSetup($failOnce);
        self::assertSame(0, $exit, $out);
        self::assertSame("the installer's Dockerfile\n", file_get_contents($this->target() . '/Dockerfile'), 'the retry restores the first try\'s backup');
        self::assertFileExists($this->target() . '/var/install/bootstrap-complete.json');
        self::assertFileDoesNotExist($this->target() . '/var/install/bootstrap-copying');
        self::assertDirectoryDoesNotExist($this->target() . '/var/install/bootstrap-backup', 'removed once the install is done');
    }

    /**
     * Fable's review of the fix: the kept backup must not undo an edit made
     * between the tries. A file that still reads as the skeleton's was written
     * over by the cut-off copy and comes from the first try's backup; one the
     * developer changed since is taken as it stands.
     */
    #[Test]
    public function an_edit_made_between_the_tries_is_kept(): void
    {
        file_put_contents($this->target() . '/Dockerfile', "the installer's Dockerfile\n");
        file_put_contents($this->target() . '/.env.default', "the installer's .env.default\n");
        $failOnce = ['CP_STUB_FAIL_ONCE' => $this->tmp . '/cp-failed'];

        [$exit, $out] = $this->runSetup($failOnce);
        self::assertSame(2, $exit, $out);
        // Between the tries the developer pins a release in .env.default.
        file_put_contents($this->target() . '/.env.default', "SEMITEXA_ULTIMATE_VERSION=2026.11.17.2136\n");

        [$exit, $out] = $this->runSetup($failOnce);
        self::assertSame(0, $exit, $out);
        self::assertSame("the installer's Dockerfile\n", file_get_contents($this->target() . '/Dockerfile'), 'written over by the cut-off copy: from the first try');
        self::assertSame("SEMITEXA_ULTIMATE_VERSION=2026.11.17.2136\n", file_get_contents($this->target() . '/.env.default'), 'edited since: as it stands');
    }

    #[Test]
    public function the_runtime_directories_a_run_makes_go_to_the_directory_owner(): void
    {
        $this->project(withMarker: true, withVendor: true);
        $owner = fileowner($this->target()) . ':' . filegroup($this->target());

        [$exit, $out] = $this->runSetup();

        self::assertSame(0, $exit, $out);
        self::assertContains('-R ' . $owner . ' ' . $this->target() . '/var/log', $this->chownCalls(), 'the first log line made var/log, as root');
        self::assertNotContains('-R ' . $owner . ' ' . $this->target() . '/var', $this->chownCalls(), 'var/ was there before');
    }

    #[Test]
    public function the_runtime_directories_go_back_even_when_the_install_fails(): void
    {
        $this->project(withMarker: false, withVendor: false);
        $owner = fileowner($this->target()) . ':' . filegroup($this->target());

        [$exit, $out] = $this->runSetup(['COMPOSER_STUB_FAIL_INSTALL' => '1']);

        self::assertSame(2, $exit, $out);
        // On the next start var/ is no longer new: handed back now, or never.
        self::assertContains('-R ' . $owner . ' ' . $this->target() . '/var', $this->chownCalls());
    }

    #[Test]
    public function an_empty_directory_gets_the_skeleton(): void
    {
        [$exit, $out] = $this->runSetup();

        self::assertSame(0, $exit, $out);
        self::assertStringStartsWith('create-project semitexa/ultimate ', $this->composerCalls()[0] ?? '');
        self::assertSame('{"name": "semitexa/ultimate"}' . "\n", file_get_contents($this->target() . '/composer.json'));
        self::assertFileExists($this->target() . '/var/install/bootstrap-complete.json');
    }

    private function project(bool $withMarker, bool $withVendor): void
    {
        $target = $this->target();
        file_put_contents($target . '/composer.json', '{"name": "acme/guard"}' . "\n");
        file_put_contents($target . '/composer.lock', '{"packages": []}' . "\n");
        file_put_contents($target . '/README.md', "Guard\n");
        if ($withMarker) {
            mkdir($target . '/var/install', 0777, true);
            file_put_contents($target . '/var/install/bootstrap-complete.json', '{"status": "ok"}' . "\n");
        }
        if ($withVendor) {
            mkdir($target . '/vendor');
            file_put_contents($target . '/vendor/autoload.php', "<?php\n");
        }
    }

    private function assertTheProjectIsUntouched(): void
    {
        self::assertSame('{"name": "acme/guard"}' . "\n", file_get_contents($this->target() . '/composer.json'));
        self::assertSame('{"packages": []}' . "\n", file_get_contents($this->target() . '/composer.lock'));
        self::assertSame("Guard\n", file_get_contents($this->target() . '/README.md'));
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private function runSetup(array $env = []): array
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 4) . '/resources/scaffold/docker-compose.yml');
        self::assertSame(
            1,
            preg_match('/\n  setup:\n[\s\S]*?command:\n      - sh\n      - -euc\n      - \|\n((?: {8}[^\n]*\n|\n)+)/', $compose, $m),
            'the setup service runs one sh -euc script',
        );
        $script = str_replace('$$', '$', (string) preg_replace('/^ {8}/m', '', $m[1]));
        // The backup lives under TARGET_DIR (var/install/bootstrap-backup), so it follows the target.
        $script = str_replace(
            ['TARGET_DIR=/var/www/html', 'TEMP_DIR=/tmp/semitexa-bootstrap'],
            ['TARGET_DIR=' . $this->target(), 'TEMP_DIR=' . $this->tmp . '/temp'],
            $script,
        );
        self::assertStringContainsString('TARGET_DIR=' . $this->target(), $script, 'the script was pointed at the test target');
        file_put_contents($this->tmp . '/setup.sh', $script);

        $command = 'PATH=' . escapeshellarg($this->tmp . '/bin:' . getenv('PATH'))
            . ' COMPOSER_STUB_LOG=' . escapeshellarg($this->tmp . '/composer.log')
            . ' CHOWN_STUB_LOG=' . escapeshellarg($this->tmp . '/chown.log')
            . ' SEMITEXA_GIT_INIT=0';
        foreach ($env as $name => $value) {
            $command .= ' ' . $name . '=' . escapeshellarg($value);
        }
        $command .= ' sh -eu ' . escapeshellarg($this->tmp . '/setup.sh') . ' 2>&1';
        exec($command, $lines, $exit);

        return [$exit, implode("\n", $lines)];
    }

    /** @return list<string> */
    private function composerCalls(): array
    {
        $log = $this->tmp . '/composer.log';
        if (!is_file($log)) {
            return [];
        }
        $lines = file($log, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            self::fail('cannot read ' . $log);
        }

        return $lines;
    }

    /** @return list<string> */
    private function chownCalls(): array
    {
        $log = $this->tmp . '/chown.log';
        if (!is_file($log)) {
            return [];
        }
        $lines = file($log, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            self::fail('cannot read ' . $log);
        }

        return $lines;
    }

    private function target(): string
    {
        return $this->tmp . '/target';
    }
}
