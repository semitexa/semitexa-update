<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;

/**
 * A project is installed with four workers and Redis. Left at its default the
 * cache is one per worker, and a live run on a fresh project (2026-11-07) had 7
 * of 20 visitors refused by their own form: the one-time token was issued by one
 * worker and looked for in another.
 *
 * The cure lives in the Redis overlay, which bin/semitexa loads only when
 * REDIS_HOST is set: in .env.default it would name Redis for a project that has
 * none, and break its cache outright.
 */
final class ShippedEnvDefaultTest extends TestCase
{
    private function scaffold(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/resources/scaffold/' . $file);
    }

    public function test_the_redis_overlay_gives_the_app_and_the_scheduler_a_shared_cache(): void
    {
        self::assertSame(2, substr_count($this->scaffold('docker-compose.redis.yml'), 'CACHE_DRIVER: ${CACHE_DRIVER:-redis}'));
    }

    public function test_the_worker_gets_the_same_cache_as_the_app(): void
    {
        // .env first, then the caller's own CACHE_DRIVER, then Redis: what Compose gives the app.
        self::assertStringContainsString('echo "CACHE_DRIVER=$(env_val CACHE_DRIVER "${CACHE_DRIVER:-redis}")"', $this->scaffold('bin/semitexa'));
    }

    public function test_env_default_does_not_name_a_cache_a_project_may_not_have(): void
    {
        $default = $this->scaffold('.env.default');
        self::assertMatchesRegularExpression('/^APP_ENV=/m', $default, 'the shipped .env.default was read, not an empty string');
        self::assertDoesNotMatchRegularExpression('/^CACHE_DRIVER=/m', $default);
    }
}
