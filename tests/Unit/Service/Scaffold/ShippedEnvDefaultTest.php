<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;

/**
 * A project is installed with four workers and Redis. Left at its default the
 * cache is one per worker, and a live run on a fresh project (2026-11-07) had 7
 * of 20 visitors refused by their own form: the one-time token was issued by one
 * worker and looked for in another.
 */
final class ShippedEnvDefaultTest extends TestCase
{
    public function test_a_project_with_redis_shares_its_cache_across_workers(): void
    {
        $env = (string) file_get_contents(dirname(__DIR__, 4) . '/resources/scaffold/.env.default');

        self::assertMatchesRegularExpression('/^REDIS_HOST=\S+/m', $env);
        self::assertMatchesRegularExpression('/^CACHE_DRIVER=redis$/m', $env);
    }
}
