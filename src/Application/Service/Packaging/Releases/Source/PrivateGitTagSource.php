<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Source;

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

    public function latestStableTag(string $repositoryUrl): ?string
    {
        $repositoryUrl = trim($repositoryUrl);
        if ($repositoryUrl === '') {
            return null;
        }

        $cmd = sprintf('git ls-remote --tags %s 2>&1', escapeshellarg($repositoryUrl));
        $output = [];
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->warnings[] = sprintf(
                'git ls-remote failed for %s (exit %d): %s — treating as no update, but this may hide a newer release.',
                $repositoryUrl,
                $exitCode,
                trim(implode(' ', array_slice($output, -3))),
            );
            return null;
        }

        return $this->extractLatestStableTag($output);
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
