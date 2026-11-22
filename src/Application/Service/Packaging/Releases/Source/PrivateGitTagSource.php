<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Source;

use Semitexa\Update\Application\Service\Packaging\Releases\Support\GitRepositoryUrl;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\SemitexaReleaseVersion;

final class PrivateGitTagSource
{
    /**
     * Diagnostics accumulated since the last resetWarnings(). A failed
     * `git ls-remote` must never masquerade as "no release tag exists".
     *
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * @param (\Closure(list<string>): array{int, list<string>})|null $runner runs an argv, returns exit code and
     *                                                                        output lines; null runs it for real
     */
    public function __construct(
        private readonly ?\Closure $runner = null,
    ) {}

    /**
     * @return list<string>
     */
    public function lastWarnings(): array
    {
        return $this->warnings;
    }

    public function resetWarnings(): void
    {
        $this->warnings = [];
    }

    /**
     * @param string $setting where the URL was configured, named in the refusal message
     */
    public function latestStableTag(string $repositoryUrl, string $setting = 'the repository URL'): ?string
    {
        $repositoryUrl = trim($repositoryUrl);
        if ($repositoryUrl === '') {
            return null;
        }

        $refusal = GitRepositoryUrl::refusal($repositoryUrl);
        if ($refusal !== null) {
            $this->warnings[] = sprintf(
                'Refused repository URL "%s" from %s: %s. git was not run; accepted: %s — treating as no update, but this may hide a newer release.',
                GitRepositoryUrl::printable($repositoryUrl),
                $setting,
                $refusal,
                GitRepositoryUrl::ACCEPTED,
            );
            return null;
        }

        // An argv, never a shell line, and "--" so git cannot read the URL as an option.
        $argv = ['git', 'ls-remote', '--tags', '--', $repositoryUrl];
        [$exitCode, $output] = $this->runner !== null ? ($this->runner)($argv) : $this->run($argv);

        if ($exitCode !== 0) {
            $this->warnings[] = sprintf(
                'git ls-remote failed for %s (exit %d): %s — treating as no update, but this may hide a newer release.',
                GitRepositoryUrl::printable($repositoryUrl),
                $exitCode,
                GitRepositoryUrl::redact(trim(implode(' ', array_slice($output, -3))), $repositoryUrl),
            );
            return null;
        }

        return $this->extractLatestStableTag($output);
    }

    /**
     * @param list<string> $argv
     * @return array{int, list<string>}
     */
    private function run(array $argv): array
    {
        $env = getenv();
        // Second line of defence: git itself refuses helper transports unless the operator widened this.
        $env['GIT_ALLOW_PROTOCOL'] ??= 'file:https:ssh';

        // One file for both streams keeps git's own order of lines, as "2>&1" did.
        $capture = tmpfile();
        if ($capture === false) {
            return [127, ['git output could not be captured']];
        }

        $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => $capture, 2 => $capture], $pipes, null, $env);
        if (!is_resource($process)) {
            fclose($capture);

            return [127, ['git could not be started']];
        }

        $exitCode = proc_close($process);
        rewind($capture);
        $raw = (string) stream_get_contents($capture);
        fclose($capture);

        return [$exitCode, $raw === '' ? [] : explode("\n", rtrim($raw, "\n"))];
    }

    /**
     * @param list<string> $lines
     */
    public function extractLatestStableTag(array $lines): ?string
    {
        $versions = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, "\t")) {
                continue;
            }

            [, $ref] = explode("\t", $line, 2);
            $tag = preg_replace('~^refs/tags/~', '', trim($ref));
            $tag = preg_replace('~\^\{\}$~', '', (string) $tag);
            $tag = ltrim((string) $tag, 'v');

            if (!SemitexaReleaseVersion::isStable($tag)) {
                continue;
            }

            $versions[] = $tag;
        }

        return SemitexaReleaseVersion::latestStable(array_values(array_unique($versions)));
    }
}
