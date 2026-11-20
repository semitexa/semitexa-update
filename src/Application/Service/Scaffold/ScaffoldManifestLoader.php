<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Scaffold;

use Semitexa\Update\Domain\Enum\ScaffoldFileCategory;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldFileEntry;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;

/**
 * Reads + validates a scaffold manifest JSON file into a {@see ScaffoldManifest}.
 *
 * Validation is intentionally strict — the manifest is the source of truth
 * for "is this downstream file safe to auto-update?", so any structural
 * surprise is a hard error rather than a silent fallback.
 */
final class ScaffoldManifestLoader
{
    public function load(string $manifestPath): ScaffoldManifest
    {
        if (!is_file($manifestPath)) {
            throw new \RuntimeException("Scaffold manifest not found: {$manifestPath}");
        }
        $raw = file_get_contents($manifestPath);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read scaffold manifest: {$manifestPath}");
        }
        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Scaffold manifest is not valid JSON: {$manifestPath}: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data)) {
            throw new \RuntimeException("Scaffold manifest must be a JSON object: {$manifestPath}");
        }
        return $this->fromArray($data, $manifestPath);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data, string $sourceHint = '<array>'): ScaffoldManifest
    {
        // Every shape is checked before it is used: a manifest is read from
        // another package since one source for bin/semitexa, and what it holds
        // must fail as a RuntimeException, which the update turns into an
        // integrity error, never as a TypeError or a warning (review, update#121).
        $schemaVersion = is_string($data['schema_version'] ?? null) ? $data['schema_version'] : '';
        if ($schemaVersion !== ScaffoldManifest::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf(
                'Unsupported scaffold manifest schema_version "%s" in %s (expected "%s")',
                $schemaVersion,
                $sourceHint,
                ScaffoldManifest::SCHEMA_VERSION,
            ));
        }

        $generatedAt = is_string($data['generated_at'] ?? null) ? $data['generated_at'] : '';
        if ($generatedAt === '') {
            throw new \RuntimeException("Scaffold manifest in {$sourceHint} is missing generated_at.");
        }

        $files = $data['files'] ?? null;
        if (!is_array($files)) {
            throw new \RuntimeException("Scaffold manifest in {$sourceHint} is missing files[].");
        }
        if (!array_is_list($files)) {
            throw new \RuntimeException("Scaffold manifest in {$sourceHint} has a files section that is not a list.");
        }

        $entries = [];
        foreach ($files as $i => $raw) {
            if (!is_array($raw)) {
                throw new \RuntimeException("Scaffold manifest file entry #{$i} is not an object.");
            }
            $entry = $this->entryFromArray($raw, $sourceHint, $i);
            if (isset($entries[$entry->path])) {
                throw new \RuntimeException("Scaffold manifest has duplicate entry for path: {$entry->path}");
            }
            $entries[$entry->path] = $entry;
        }
        ksort($entries);

        return new ScaffoldManifest($schemaVersion, $generatedAt, $entries, $this->retiredFromArray($data['retired'] ?? [], $sourceHint, $entries));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function entryFromArray(array $raw, string $sourceHint, int $index): ScaffoldFileEntry
    {
        $path = $raw['path'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new \RuntimeException("Scaffold manifest file entry #{$index} in {$sourceHint} has no path.");
        }
        $currentSha256 = $raw['current_sha256'] ?? null;
        if (!is_string($currentSha256) || !$this->isSha256($currentSha256)) {
            throw new \RuntimeException("Scaffold manifest entry '{$path}' has invalid current_sha256.");
        }

        $previousSha256Raw = $raw['previous_sha256'] ?? [];
        if (!is_array($previousSha256Raw)) {
            throw new \RuntimeException("Scaffold manifest entry '{$path}' has non-list previous_sha256.");
        }
        $previousSha256 = [];
        foreach ($previousSha256Raw as $prev) {
            if (!is_string($prev) || !$this->isSha256($prev)) {
                throw new \RuntimeException("Scaffold manifest entry '{$path}' has invalid previous_sha256 element.");
            }
            $previousSha256[] = $prev;
        }

        $categoryRaw = $raw['category'] ?? null;
        $category = is_string($categoryRaw) ? ScaffoldFileCategory::tryFrom($categoryRaw) : null;
        if ($category === null) {
            throw new \RuntimeException(sprintf("Scaffold manifest entry '%s' has invalid category: %s", $path, is_string($categoryRaw) ? $categoryRaw : get_debug_type($categoryRaw)));
        }

        return new ScaffoldFileEntry(
            path: $path,
            currentSha256: $currentSha256,
            previousSha256: $previousSha256,
            category: $category,
            critical: (bool) ($raw['critical'] ?? false),
            autoUpdate: (bool) ($raw['auto_update'] ?? false),
            preserveExecutable: (bool) ($raw['preserve_executable'] ?? false),
            notes: is_string($raw['notes'] ?? null) ? $raw['notes'] : '',
        );
    }

    /**
     * The update deletes what this list names, so it is read as strictly as
     * the files: a path that could leave the project, or one the scaffold
     * still ships, stops the load.
     *
     * @param array<string, ScaffoldFileEntry> $entries
     * @return array<string, list<string>>
     */
    private function retiredFromArray(mixed $raw, string $sourceHint, array $entries): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \RuntimeException("Scaffold manifest in {$sourceHint} has a retired section that is not a list.");
        }
        $retired = [];
        foreach ($raw as $index => $item) {
            if (!is_array($item)) {
                throw new \RuntimeException("Scaffold manifest retired entry #{$index} in {$sourceHint} is not an object.");
            }
            $path = $item['path'] ?? null;
            if (!is_string($path) || !$this->isProjectRelativePath($path)) {
                throw new \RuntimeException("Scaffold manifest retired entry #{$index} in {$sourceHint} has an unsafe path.");
            }
            if (isset($retired[$path])) {
                throw new \RuntimeException("Scaffold manifest has duplicate retired entry for path: {$path}");
            }
            if (isset($entries[$path])) {
                throw new \RuntimeException("Scaffold manifest lists {$path} as both shipped and retired.");
            }
            $hashes = $item['shipped_sha256'] ?? null;
            if (!is_array($hashes) || $hashes === []) {
                throw new \RuntimeException("Scaffold manifest retired entry '{$path}' has no shipped_sha256.");
            }
            $valid = [];
            foreach ($hashes as $hash) {
                if (!is_string($hash) || !$this->isSha256($hash)) {
                    throw new \RuntimeException("Scaffold manifest retired entry '{$path}' has an invalid shipped_sha256 element.");
                }
                $valid[] = $hash;
            }
            $retired[$path] = array_values(array_unique($valid));
        }
        ksort($retired, SORT_STRING);

        return $retired;
    }

    /** Relative, forward slashes, no empty, '.' or '..' segment: it names a file inside the project. */
    private function isProjectRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function isSha256(string $candidate): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $candidate) === 1;
    }
}
