<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Scaffold;

/**
 * In-memory representation of the scaffold hash manifest.
 *
 * The manifest is the authoritative answer to "what should a fresh project
 * root look like, and which hashes have we recognised in the past?". It is
 * used by the future scaffold sync engine to classify downstream project
 * files; it never describes the live state of a downstream project.
 */
final readonly class ScaffoldManifest
{
    public const SCHEMA_VERSION = 'semitexa.scaffold-manifest/v1';

    /**
     * @param array<string, ScaffoldFileEntry> $entries Keyed by project-relative path.
     * @param array<string, list<string>>     $retired Paths semitexa/ultimate shipped once and
     *                                                 no longer does, each with the sha256 of every
     *                                                 version its history has of it. A project's copy
     *                                                 that matches one is the framework's, and goes.
     */
    public function __construct(
        public string $schemaVersion,
        public string $generatedAt,
        public array $entries,
        public array $retired = [],
    ) {
    }

    public function entryByPath(string $path): ?ScaffoldFileEntry
    {
        return $this->entries[$path] ?? null;
    }

    /**
     * @return list<ScaffoldFileEntry>
     */
    public function entriesMatchingHash(string $sha256): array
    {
        $matches = [];
        foreach ($this->entries as $entry) {
            if ($entry->classifyHash($sha256) !== \Semitexa\Update\Domain\Enum\ScaffoldFileHashMatch::Unknown) {
                $matches[] = $entry;
            }
        }
        return $matches;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->entries);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $files = [];
        foreach ($this->entries as $entry) {
            $files[] = $entry->toArray();
        }
        $retired = [];
        $paths = array_keys($this->retired);
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $hashes = $this->retired[$path];
            sort($hashes, SORT_STRING);
            $retired[] = ['path' => $path, 'shipped_sha256' => $hashes];
        }
        return [
            'schema_version' => $this->schemaVersion,
            'generated_at' => $this->generatedAt,
            'files' => $files,
            'retired' => $retired,
        ];
    }
}
