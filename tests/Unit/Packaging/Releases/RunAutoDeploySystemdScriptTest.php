<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\TestCase;

final class RunAutoDeploySystemdScriptTest extends TestCase
{
    public function testWrapperRunsFallbackRestartAndHealthcheck(): void
    {
        $server = $this->startHttpServer(200);
        $projectRoot = $this->createFakeProject($server);

        try {
            [$exitCode, $output] = $this->runWrapper($projectRoot);

            self::assertSame(0, $exitCode, $output);
            self::assertFileExists($projectRoot . '/var/server-started');
        } finally {
            $this->cleanupProject($projectRoot, $server);
        }
    }

    public function testWrapperFailsWhenFallbackHealthcheckFails(): void
    {
        $server = $this->startHttpServer(500);
        $projectRoot = $this->createFakeProject($server);

        try {
            [$exitCode, $output] = $this->runWrapper($projectRoot);

            self::assertNotSame(0, $exitCode, $output);
            self::assertStringContainsString('Health check failed', $output);
            self::assertFileExists($projectRoot . '/var/server-started');
            self::assertFileExists($projectRoot . '/var/rollback-asked', 'a failed health check asks for the rollback');
            self::assertStringContainsString('The failing release is still live', $output);
        } finally {
            $this->cleanupProject($projectRoot, $server);
        }
    }

    /**
     * The host has no PHP: the wrapper parsed the JSON with `php -r` and
     * failed on every tick there. A noop must not restart anything.
     */
    public function testANoopRestartsNothingAndNeedsNoPhpOnTheHost(): void
    {
        $server = $this->startHttpServer(200);
        $projectRoot = $this->createFakeProject($server, null, ['status' => 'noop', 'restart_required' => false, 'reason' => 'pinned "x", see {y}']);

        try {
            $path = $projectRoot . '/nophp';
            mkdir($path);
            foreach (['bash', 'sed', 'grep', 'tail', 'head', 'curl', 'touch', 'sleep', 'env', 'printf'] as $tool) {
                $found = trim((string) shell_exec('command -v ' . $tool));
                if ($found !== '' && $found[0] === '/') {
                    symlink($found, $path . '/' . $tool);
                }
            }
            $command = sprintf('PATH=%s bash %s %s 2>&1', escapeshellarg($path), escapeshellarg(dirname(__DIR__, 4) . '/tools/run-auto-deploy-systemd.sh'), escapeshellarg($projectRoot));
            exec($command, $lines, $exitCode);

            self::assertSame(0, $exitCode, implode("\n", $lines));
            self::assertFileDoesNotExist($projectRoot . '/var/server-started');
        } finally {
            $this->cleanupProject($projectRoot, $server);
        }
    }

    /**
     * Rolled back inside the app container, where nothing restarts: the
     * workers ran the broken release until someone restarted by hand.
     */
    public function testAFailedDeployThatOwesARestartRestartsAndStillFails(): void
    {
        $server = $this->startHttpServer(200);
        $projectRoot = $this->createFakeProject($server, null, ['status' => 'failed', 'reason' => 'health check failed', 'restart_required' => true], autoExit: 1);

        try {
            [$exitCode, $output] = $this->runWrapper($projectRoot);

            self::assertSame(1, $exitCode, $output);
            self::assertFileExists($projectRoot . '/var/server-started');
        } finally {
            $this->cleanupProject($projectRoot, $server);
        }
    }

