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
                mkdir -p "$dir/vendor" && echo '<?php' > "$dir/vendor/autoload.php" ;;
              create-project)
                mkdir -p "$3" && echo '{"name": "semitexa/ultimate"}' > "$3/composer.json" && echo 'the skeleton' > "$3/README.md" ;;
            esac
            exit 0
            SH);
        chmod($this->tmp . '/bin/composer', 0755);
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

    /** @return array{int, string} */
    private function runSetup(): array
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 4) . '/resources/scaffold/docker-compose.yml');
        self::assertSame(
            1,
            preg_match('/\n  setup:\n[\s\S]*?command:\n      - sh\n      - -euc\n      - \|\n((?: {8}[^\n]*\n|\n)+)/', $compose, $m),
            'the setup service runs one sh -euc script',
        );
        $script = str_replace('$$', '$', (string) preg_replace('/^ {8}/m', '', $m[1]));
        $script = str_replace(
            ['TARGET_DIR=/var/www/html', 'TEMP_DIR=/tmp/semitexa-bootstrap', 'BACKUP_DIR=/tmp/semitexa-bootstrap-backup'],
            ['TARGET_DIR=' . $this->target(), 'TEMP_DIR=' . $this->tmp . '/temp', 'BACKUP_DIR=' . $this->tmp . '/backup'],
            $script,
        );
        file_put_contents($this->tmp . '/setup.sh', $script);

        $command = 'PATH=' . escapeshellarg($this->tmp . '/bin:' . getenv('PATH'))
            . ' COMPOSER_STUB_LOG=' . escapeshellarg($this->tmp . '/composer.log')
            . ' SEMITEXA_GIT_INIT=0 sh -eu ' . escapeshellarg($this->tmp . '/setup.sh') . ' 2>&1';
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

    private function target(): string
    {
        return $this->tmp . '/target';
    }
}
