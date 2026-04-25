<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\Releases\Source;

use Semitexa\Update\Packaging\Releases\Support\SemitexaReleaseVersion;

final class PrivateGitTagSource
{
    public function latestStableTag(string $repositoryUrl): ?string
    {
        $repositoryUrl = trim($repositoryUrl);
        if ($repositoryUrl === '') {
            return null;
        }

        $cmd = sprintf('git ls-remote --tags %s 2>/dev/null', escapeshellarg($repositoryUrl));
        $output = [];
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
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