    public function testAFailedDeployThatOwesNoRestartRestartsNothing(): void
    {
        $server = $this->startHttpServer(200);
        $projectRoot = $this->createFakeProject($server, null, ['status' => 'failed', 'reason' => 'composer: could not resolve host', 'restart_required' => false], autoExit: 1);

        try {
            [$exitCode, $output] = $this->runWrapper($projectRoot);

            self::assertSame(1, $exitCode, $output);
            self::assertFileDoesNotExist($projectRoot . '/var/server-started');
        } finally {
            $this->cleanupProject($projectRoot, $server);
        }
    }

/** A health URL that is a password away from a leak: never printed, never in curl's argv. */
    public function testTheHealthUrlIsNeitherPrintedNorPassedOnTheCommandLine(): void
    {
        $server = $this->startHttpServer(500);
        $projectRoot = $this->createFakeProject($server);
        $check = sprintf('{"healthcheck_url":"http://user:p#ss@127.0.0.1:%d/?token=SECRET"}', $server['port']);
        $script = (string) file_get_contents($projectRoot . '/bin/semitexa');
        file_put_contents($projectRoot . '/bin/semitexa', (string) preg_replace('/(update:packages:check\)\n    printf .%s\\n. )\'[^\']*\'/', '$1\'' . $check . '\'', $script));
        self::assertStringContainsString('SECRET', (string) file_get_contents($projectRoot . '/bin/semitexa'), 'the check answers with the secret URL');

        try {
            [$exitCode, $output] = $this->runWrapper($projectRoot);

            self::assertSame(1, $exitCode, $output);
            self::assertStringContainsString(sprintf('Health check failed for http://127.0.0.1:%d/.', $server['port']), $output);
            self::assertStringNotContainsString('ss@', $output);
            self::assertStringNotContainsString('SECRET', $output);
        } finally {
            $this->cleanupProject($projectRoot, $server);
        }
    }

        public function testWrapperFailsWhenDeployCheckOutputIsMalformed(): void
    {
        $server = $this->startHttpServer(200);
        $projectRoot = $this->createFakeProject($server, 'notice: deprecated output');

        try {
            [$exitCode, $output] = $this->runWrapper($projectRoot);

            self::assertNotSame(0, $exitCode, $output);
            self::assertStringContainsString('Failed to decode update:packages:check JSON output.', $output);
            self::assertFileExists($projectRoot . '/var/server-started');
        } finally {
            $this->cleanupProject($projectRoot, $server);
        }
    }

    /**
     * The release is broken after the wrapper's own restart: the next tick
     * finds nothing newer and it stayed live. Rolled back inside the app
     * container, the stack is restarted onto the release before.
     */
    public function testAFailedHealthCheckRollsBackAndRestartsOntoTheReleaseBefore(): void
    {
        $server = $this->startHttpServer(500);
        $projectRoot = $this->createFakeProject($server, rollbackPayload: ['status' => 'rolled_back', 'reason' => 'back', 'restart_required' => true]);

        try {
            [$exitCode, $output] = $this->runWrapper($projectRoot);

            self::assertSame(1, $exitCode, $output);
            self::assertFileExists($projectRoot . '/var/rollback-asked');
            self::assertSame(2, substr_count((string) file_get_contents($projectRoot . '/var/server-started'), 'x'), 'the deploy restart, then the one onto the restored release');
            self::assertStringContainsString('Rolled back to the release before the deploy.', $output);
        } finally {
            $this->cleanupProject($projectRoot, $server);
        }
    }

    /** The new release's containers do not come up: set -e ended the run before any rollback. */
    public function testARestartThatFailsRollsBackWithoutAHealthCheck(): void
    {
        $server = $this->startHttpServer(200);
        $projectRoot = $this->createFakeProject($server, rollbackPayload: ['status' => 'rolled_back', 'reason' => 'back', 'restart_required' => true], restartExit: 1);

        try {
            [$exitCode, $output] = $this->runWrapper($projectRoot);

            self::assertSame(1, $exitCode, $output);
            self::assertStringContainsString('Restart onto the new release failed.', $output);
            self::assertFileExists($projectRoot . '/var/rollback-asked');
            self::assertStringNotContainsString('Health check failed', $output);
        } finally {
            $this->cleanupProject($projectRoot, $server);
        }
    }

