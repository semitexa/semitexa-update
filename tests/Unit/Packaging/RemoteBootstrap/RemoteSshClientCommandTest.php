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

    /** @return list<string> */
    private function build(string $method, mixed ...$args): array
    {
        return (new \ReflectionMethod(RemoteSshClient::class, $method))->invoke(new RemoteSshClient(), ...$args);
    }
}
