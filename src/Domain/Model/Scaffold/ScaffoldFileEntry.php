<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Scaffold;

use Semitexa\Update\Domain\Enum\ScaffoldFileCategory;
use Semitexa\Update\Domain\Enum\ScaffoldFileHashMatch;

/**
 * One scaffold file as captured by the manifest.
 *
 * Hash semantics: SHA-256 over the raw file bytes. No newline normalisation,
 * no trimming, no BOM stripping — what you read from disk is what the hash
 * covers. Executable bits and other filesystem metadata are NOT part of the
 * hash; they are recorded separately via `preserveExecutable`.
 *
 * `currentSha256` is the hash this entry will match against the active
 * scaffold release. `previousSha256` is an append-only list of hashes that
 * were `currentSha256` in previous releases — the future sync engine treats
 * them as "known prior scaffold" and is allowed to auto-update (with backup)
 * downstream files that match them.
 */
final readonly class ScaffoldFileEntry
{
    /**
     * @param list<string> $previousSha256 SHA-256 hashes recognised as prior scaffold versions. Order is informational.
     */
    public function __construct(
        public string $path,
        public string $currentSha256,
        public array $previousSha256,
        public ScaffoldFileCategory $category,
        public bool $critical,
        public bool $autoUpdate,
        public bool $preserveExecutable,
        public string $notes,
    ) {
    }

    public function classifyHash(string $sha256): ScaffoldFileHashMatch
    {
        if ($sha256 === $this->currentSha256) {
            return ScaffoldFileHashMatch::Current;
        }
        if (in_array($sha256, $this->previousSha256, true)) {
            return ScaffoldFileHashMatch::KnownPrevious;
        }
        return ScaffoldFileHashMatch::Unknown;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'current_sha256' => $this->currentSha256,
            'previous_sha256' => $this->previousSha256,
            'category' => $this->category->value,
            'critical' => $this->critical,
            'auto_update' => $this->autoUpdate,
            'preserve_executable' => $this->preserveExecutable,
            'notes' => $this->notes,
        ];
    }
}
