<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Scaffold;

use Semitexa\Update\Domain\Model\Scaffold\ScaffoldFileEntry;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;

/**
 * Builds a fresh {@see ScaffoldManifest} from the files in a scaffold source
 * directory. When a prior manifest is supplied, each entry's previous_sha256
 * registry grows: the prior current_sha256 is prepended (deduplicated) so
 * downstream projects with files matching any prior release stay eligible
 * for safe auto-update.
 *
 * The builder is strict: every file found in the scaffold dir MUST appear
 * in the policy table. An uncategorised file aborts the build — humans must
 * decide policy before a release can ship.
 */
final class ScaffoldManifestBuilder
{
    public function __construct(
        private readonly ScaffoldHasher $hasher = new ScaffoldHasher(),
        private readonly ScaffoldManifestPolicy $policy = new ScaffoldManifestPolicy(),
    ) {
    }

    public function build(string $scaffoldRoot, ?ScaffoldManifest $prior = null, ?string $generatedAt = null): ScaffoldManifest
    {
        if (!is_dir($scaffoldRoot)) {
            throw new \RuntimeException("Scaffold root not found: {$scaffoldRoot}");
        }

        $files = $this->listScaffoldFiles($scaffoldRoot);
        $defaults = $this->policy->defaults();
        $unknown = array_values(array_filter($files, static fn (string $p) => !array_key_exists($p, $defaults)));
        if ($unknown !== []) {
            throw new \RuntimeException(
                'Scaffold contains uncategorised files (add them to ScaffoldManifestPolicy first): '
                . implode(', ', $unknown)
            );
        }

        $entries = [];
        foreach ($files as $relativePath) {
            $absolute = $scaffoldRoot . DIRECTORY_SEPARATOR . $relativePath;
            $currentHash = $this->hasher->hashFile($absolute);
            $policy = $defaults[$relativePath];

            $previous = $this->mergePreviousHashes($prior, $relativePath, $currentHash);

            $entries[$relativePath] = new ScaffoldFileEntry(
                path: $relativePath,
                currentSha256: $currentHash,
                previousSha256: $previous,
                category: $policy['category'],
                critical: $policy['critical'],
                autoUpdate: $policy['auto_update'],
                preserveExecutable: $policy['preserve_executable'],
                notes: $policy['notes'],
            );
        }
        ksort($entries);

        return new ScaffoldManifest(
            schemaVersion: ScaffoldManifest::SCHEMA_VERSION,
            generatedAt: $generatedAt ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            entries: $entries,
        );
    }

    /**
     * @return list<string>
     */
    private function listScaffoldFiles(string $scaffoldRoot): array
    {
        $files = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scaffoldRoot, \FilesystemIterator::SKIP_DOTS),
        );
        $rootLen = strlen($scaffoldRoot) + 1;
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), $rootLen);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $files[] = $relative;
        }
        sort($files);
        return $files;
    }

    /**
     * @return list<string>
     */
    private function mergePreviousHashes(?ScaffoldManifest $prior, string $path, string $currentHash): array
    {
        if ($prior === null) {
            return [];
        }
        $priorEntry = $prior->entryByPath($path);
        if ($priorEntry === null) {
            return [];
        }
        if ($priorEntry->currentSha256 === $currentHash) {
            return $priorEntry->previousSha256;
        }
        $merged = [$priorEntry->currentSha256, ...$priorEntry->previousSha256];
        return array_values(array_unique($merged));
    }
}
