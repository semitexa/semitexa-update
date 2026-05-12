<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

/**
 * Runs `composer` inside the appropriate execution context.
 *
 * Production implementation refuses to execute outside the Semitexa app
 * container so `bin/semitexa update` can never accidentally mutate a
 * project's vendor tree from the host. Tests inject a fake.
 */
interface ComposerExecutorInterface
{
    /**
     * True iff this executor is currently in a position to actually invoke
     * composer. Production: equivalent to "running inside the Semitexa
     * container with composer on PATH". When this returns false the runner
     * should refuse to mutate anything and surface `$containerError()` to
     * the operator.
     */
    public function isAvailable(): bool;

    /**
     * Human-friendly explanation of why `isAvailable()` returned false,
     * or empty string when it returned true.
     */
    public function containerError(): string;

    /**
     * Execute `composer <args>` inside the current execution context.
     *
     * @param list<string> $args
     * @return array{exitCode: int, output: string}
     */
    public function run(array $args, string $projectRoot): array;
}