    public function testARollbackThatRestartedItselfIsNotRestartedAgain(): void
    {
        $server = $this->startHttpServer(500);
        $projectRoot = $this->createFakeProject($server, rollbackPayload: ['status' => 'rolled_back', 'reason' => 'back', 'restart_required' => false]);

        try {
            [$exitCode, $output] = $this->runWrapper($projectRoot);

            self::assertSame(1, $exitCode, $output);
            self::assertSame(1, substr_count((string) file_get_contents($projectRoot . '/var/server-started'), 'x'), $output);
        } finally {
            $this->cleanupProject($projectRoot, $server);
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runWrapper(string $projectRoot): array
    {
        $script = dirname(__DIR__, 4) . '/tools/run-auto-deploy-systemd.sh';
        $command = sprintf(
            'bash %s %s 2>&1',
            escapeshellarg($script),
            escapeshellarg($projectRoot),
        );

        $output = [];
        exec($command, $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    /**
     * @return array{server_root: string, port: int, process: resource}
     */
    private function startHttpServer(int $statusCode): array
    {
        $serverRoot = sys_get_temp_dir() . '/semitexa-auto-deploy-server-' . bin2hex(random_bytes(4));
        mkdir($serverRoot, 0777, true);

        file_put_contents(
            $serverRoot . '/index.php',
            sprintf("<?php http_response_code(%d); echo 'ok';\n", $statusCode),
        );

        $port = random_int(20000, 40000);
        $command = sprintf(
            '%s -S 127.0.0.1:%d -t %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($serverRoot),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/tmp/semitexa-auto-deploy-server.out', 'a'],
            2 => ['file', '/tmp/semitexa-auto-deploy-server.err', 'a'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);

        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                $ready = true;
                break;
            }
            usleep(100_000);
        }

        self::assertTrue($ready, 'Built-in test HTTP server failed to start.');

        return [
            'server_root' => $serverRoot,
            'port' => $port,
            'process' => $process,
        ];
    }

    /**
     * @param array{server_root: string, port: int, process: resource} $server
     */
    /**
     * @param array<string, mixed>|null $autoPayload
     * @param array<string, mixed>|null $rollbackPayload
     */
    private function createFakeProject(array $server, ?string $deployCheckOutput = null, ?array $autoPayload = null, int $autoExit = 0, ?array $rollbackPayload = null, int $restartExit = 0): string
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-auto-deploy-project-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/bin', 0777, true);
        mkdir($projectRoot . '/var', 0777, true);

        $payload = json_encode($autoPayload ?? [
            'status' => 'updated',
            'reason' => 'a "quoted" reason with {braces}',
            'restart_required' => true,
            'updated_packages' => [['package' => 'semitexa/core', 'installed_version' => '1', 'latest_version' => '2']],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $healthcheckPayload = json_encode([
            'healthcheck_url' => sprintf('http://127.0.0.1:%d/', $server['port']),
        ], JSON_THROW_ON_ERROR);
        $deployCheckPayload = $deployCheckOutput ?? $healthcheckPayload;
        $rollback = json_encode($rollbackPayload ?? ['status' => 'nothing', 'reason' => 'Nothing to roll back', 'restart_required' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $script = <<<BASH
#!/usr/bin/env bash
set -euo pipefail

case "\${1:-}" in
  update:packages:auto)
    printf '%s\n' '__PAYLOAD__'
    exit __AUTO_EXIT__
    ;;
  update:packages:check)
    printf '%s\n' '__HEALTHCHECK_PAYLOAD__'
    ;;
  update:packages:rollback)
    touch var/rollback-asked
    printf '%s\n' '__ROLLBACK_PAYLOAD__'
    ;;
  server:restart)
    echo '[INFO] Restarting the stack'
    printf x >> var/server-started
    exit __RESTART_EXIT__
    ;;
  *)
    echo "Unexpected command: \$*" >&2
    exit 1
    ;;
esac
BASH;

        file_put_contents(
            $projectRoot . '/bin/semitexa',
            str_replace(
                ['__PAYLOAD__', '__HEALTHCHECK_PAYLOAD__', '__AUTO_EXIT__', '__ROLLBACK_PAYLOAD__', '__RESTART_EXIT__'],
                [$payload, $deployCheckPayload, (string) $autoExit, $rollback, (string) $restartExit],
                $script,
            ),
        );
        chmod($projectRoot . '/bin/semitexa', 0755);

        return $projectRoot;
    }

    /**
     * @param array{server_root: string, port: int, process: resource} $server
     */
    private function cleanupProject(string $projectRoot, array $server): void
    {
        proc_terminate($server['process']);
        proc_close($server['process']);
        $this->deleteDir($server['server_root']);
        $this->deleteDir($projectRoot);
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->deleteDir($path . '/' . $item);
        }

        @rmdir($path);
    }
}
