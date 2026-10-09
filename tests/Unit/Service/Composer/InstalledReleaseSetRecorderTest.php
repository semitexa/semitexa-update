<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Composer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\FrameworkVersion;
use Semitexa\Update\Application\Service\Composer\InstalledReleaseSetRecorder;
use Semitexa\Update\Application\Service\Composer\UpstreamVersionResolverInterface;

/**
 * Which ultimate release vendor/ holds. The fixture is the 2026-10-09 cut: it
 * re-tagged dev, update and platform-ui and left core on 2026.10.08.0620.
 */
final class InstalledReleaseSetRecorderTest extends TestCase
{
    private const RELEASES = [
        '2026.10.08.0620' => [
            'php' => '^8.4',
            'semitexa/core' => '2026.10.08.0620',
            'semitexa/update' => '2026.10.08.0620',
            'semitexa/platform-ui' => '2026.10.08.0620',
        ],
        '2026.10.09.0714' => [
            'php' => '^8.4',
            'semitexa/core' => '2026.10.08.0620',
            'semitexa/update' => '2026.10.09.0714',
            'semitexa/platform-ui' => '2026.10.09.0714',
            'semitexa/crud' => '2026.10.09.0714',
        ],
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/release-set-recorder-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/vendor/composer', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_cut_that_did_not_retag_core_is_still_named(): void
    {
        $this->install([
            'semitexa/core' => '2026.10.08.0620',
            'semitexa/update' => '2026.10.09.0714',
            'semitexa/platform-ui' => '2026.10.09.0714',
        ]);

        // crud is pinned by the release and not installed here: it does not count against it.
        self::assertSame('2026.10.09.0714', $this->recorder()->record($this->root));
        self::assertSame('2026.10.09.0714', $this->recorded());
    }

    #[Test]
    public function a_package_behind_its_pin_means_the_release_is_not_installed(): void
    {
        $this->install([
            'semitexa/core' => '2026.10.08.0620',
            'semitexa/update' => '2026.10.09.0714',
            'semitexa/platform-ui' => '2026.10.08.0620',
        ]);

        self::assertSame('2026.10.08.0620', $this->recorder()->record($this->root));
    }

    #[Test]
    public function a_working_tree_drops_the_record(): void
    {
        FrameworkVersion::record('2026.10.08.0620', $this->root);
        $this->install(['semitexa/core' => 'dev-develop']);

        self::assertNull($this->recorder()->record($this->root));
        self::assertFileDoesNotExist($this->root . '/' . FrameworkVersion::RECORD_PATH);
    }

    #[Test]
    public function an_unreachable_registry_leaves_the_record_alone(): void
    {
        FrameworkVersion::record('2026.10.08.0620', $this->root);
        $this->install(['semitexa/core' => '2026.10.08.0620']);

        self::assertFalse($this->recorder(reachable: false)->record($this->root));
        self::assertSame('2026.10.08.0620', $this->recorded());
    }

    #[Test]
    public function a_release_listed_without_its_pins_leaves_the_record_alone(): void
    {
        FrameworkVersion::record('2026.10.08.0620', $this->root);
        $this->install(['semitexa/core' => '2026.10.08.0620']);

        self::assertFalse($this->recorder(releases: ['2026.10.10.0000' => null] + self::RELEASES)->record($this->root));
        self::assertSame('2026.10.08.0620', $this->recorded());
    }

    /** @param array<string, array<string, string>|null> $releases */
    private function recorder(bool $reachable = true, array $releases = self::RELEASES): InstalledReleaseSetRecorder
    {
        $resolver = new class ($releases, $reachable) implements UpstreamVersionResolverInterface {
            /** @param array<string, array<string, string>|null> $releases */
            public function __construct(private readonly array $releases, private readonly bool $reachable)
            {
            }

            public function stableVersions(string $package): ?array
            {
                return $this->reachable ? array_keys($this->releases) : null;
            }

            public function requiresOf(string $package, string $version): ?array
            {
                return $this->reachable ? ($this->releases[$version] ?? null) : null;
            }
        };

        return new InstalledReleaseSetRecorder($resolver);
    }

    /** @param array<string, string> $versions */
    private function install(array $versions): void
    {
        $packages = [];
        foreach ($versions as $name => $version) {
            $packages[] = ['name' => $name, 'version' => $version, 'dist' => ['type' => 'zip']];
        }
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => $packages]));
    }

    private function recorded(): ?string
    {
        $data = json_decode((string) @file_get_contents($this->root . '/' . FrameworkVersion::RECORD_PATH), true);
        return is_array($data) ? ($data['version'] ?? null) : null;
    }
}
