<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The installer writes the project path into quoted unit-file values: an
 * unquoted ExecStart split a path with a space, and systemd read % as a
 * specifier and $ as a variable.
 */
final class InstallAutoDeploySystemdEscapeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function escapable(): iterable
    {
        yield 'space' => ['/srv/My Project', '/srv/My Project'];
        yield 'percent' => ['/srv/100%', '/srv/100%%'];
        yield 'dollar' => ['/srv/a$b', '/srv/a$$b'];
        yield 'sed specials' => ['/srv/a&b|c', '/srv/a\\&b\\|c'];
    }

    #[Test]
    #[DataProvider('escapable')]
    public function a_path_is_escaped_for_a_unit_file(string $path, string $expected): void
    {
        [$exit, $out] = $this->escape($path);

        self::assertSame(0, $exit, $out);
        self::assertSame($expected, $out);
    }

    /**
     * WorkingDirectory= and Environment= read `$` literally: doubled there, a
     * path with one named another directory and the unit did not start.
     */
    #[Test]
    public function a_value_outside_exec_start_keeps_its_dollar(): void
    {
        [$exit, $out] = $this->escape('/srv/a$b 100%', 'escape_unit_value');

        self::assertSame(0, $exit, $out);
        self::assertSame('/srv/a$b 100%%', $out);
        self::assertNotSame(0, $this->escape('/srv/a"b', 'escape_unit_value')[0], 'refused as escape_unit refuses it');
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'double quote' => ['/srv/a"b'];
        yield 'backslash' => ['/srv/a\\b'];
        yield 'newline' => ["/srv/a\nb"];
    }

    #[Test]
    #[DataProvider('refused')]
    public function a_path_systemd_cannot_quote_is_refused(string $path): void
    {
        [$exit, $out] = $this->escape($path);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('Refusing a path systemd cannot quote', $out);
    }

    /** @return array{int, string} */
    private function escape(string $path, string $function = 'escape_unit'): array
    {
        $installer = dirname(__DIR__, 4) . '/tools/install-auto-deploy-systemd.sh';
        $script = 'set -euo pipefail; source <(sed -n "/^escape_sed()/,/^}/p;/^escape_unit()/,/^}/p;/^escape_unit_value()/,/^}/p" "$1"); out="$(' . $function . ' "$2")"; printf %s "$out"';
        exec(sprintf('bash -c %s _ %s %s 2>&1', escapeshellarg($script), escapeshellarg($installer), escapeshellarg($path)), $lines, $exit);

        return [$exit, implode("\n", $lines)];
    }
}
