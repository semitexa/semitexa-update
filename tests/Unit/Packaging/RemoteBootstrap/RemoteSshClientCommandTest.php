<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\RemoteBootstrap;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support\RemoteDeployTargetParser;
use Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support\RemoteSshClient;

/**
 * The commands as they are built: ssh takes an IPv6 host bare, scp in
 * brackets (`user@[::1]:path`); with a password, keys are not offered.
 */
final class RemoteSshClientCommandTest extends TestCase
{
    public function testSshTakesAnIpv6HostBareAndScpInBrackets(): void
    {
        $target = (new RemoteDeployTargetParser())->parseOne('deploy@[2001:DB8::10]');

        $ssh = $this->build('buildSshCommand', $target, 22, 'true', true);
        $scp = $this->build('buildScpCommand', $target, 22, '/tmp/a', '/srv/a');

        self::assertContains('deploy@2001:db8::10', $ssh);
        self::assertContains('deploy@[2001:db8::10]:/srv/a', $scp);
    }

    /**
     * A key with a passphrase and no agent made ssh ask for it inside
     * sshpass, which answers only a password prompt: the command hung.
     */
    public function testWithAPasswordNoKeyIsOffered(): void
    {
        $target = (new RemoteDeployTargetParser())->parseOne('deploy@example.com');

        $ssh = $this->build('buildSshCommand', $target, 22, 'true', false);
        $scp = $this->build('buildScpCommand', $target, 22, '/tmp/a', '/srv/a', true);

        foreach ([$ssh, $scp] as $command) {
            self::assertContains('PubkeyAuthentication=no', $command);
        }
        self::assertNotContains('PubkeyAuthentication=no', $this->build('buildSshCommand', $target, 22, 'true', true));
    }

    /** known_hosts went to ~/.ssh, the project directory itself, and shipped in the artifact. */
    public function testKnownHostsAreKeptUnderTheStateDirectory(): void
    {
        $target = (new RemoteDeployTargetParser())->parseOne('deploy@example.com');
        $dir = sys_get_temp_dir() . '/semitexa-ssh-state-' . bin2hex(random_bytes(4));
        $client = new RemoteSshClient($dir);

        try {
            $ssh = (new \ReflectionMethod(RemoteSshClient::class, 'buildSshCommand'))->invoke($client, $target, 22, 'true', true);
            $scp = (new \ReflectionMethod(RemoteSshClient::class, 'buildScpCommand'))->invoke($client, $target, 22, '/tmp/a', '/srv/a');

            self::assertContains('UserKnownHostsFile="' . $dir . '/known_hosts"', $ssh);
            self::assertContains('UserKnownHostsFile="' . $dir . '/known_hosts"', $scp);
            self::assertDirectoryExists($dir);
            // Nothing after the options reads as one.
            self::assertSame('--', $ssh[array_search('deploy@example.com', $ssh, true) - 1]);
            self::assertSame('--', $scp[array_search('/tmp/a', $scp, true) - 1]);
        } finally {
            @rmdir($dir);
        }
    }

    /** @return list<string> */
    private function build(string $method, mixed ...$args): array
    {
        return (new \ReflectionMethod(RemoteSshClient::class, $method))->invoke(new RemoteSshClient(), ...$args);
    }

    /** A space split the path into two files, and a % made ssh read a token. */
    public function testAKnownHostsPathWithASpaceOrPercentIsQuotedAndEscaped(): void
    {
        $target = (new RemoteDeployTargetParser())->parseOne('deploy@example.com');
        $dir = sys_get_temp_dir() . '/semitexa ssh 100%-' . bin2hex(random_bytes(4));
        $client = new RemoteSshClient($dir);

        try {
            $ssh = (new \ReflectionMethod(RemoteSshClient::class, 'buildSshCommand'))->invoke($client, $target, 22, 'true', true);

            self::assertContains('UserKnownHostsFile="' . str_replace('%', '%%', $dir) . '/known_hosts"', $ssh);
        } finally {
            @rmdir($dir);
        }
    }
}
