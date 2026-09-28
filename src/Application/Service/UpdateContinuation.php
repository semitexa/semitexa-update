<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

/**
 * Carries an update on in a fresh PHP process after composer upgraded
 * semitexa/update itself.
 *
 * The classes of the running process are the old updater's; running the next
 * stages with them would apply the old release's scaffold and patches to the
 * new release's code. The run used to stop there and ask the operator to type
 * the command again — every consumer update that moved the updater took two
 * runs, and the first one ended on "Update completed." with half the stages
 * never started. A new process loads the new code, so it continues by itself.
 *
 * Once only: the continuation carries a marker, and a continuation that finds
 * the updater changed yet again stops rather than looping.
 */
final class UpdateContinuation
{
    public const MARKER = 'SEMITEXA_UPDATE_CONTINUATION';

    /**
     * @param list<string>|null $argv the command line of this process; null reads $_SERVER['argv']
     */
    public function __construct(
        private readonly ?array $argv = null,
        private readonly string $phpBinary = PHP_BINARY,
    ) {
    }

    /** False inside a continuation already, or when this process's command line is unknown. */
    public function isPossible(): bool
    {
        return getenv(self::MARKER) !== '1' && $this->commandLine() !== null;
    }

    /**
     * Run the same command again in a new process, sharing this terminal.
     *
     * @return int the continuation's exit code; 1 when it could not be started
     */
    public function run(): int
    {
        $commandLine = $this->commandLine();
        if ($commandLine === null) {
            return 1;
        }

        $env = getenv();
        $env[self::MARKER] = '1';

        $proc = @proc_open(
            [$this->phpBinary, ...$commandLine],
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            null,
            $env,
        );
        if (!is_resource($proc)) {
            return 1;
        }

        return proc_close($proc);
    }

    /**
     * @return non-empty-list<string>|null
     */
    private function commandLine(): ?array
    {
        $argv = $this->argv ?? ($_SERVER['argv'] ?? null);
        if (!is_array($argv) || $argv === []) {
            return null;
        }
        $out = [];
        foreach ($argv as $arg) {
            if (!is_string($arg)) {
                return null;
            }
            $out[] = $arg;
        }

        return $out;
    }
}
