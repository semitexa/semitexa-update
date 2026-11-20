<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\RequiredSettings;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\RequiredSettings\DoctorProbe;

/**
 * What of a `system:doctor --json` report stops an update: a failing check
 * that names a setting, and nothing else.
 */
final class DoctorProbeTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-doctor-probe-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectRoot));
    }

    public function testAFailingCheckThatNamesASettingIsMissing(): void
    {
        $missing = DoctorProbe::parse(self::report([
            self::check('app.secret', 'fail', ['APP_SECRET'], 'Set APP_SECRET in .env to a long random value: openssl rand -hex 32'),
            self::check('db.reachable', 'fail', []),
            self::check('redis.pool', 'pass', ['REDIS_POOL_SIZE']),
            self::check('mail.dsn', 'warn', ['MAIL_DSN']),
        ]));

        self::assertNotNull($missing);
        self::assertCount(1, $missing, 'a failing check with no settings, a pass and a warning block nothing');
        self::assertSame('app.secret', $missing[0]->check);
        self::assertSame('semitexa/core', $missing[0]->package);
        self::assertSame(['APP_SECRET'], $missing[0]->settings);
        self::assertSame('Set APP_SECRET in .env to a long random value: openssl rand -hex 32', $missing[0]->hint);
    }

    /**
     * A core older than the field emits no `settings` key: its failing checks
     * block nothing, and the operator is told the gate had nothing to read.
     */
    public function testAReportFromAnOlderCoreWithoutSettingsBlocksNothing(): void
    {
        $check = self::check('app.secret', 'fail', []);
        unset($check['settings']);
        $output = self::report([$check]);

        self::assertSame([], DoctorProbe::parse($output));

        $report = new DoctorProbe(
            static fn (string $root): array => ['exitCode' => 1, 'output' => $output],
            static fn (string $name): ?string => null,
        )->probe($this->projectRoot);

        self::assertTrue($report->isSuccess());
        self::assertSame(
            "this core's system:doctor does not report settings: the required-settings gate did not run.",
            $report->probeError,
        );
        self::assertTrue(DoctorProbe::isLegacyReport($output));
    }

    /** A current core emits `settings` on every check, empty or not: that is a gate that ran. */
    public function testAReportThatCarriesTheFieldIsNotLegacy(): void
    {
        $output = self::report([self::check('db.reachable', 'fail', []), self::check('app.secret', 'pass', ['APP_SECRET'])]);

        $report = new DoctorProbe(
            static fn (string $root): array => ['exitCode' => 1, 'output' => $output],
            static fn (string $name): ?string => null,
        )->probe($this->projectRoot);

        self::assertTrue($report->isSuccess());
        self::assertNull($report->probeError);
        self::assertFalse(DoctorProbe::isLegacyReport(self::report([])), 'no checks at all says nothing about the core');
    }

    /**
     * A check that closes stdout and keeps running ended the read loop, and
     * the wait for its exit had no limit: the update held its lock for as
     * long as the check lived.
     */
    public function testADoctorThatClosesStdoutAndKeepsRunningIsKilledAtTheDeadline(): void
    {
        mkdir($this->projectRoot . '/vendor/bin', 0777, true);
        file_put_contents($this->projectRoot . '/vendor/bin/semitexa', '<?php fclose(STDOUT); sleep(12);');

        $started = microtime(true);
        $report = new DoctorProbe(null, static fn (string $name): ?string => null, timeoutSeconds: 1)->probe($this->projectRoot);
        $took = microtime(true) - $started;

        self::assertSame('system:doctor timed out after 1 s and was killed.', $report->probeError);
        self::assertTrue($report->isSuccess(), 'a timeout warns, it does not block');
        self::assertLessThan(6.0, $took, 'killed at the deadline, not waited for');
    }

    /** The real process on its normal path: the report is read and the exit code kept. */
    public function testTheRealProcessIsReadWhenItAnswersInTime(): void
    {
        mkdir($this->projectRoot . '/vendor/bin', 0777, true);
        $report = self::report([self::check('app.secret', 'fail', ['APP_SECRET'], 'openssl rand -hex 32')]);
        file_put_contents($this->projectRoot . '/vendor/bin/semitexa', '<?php echo ' . var_export($report, true) . '; exit(1);');

        $result = new DoctorProbe(null, static fn (string $name): ?string => null)->probe($this->projectRoot);

        self::assertNull($result->probeError);
        self::assertSame(['APP_SECRET'], $result->settingNames());
    }

    /**
     * No APP_ENV in the process, and a .env that is there but cannot be read
     * (a directory stands in for a permission error, which root never gets):
     * the gate fails and names the file, where it used to fall back to
     * .env.default and judge another environment.
     */
    public function testAnUnreadableDotEnvFailsTheGateAndNamesTheFile(): void
    {
        mkdir($this->projectRoot . '/.env');
        file_put_contents($this->projectRoot . '/.env.default', "APP_ENV=dev\n");
        $ran = false;
        $probe = new DoctorProbe(
            static function (string $root) use (&$ran): array {
                $ran = true;

                return ['exitCode' => 0, 'output' => '{"checks": []}'];
            },
            static fn (string $name): ?string => null,
        );

        $report = $probe->probe($this->projectRoot);

        self::assertFalse($report->isSuccess());
        self::assertStringContainsString($this->projectRoot . '/.env exists but cannot be read', (string) $report->failure);
        self::assertStringContainsString($this->projectRoot . '/.env exists but cannot be read', (string) $report->describe());
        self::assertFalse($ran, 'no doctor is asked about an environment nobody could name');
    }

    /** With APP_ENV in the process environment the file is not what decides, and is not read here. */
    public function testAnUnreadableDotEnvDoesNotMatterWhenTheProcessNamesTheEnvironment(): void
    {
        mkdir($this->projectRoot . '/.env');
        $probe = new DoctorProbe(
            static fn (string $root): array => ['exitCode' => 0, 'output' => '{"checks": []}'],
            static fn (string $name): string => 'prod',
        );

        self::assertTrue($probe->probe($this->projectRoot)->isSuccess());
    }

    /** A setting declared as anything but a name is dropped, as core drops it. */
    public function testSettingsThatAreNotNamesDoNotCount(): void
    {
        $check = self::check('app.secret', 'fail', []);
        $check['settings'] = [null, '', 7];

        self::assertSame([], DoctorProbe::parse(self::report([$check])));
    }

    public function testANoticeBeforeTheReportDoesNotHideIt(): void
    {
        $output = "Deprecated: something in vendor/x.php on line 3\n"
            . self::report([self::check('app.secret', 'fail', ['APP_SECRET'], 'openssl rand -hex 32')]);

        $missing = DoctorProbe::parse($output);

        self::assertNotNull($missing);
        self::assertCount(1, $missing);
        self::assertSame(['APP_SECRET'], $missing[0]->settings);
    }

    /** A doctor that says nothing readable is reported and stops nothing. */
    public function testOutputThatIsNotAReportIsAProbeErrorAndBlocksNothing(): void
    {
        self::assertNull(DoctorProbe::parse('PHP Fatal error: Uncaught Error: Class "X" not found'));
        self::assertNull(DoctorProbe::parse('{"healthy": false}'));

        $report = new DoctorProbe(
            static fn (string $root): array => ['exitCode' => 255, 'output' => 'PHP Fatal error: Uncaught Error: Class "X" not found'],
            static fn (string $name): ?string => null,
        )->probe($this->projectRoot);

        self::assertTrue($report->isSuccess());
        self::assertStringContainsString('exit 255', (string) $report->probeError);
        self::assertStringContainsString('Class "X" not found', (string) $report->probeError);
    }

    public function testARunnerThatThrowsIsAProbeErrorAndBlocksNothing(): void
    {
        $report = new DoctorProbe(
            static fn (string $root): array => throw new \RuntimeException('vendor/bin/semitexa not found.'),
            static fn (string $name): ?string => null,
        )->probe($this->projectRoot);

        self::assertTrue($report->isSuccess());
        self::assertSame('system:doctor could not be run: vendor/bin/semitexa not found.', $report->probeError);
    }

    /** A doctor that hangs is killed by the runner; what it had printed is not read as a report. */
    public function testATimedOutDoctorIsAProbeErrorAndBlocksNothing(): void
    {
        $asked = null;
        $half = self::report([self::check('app.secret', 'fail', ['APP_SECRET'])]);
        $report = new DoctorProbe(
            static function (string $root, int $timeoutSeconds) use (&$asked, $half): array {
                $asked = $timeoutSeconds;

                return ['exitCode' => -1, 'output' => $half, 'timedOut' => true];
            },
            static fn (string $name): ?string => null,
            timeoutSeconds: 7,
        )->probe($this->projectRoot);

        self::assertSame(7, $asked, 'the runner is given the limit');
        self::assertTrue($report->isSuccess());
        self::assertSame('system:doctor timed out after 7 s and was killed.', $report->probeError);
    }

    public function testTheDefaultTimeoutIsTwoMinutes(): void
    {
        $asked = null;
        new DoctorProbe(
            static function (string $root, int $timeoutSeconds) use (&$asked): array {
                $asked = $timeoutSeconds;

                return ['exitCode' => 0, 'output' => '{"checks": []}'];
            },
            static fn (string $name): ?string => null,
        )->probe($this->projectRoot);

        self::assertSame(120, $asked);
    }

    /** The APP_ENV named in the output is the one the server would run under. */
    public function testAppEnvIsTheProcessEnvironmentThenDotEnvThenProd(): void
    {
        $noProcessEnv = new DoctorProbe(null, static fn (string $name): ?string => null);
        self::assertSame('prod', $noProcessEnv->appEnv($this->projectRoot), 'no file, no variable: the server defaults to prod');

        file_put_contents($this->projectRoot . '/.env.default', "APP_ENV=dev\n");
        self::assertSame('dev', $noProcessEnv->appEnv($this->projectRoot));

        file_put_contents($this->projectRoot . '/.env', "# APP_ENV=dev\nAPP_NAME=x\nexport APP_ENV=\"Prod\" # live\n");
        self::assertSame('prod', $noProcessEnv->appEnv($this->projectRoot), '.env over .env.default');

        $fromProcess = new DoctorProbe(null, static fn (string $name): ?string => $name === 'APP_ENV' ? 'test' : null);
        self::assertSame('test', $fromProcess->appEnv($this->projectRoot), 'the process environment over .env');
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private static function report(array $checks): string
    {
        return (string) json_encode([
            'artifact' => 'semitexa.system-doctor/v1',
            'checks' => $checks,
            'healthy' => false,
        ], JSON_PRETTY_PRINT);
    }

    /**
     * @param list<string> $settings
     * @return array<string, mixed>
     */
    private static function check(string $name, string $status, array $settings, ?string $hint = null): array
    {
        return [
            'name' => $name,
            'package' => 'semitexa/core',
            'status' => $status,
            'message' => $name . ' says ' . $status,
            'hint' => $hint,
            'settings' => $settings,
        ];
    }
}
