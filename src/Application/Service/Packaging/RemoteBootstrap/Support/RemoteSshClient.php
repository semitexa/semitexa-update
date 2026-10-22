<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support;

use Semitexa\Core\Environment;
use Semitexa\Update\Domain\Model\RemoteDeployTarget;
use Semitexa\Update\Domain\Model\SshCommandResult;

final class RemoteSshClient
{
    /**
     * $stateDir holds known_hosts (the project's var/ssh): the ssh default is
     * ~/.ssh, which for the image's user is the project directory itself, and
     * went to the server inside the deploy artifact.
     */
    public function __construct(
        private readonly ?string $stateDir = null,
    ) {}

    public function probeBatchAuth(RemoteDeployTarget $target, int $port): SshCommandResult
    {
        return $this->execute(
            $this->buildSshCommand($target, $port, 'printf __SEMITEXA__', true),
            null,
        );
    }

    public function run(RemoteDeployTarget $target, int $port, string $command, ?string $password = null): SshCommandResult
    {
        return $this->execute(
            $this->buildSshCommand($target, $port, $command, $password === null),
            $password,
        );
    }

    public function upload(RemoteDeployTarget $target, int $port, string $localPath, string $remotePath, ?string $password = null): SshCommandResult
    {
        return $this->execute(
            $this->buildScpCommand($target, $port, $localPath, $remotePath, $password !== null),
            $password,
        );
    }

    public function requiresPassword(SshCommandResult $result): bool
    {
        $text = strtolower($result->stderr . "\n" . $result->stdout);
        return str_contains($text, 'permission denied')
            || str_contains($text, 'password')
            || str_contains($text, 'publickey');
    }

    /**
     * @param list<string> $command
     */
    private function execute(array $command, ?string $password): SshCommandResult
    {
        $this->ensureAccount();

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = null;
        if ($password !== null) {
            array_unshift($command, 'sshpass', '-e');
            $env = array_merge($_ENV, $_SERVER, ['SSHPASS' => $password]);
        }

        $process = proc_open($command, $descriptor, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start SSH process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new SshCommandResult(
            exitCode: $exitCode,
            stdout: trim((string) $stdout),
            stderr: trim((string) $stderr),
        );
    }

    /**
     * @return list<string>
     */
    private function buildSshCommand(RemoteDeployTarget $target, int $port, string $command, bool $batchMode): array
    {
        return array_merge([
            'ssh',
        ], $batchMode ? $this->identityArgs() : self::passwordOnlyArgs(), [
            '-p',
            (string) $port,
            '-o',
            $batchMode ? 'BatchMode=yes' : 'BatchMode=no',
            '-o',
            'StrictHostKeyChecking=accept-new',
            ...$this->knownHostsArgs(),
            // Nothing after this is an option, whatever it starts with.
            '--',
            $target->sshDestination(),
            'sh -lc ' . escapeshellarg($command),
        ]);
    }

    /**
     * @return list<string>
     */
    private function buildScpCommand(RemoteDeployTarget $target, int $port, string $localPath, string $remotePath, bool $withPassword = false): array
    {
        return array_merge([
            'scp',
        ], $withPassword ? self::passwordOnlyArgs() : $this->identityArgs(), [
            '-P',
            (string) $port,
            '-o',
            'StrictHostKeyChecking=accept-new',
            ...$this->knownHostsArgs(),
            '--',
            $localPath,
            $target->toConnectionString() . ':' . $remotePath,
        ]);
    }

    /** @return list<string> */
    private function knownHostsArgs(): array
    {
        if ($this->stateDir === null) {
            return [];
        }
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0700, true);
        }

        // Quoted, and % doubled: a path with a space split into two files
        // (known_hosts went to "/proj/My"), and a % made ssh read a token.
        return ['-o', 'UserKnownHostsFile="' . str_replace('%', '%%', $this->stateDir . '/known_hosts') . '"'];
    }

    /**
     * ssh and scp refuse to run for a uid with no account ("No user exists
     * for uid 501"), and the app container runs as the host user's uid, which
     * the image cannot know: every macOS user, every host user but 1000. The
     * image leaves /etc/passwd writable for this (it has no setuid program to
     * abuse it with); the account is added once, here, only when missing.
     */
    private function ensureAccount(): void
    {
        if (!function_exists('posix_geteuid') || posix_getpwuid(posix_geteuid()) !== false || !is_writable('/etc/passwd')) {
            return;
        }
        $uid = posix_geteuid();
        @file_put_contents('/etc/passwd', sprintf(
            "semitexa-%d:x:%d:%d:Semitexa:%s:/sbin/nologin\n",
            $uid,
            $uid,
            posix_getegid(),
            $this->stateDir ?? sys_get_temp_dir(),
        ), FILE_APPEND | LOCK_EX);
    }

    /**
     * With a password, the password only: a key with a passphrase and no
     * agent made ssh ask for the passphrase inside sshpass, which answers
     * only a password prompt, and the command hung with no output.
     *
     * @return list<string>
     */
    private static function passwordOnlyArgs(): array
    {
        return ['-o', 'PubkeyAuthentication=no', '-o', 'PreferredAuthentications=password,keyboard-interactive'];
    }

    /** @return list<string> */
    private function identityArgs(): array
    {
        // From .env too, like the other SEMITEXA_REMOTE_DEPLOY_* settings: run
        // through bin/semitexa, the command sees the container's environment.
        $identityFile = trim((string) Environment::getEnvValue('SEMITEXA_REMOTE_DEPLOY_SSH_IDENTITY_FILE', ''));
        if ($identityFile === '') {
            return [];
        }

        if (!is_file($identityFile)) {
            throw new \RuntimeException(sprintf(
                'SSH identity file "%s" does not exist.',
                $identityFile,
            ));
        }

        return ['-i', $identityFile];
    }
}
