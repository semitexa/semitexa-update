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
        $schemaVersion = (string) ($data['schema_version'] ?? '');
        if ($schemaVersion !== ScaffoldManifest::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf(
                'Unsupported scaffold manifest schema_version "%s" in %s (expected "%s")',
                $schemaVersion,
                $sourceHint,
                ScaffoldManifest::SCHEMA_VERSION,
            ));
        }

        $generatedAt = (string) ($data['generated_at'] ?? '');
        if ($generatedAt === '') {
            throw new \RuntimeException("Scaffold manifest in {$sourceHint} is missing generated_at.");
        }

        $files = $data['files'] ?? null;
        if (!is_array($files)) {
            throw new \RuntimeException("Scaffold manifest in {$sourceHint} is missing files[].");
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

        return new ScaffoldManifest($schemaVersion, $generatedAt, $entries);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function entryFromArray(array $raw, string $sourceHint, int $index): ScaffoldFileEntry
    {
        $path = (string) ($raw['path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException("Scaffold manifest file entry #{$index} in {$sourceHint} has no path.");
        }
        $currentSha256 = (string) ($raw['current_sha256'] ?? '');
        if (!$this->isSha256($currentSha256)) {
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

        $categoryRaw = (string) ($raw['category'] ?? '');
        $category = ScaffoldFileCategory::tryFrom($categoryRaw);
        if ($category === null) {
            throw new \RuntimeException("Scaffold manifest entry '{$path}' has invalid category: {$categoryRaw}");
        }

        return new ScaffoldFileEntry(
            path: $path,
            currentSha256: $currentSha256,
            previousSha256: $previousSha256,
            category: $category,
            critical: (bool) ($raw['critical'] ?? false),
            autoUpdate: (bool) ($raw['auto_update'] ?? false),
            preserveExecutable: (bool) ($raw['preserve_executable'] ?? false),
            notes: (string) ($raw['notes'] ?? ''),
        );
    }

    private function isSha256(string $candidate): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $candidate) === 1;
    }
}
