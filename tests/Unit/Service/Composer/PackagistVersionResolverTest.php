<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Composer;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Composer\PackagistVersionResolver;

final class PackagistVersionResolverTest extends TestCase
{
    public function testA404IsAnAnswerThePackageIsNotPublished(): void
    {
        self::assertSame([], $this->resolver(['semitexa/site' => ''])->stableVersions('semitexa/site'));
    }

    public function testAnUnreachableRegistryIsNotAnAnswer(): void
    {
        self::assertNull($this->resolver(['semitexa/core' => null])->stableVersions('semitexa/core'));
    }

    /**
     * A truncated body or a proxy page served with 200 used to be cached as
     * "not published", so the planner left an exact pin alone and carried on.
     */
    public function testABodyThatIsNotThePackagesMetadataIsNotAnAnswer(): void
    {
        $resolver = $this->resolver([
            'semitexa/core' => '{"packages":{"semitexa/co',
            'semitexa/orm'  => '<html>502 Bad Gateway</html>',
            'semitexa/api'  => '{"packages":{}}',
        ]);

        self::assertNull($resolver->stableVersions('semitexa/core'));
        self::assertNull($resolver->stableVersions('semitexa/orm'));
        self::assertNull($resolver->stableVersions('semitexa/api'));
    }

    /** p2 is minified: a later row carries only what changed, so require must be read after expansion. */
    public function testReadsStableVersionsAndTheRequireOfAMinifiedRow(): void
    {
        $body = json_encode(['packages' => ['semitexa/ultimate' => [
            ['version' => '2026.09.28.0444', 'require' => ['php' => '^8.4', 'semitexa/core' => '2026.09.27.0404']],
            ['version' => '2026.09.27.0404'],
            ['version' => 'dev-master'],
        ]]], JSON_THROW_ON_ERROR);
        $resolver = $this->resolver(['semitexa/ultimate' => $body]);

        self::assertSame(['2026.09.28.0444', '2026.09.27.0404'], $resolver->stableVersions('semitexa/ultimate'));
        self::assertSame(
            ['php' => '^8.4', 'semitexa/core' => '2026.09.27.0404'],
            $resolver->requiresOf('semitexa/ultimate', '2026.09.27.0404'),
        );
        self::assertNull($resolver->requiresOf('semitexa/ultimate', '2026.01.01.0000'));
    }

    /**
     * @param array<string, string|null> $bodies package → body
     */
    private function resolver(array $bodies): PackagistVersionResolver
    {
        return new PackagistVersionResolver(
            fetchBody: static function (string $url) use ($bodies): ?string {
                foreach ($bodies as $package => $body) {
                    if (str_ends_with($url, '/' . $package . '.json')) {
                        return $body;
                    }
                }
                self::fail('Unexpected URL ' . $url);
            },
        );
    }
}
