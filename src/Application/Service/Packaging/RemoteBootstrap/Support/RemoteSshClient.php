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
        $env = $this->accountEnvironment();

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        if ($password !== null) {
            array_unshift($command, 'sshpass', '-e');
            $env['SSHPASS'] = $password;
        }

        // Strings only: $_SERVER also holds argv, an array, which is no
        // environment value.
        $process = proc_open($command, $descriptor, $pipes, null, $env === []
            ? null
            : array_merge(array_filter(array_merge(getenv(), $_ENV, $_SERVER), 'is_string'), $env));
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
     * image ships nss_wrapper for this, so the account is named in a passwd
     * file of this client's own: a writable /etc/passwd turned any file write
     * in the app into a new account.
     *
     * @return array<string, string> what ssh is run with; none when the uid has an account
     */
    private function accountEnvironment(): array
    {
        if (!function_exists('posix_geteuid') || posix_getpwuid(posix_geteuid()) !== false || !is_file(self::NSS_WRAPPER)) {
            return [];
        }

        return self::nssWrapperEnvironment(posix_geteuid(), posix_getegid(), $this->stateDir ?? sys_get_temp_dir(), self::NSS_WRAPPER);
    }

    /** Where the image's nss_wrapper package puts the library (Alpine). */
    private const NSS_WRAPPER = '/usr/lib/libnss_wrapper.so';

    /**
     * The system's accounts and groups, and one for this uid (and its gid
     * when that has no group), in files under $dir that nss_wrapper reads
     * in place of /etc/passwd and /etc/group. None when they cannot be
     * written: ssh then says itself which account is missing.
     *
     * @return array<string, string>
     */
    private static function nssWrapperEnvironment(int $uid, int $gid, string $dir, string $library): array
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return [];
        }
        $accounts = rtrim((string) @file_get_contents('/etc/passwd'), "\n");
        $accounts .= ($accounts === '' ? '' : "\n") . sprintf("semitexa-%d:x:%d:%d:Semitexa:%s:/sbin/nologin\n", $uid, $uid, $gid, $dir);
        $groups = rtrim((string) @file_get_contents('/etc/group'), "\n");
        if (!function_exists('posix_getgrgid') || posix_getgrgid($gid) === false) {
            $groups .= ($groups === '' ? '' : "\n") . sprintf('semitexa-%d:x:%d:', $gid, $gid);
        }
        $passwdFile = $dir . '/nss-passwd';
        $groupFile = $dir . '/nss-group';
        if (@file_put_contents($passwdFile, $accounts) === false || @file_put_contents($groupFile, $groups . "\n") === false) {
            return [];
        }

        return ['LD_PRELOAD' => $library, 'NSS_WRAPPER_PASSWD' => $passwdFile, 'NSS_WRAPPER_GROUP' => $groupFile];
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
