<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

/**
 * Production composer executor. Only runs when the current PHP process is
 * already inside the Semitexa app container (detected via /.dockerenv) and
 * the `composer` binary is on PATH.
 *
 * Routing into the container is the responsibility of the host-side
 * `bin/semitexa` shell wrapper — by the time this PHP code runs, the
 * wrapper has either `docker compose exec`-ed into the running app
 * container or `docker compose run --rm`-ed a one-off. Either way we land
 * here in-container; this class verifies that assumption and refuses to
 * run otherwise.
 */
final class InContainerComposerExecutor implements ComposerExecutorInterface
{
    public function __construct(
        private readonly string $dockerEnvMarker = '/.dockerenv',
    ) {
    }

    public function isAvailable(): bool
    {
        if (!is_file($this->dockerEnvMarker)) {
            return false;
        }
        return $this->locateComposer() !== null;
    }

    public function containerError(): string
    {
        if (!is_file($this->dockerEnvMarker)) {
            return 'Composer phase requires container execution (the host-side `bin/semitexa` '
                . 'wrapper normally routes into the app container). Run `bin/semitexa update` '
                . 'from the project root instead of invoking the PHP CLI directly.';
        }
        if ($this->locateComposer() === null) {
            return 'Composer binary not found on PATH inside the container. Rebuild the app '
                . 'image so `/usr/local/bin/composer` is present.';
        }
        return '';
    }

    /**
     * @param list<string> $args
     * @return array{exitCode: int, output: string}
     */
    public function run(array $args, string $projectRoot): array
    {
        $composer = $this->locateComposer();
        if ($composer === null) {
            return ['exitCode' => 127, 'output' => 'composer not found on PATH'];
        }
        $cmd = array_merge([$composer], $args);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $this->safeEnv();
        $proc = @proc_open($cmd, $descriptors, $pipes, $projectRoot, $env);
        if (!is_resource($proc)) {
            return ['exitCode' => 1, 'output' => 'Failed to spawn composer'];
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exitCode = proc_close($proc);
        return [
            'exitCode' => $exitCode,
            'output' => trim($stdout . ($stderr !== '' ? "\n" . $stderr : '')),
        ];
    }

    private function locateComposer(): ?string
    {
        $candidates = ['/usr/local/bin/composer', '/usr/bin/composer'];
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        // `which composer` fallback for unusual layouts.
        $located = @shell_exec('command -v composer 2>/dev/null');
        if (is_string($located)) {
            $located = trim($located);
            if ($located !== '' && is_executable($located)) {
                return $located;
            }
        }
        return null;
    }

    /**
     * @return array<string, string>
     */
    private function safeEnv(): array
    {
        // Pass through the parent environment but strip variables that can
        // make composer interactive or surprise the operator.
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['COMPOSER_NO_INTERACTION'] = '1';
        return $env;
    }
}
