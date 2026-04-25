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
     * @return array{0: int, 1: string}
     */
    private function runWrapper(string $projectRoot): array
    {
        $script = dirname(__DIR__, 3) . '/tools/run-auto-deploy-systemd.sh';
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
    private function createFakeProject(array $server, ?string $deployCheckOutput = null): string
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-auto-deploy-project-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/bin', 0777, true);
        mkdir($projectRoot . '/var', 0777, true);

        $payload = json_encode([
            'status' => 'updated',
            'restart_required' => true,
        ], JSON_THROW_ON_ERROR);

        $healthcheckPayload = json_encode([
            'healthcheck_url' => sprintf('http://127.0.0.1:%d/', $server['port']),
        ], JSON_THROW_ON_ERROR);
        $deployCheckPayload = $deployCheckOutput ?? $healthcheckPayload;

        $script = <<<BASH
#!/usr/bin/env bash
set -euo pipefail

case "\${1:-}" in
  update:packages:auto)
    printf '%s\n' '__PAYLOAD__'
    ;;
  update:packages:check)
    printf '%s\n' '__HEALTHCHECK_PAYLOAD__'
    ;;
  server:start)
    touch var/server-started
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
                ['__PAYLOAD__', '__HEALTHCHECK_PAYLOAD__'],
                [$payload, $deployCheckPayload],
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
