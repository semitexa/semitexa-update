<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\RequiredSettings;

use Semitexa\Update\Domain\Model\RequiredSettings\MissingRequiredSetting;
use Semitexa\Update\Domain\Model\RequiredSettings\RequiredSettingsReport;

/**
 * Asks the installed code which required settings this environment lacks.
 *
 * `system:doctor --json` runs in a new PHP process: after composer moved core,
 * this process still holds the old core's classes, and its doctor would judge
 * the release that was just replaced.
 *
 * What blocks is narrow on purpose: a check that FAILS and names at least one
 * setting. Any other failing check is the doctor's business, not the update's.
 * A report with no `settings` (a core older than the field) blocks nothing,
 * and neither does a doctor that could not be run or read, or that did not
 * answer in time: that is reported.
 *
 * No cache stands between composer and this answer. The doctor finds its
 * checks through core's ClassDiscovery, which keeps nothing on disk: each
 * process reads vendor/composer/autoload_classmap.php and autoload_psr4.php,
 * the files composer has just written. A check shipped by the release being
 * installed is seen without a cache:clear first.
 */
final class DoctorProbe
{
    /** A doctor check that hangs (a database that never answers) must not hold the update lock for good. */
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    /**
     * @param (\Closure(string, int): array{exitCode: int, output: string, timedOut?: bool})|null $runner runs the
     *        doctor in the project, within the given seconds, and returns its stdout; null spawns the
     *        real process. Tests inject one.
     * @param (\Closure(string): (string|null))|null $processEnv reads one variable of the process
     *        environment; null reads getenv()
     */
    public function __construct(
        private readonly ?\Closure $runner = null,
        private readonly ?\Closure $processEnv = null,
        private readonly string $phpBinary = PHP_BINARY,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    public function probe(string $projectRoot): RequiredSettingsReport
    {
        try {
            $appEnv = $this->appEnv($projectRoot);
        } catch (\RuntimeException $e) {
            // The file that says which environment this is cannot be read: a
            // gate that guesses (prod? dev?) has not judged anything.
            return new RequiredSettingsReport('unknown', [], null, $e->getMessage());
        }

        try {
            $ran = $this->runner !== null
                ? ($this->runner)($projectRoot, $this->timeoutSeconds)
                : $this->spawn($projectRoot, $this->timeoutSeconds);
        } catch (\Throwable $e) {
            return new RequiredSettingsReport($appEnv, [], 'system:doctor could not be run: ' . $e->getMessage());
        }
        if ($ran['timedOut'] ?? false) {
            // Whatever it printed before the kill is half a report: not read.
            return new RequiredSettingsReport($appEnv, [], sprintf(
                'system:doctor timed out after %d s and was killed.',
                $this->timeoutSeconds,
            ));
        }

        $missing = self::parse($ran['output']);
        if ($missing === null) {
            $said = mb_substr(trim((string) preg_replace('/\s+/', ' ', $ran['output'])), 0, 300);

            return new RequiredSettingsReport($appEnv, [], sprintf(
                'system:doctor --json gave no report this update can read (exit %d)%s',
                $ran['exitCode'],
                $said !== '' ? ': ' . $said : '.',
            ));
        }

        if ($missing === [] && self::isLegacyReport($ran['output'])) {
            // Not blocking, but not silent: nothing here said which settings are required.
            return new RequiredSettingsReport($appEnv, [], "this core's system:doctor does not report settings: the required-settings gate did not run.");
        }

        return new RequiredSettingsReport($appEnv, $missing);
    }

    /**
     * True for a report from a core older than the `settings` field: it has
     * checks, and none of them carries the key (a newer core emits it on every
     * check, empty or not).
     */
    public static function isLegacyReport(string $output): bool
    {
        $checks = self::checks($output);
        foreach ($checks ?? [] as $check) {
            if (is_array($check) && array_key_exists('settings', $check)) {
                return false;
            }
        }

        return $checks !== null && $checks !== [];
    }

    /**
     * The failing checks that name a setting; null when the output is not a doctor report.
     *
     * The exit code is not read: the doctor exits 1 for any failing check, and
     * most of those are not a missing setting.
     *
     * @return list<MissingRequiredSetting>|null
     */
    public static function parse(string $output): ?array
    {
        $checks = self::checks($output);
        if ($checks === null) {
            return null;
        }

        $missing = [];
        foreach ($checks as $check) {
            if (!is_array($check) || ($check['status'] ?? null) !== 'fail') {
                continue;
            }
            // Absent in a report from a core older than the field: nothing to block on.
            $declared = $check['settings'] ?? null;
            $settings = [];
            foreach (is_array($declared) ? $declared : [] as $setting) {
                if (is_string($setting) && $setting !== '') {
                    $settings[] = $setting;
                }
            }
            if ($settings === []) {
                continue;
            }
            $name = $check['name'] ?? null;
            $package = $check['package'] ?? null;
            $message = $check['message'] ?? null;
            $hint = $check['hint'] ?? null;
            $missing[] = new MissingRequiredSetting(
                check: is_string($name) ? $name : '(unnamed)',
                package: is_string($package) ? $package : '',
                settings: $settings,
                message: is_string($message) ? $message : '',
                hint: is_string($hint) && $hint !== '' ? $hint : null,
            );
        }

        return $missing;
    }

    /**
     * The report's checks; null when the output is not a doctor report.
     *
     * @return array<mixed>|null
     */
    private static function checks(string $output): ?array
    {
        $report = json_decode(trim($output), true);
        if (!is_array($report)) {
            // A notice printed before the report must not hide it.
            $from = strpos($output, '{');
            $to = strrpos($output, '}');
            $report = $from !== false && $to !== false && $to > $from
                ? json_decode(substr($output, $from, $to - $from + 1), true)
                : null;
        }
        if (!is_array($report) || !is_array($report['checks'] ?? null)) {
            return null;
        }

        return $report['checks'];
    }

    /**
     * APP_ENV as the server resolves it: the process environment, then .env
     * over .env.default, then prod. Read from the given root, which is the
     * project the doctor runs in.
     *
     * @throws \RuntimeException when an env file it has to read is there and unreadable
     */
    public function appEnv(string $projectRoot): string
    {
        $value = $this->processEnv !== null ? ($this->processEnv)('APP_ENV') : getenv('APP_ENV');
        if (!is_string($value)) {
            $value = self::fromEnvFile($projectRoot . '/.env') ?? self::fromEnvFile($projectRoot . '/.env.default') ?? 'prod';
        }

        return strtolower(trim($value));
    }

    /**
     * The contents of an env file; null when there is none. A file that is
     * there and cannot be read (permissions, a directory in its place) throws:
     * falling back to .env.default or prod would judge another environment.
     *
     * @throws \RuntimeException naming the file
     */
    public static function readEnvFile(string $file): ?string
    {
        if (!file_exists($file) && !is_link($file)) {
            return null;
        }
        $contents = is_file($file) && is_readable($file) ? @file_get_contents($file) : false;
        if ($contents === false) {
            throw new \RuntimeException(sprintf('%s exists but cannot be read: fix its permissions, then run this again.', $file));
        }

        return $contents;
    }

    private static function fromEnvFile(string $file): ?string
    {
        $found = null;
        foreach (preg_split('/\R/', self::readEnvFile($file) ?? '') ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?APP_ENV\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $raw = $m[1];
            // The last assignment wins, quoted or with a trailing comment.
            $found = preg_match('/^(["\'])(.*?)\1/', $raw, $quoted) === 1
                ? $quoted[2]
                : trim((string) preg_replace('/\s#.*$/', '', $raw));
        }

        return $found;
    }

    /**
     * @return array{exitCode: int, output: string, timedOut: bool}
     */
    private function spawn(string $projectRoot, int $timeoutSeconds): array
    {
        // The console entry the deploy uses for orm:sync: bin/semitexa is the
        // host wrapper, and by now this process is where the project runs.
        $cli = $projectRoot . '/vendor/bin/semitexa';
        if (!is_file($cli)) {
            throw new \RuntimeException('vendor/bin/semitexa not found.');
        }
        // stderr to a file, not a pipe: a full pipe nobody reads would hang the doctor.
        $stderr = tmpfile();
        if ($stderr === false) {
            throw new \RuntimeException('no temporary file for its stderr.');
        }
        $proc = @proc_open(
            [$this->phpBinary, $cli, 'system:doctor', '--json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $stderr],
            $pipes,
            $projectRoot,
        );
        if (!is_resource($proc)) {
            fclose($stderr);
            throw new \RuntimeException('the process did not start.');
        }
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = '';
        $timedOut = false;
        while (!feof($pipes[1])) {
            if (microtime(true) >= $deadline) {
                // An argv command has no shell between: this is the doctor itself.
                proc_terminate($proc, 9);
                $timedOut = true;
                break;
            }
            $read = [$pipes[1]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200_000) > 0) {
                $stdout .= (string) fread($pipes[1], 65536);
            }
        }
        fclose($pipes[1]);
        // A check can close stdout and keep running: the end of the output is
        // not the end of the process, and proc_close() alone would wait for
        // it without limit. The same deadline holds until it has exited.
        $status = proc_get_status($proc);
        while (!$timedOut && $status['running']) {
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9);
                $timedOut = true;
                break;
            }
            usleep(50_000);
            $status = proc_get_status($proc);
        }
        // Once proc_get_status() has seen the exit, proc_close() no longer knows the code.
        $closed = proc_close($proc);
        $exitCode = $status['running'] ? $closed : $status['exitcode'];
        rewind($stderr);
        $errors = trim((string) stream_get_contents($stderr));
        fclose($stderr);

        // stderr only when stdout carried nothing: it is what explains the silence.
        return ['exitCode' => $exitCode, 'output' => trim($stdout) !== '' ? $stdout : $errors, 'timedOut' => $timedOut];
    }
}
