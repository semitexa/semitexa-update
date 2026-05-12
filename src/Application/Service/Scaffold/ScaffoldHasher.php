<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Scaffold;

/**
 * Computes the SHA-256 of a file's raw bytes.
 *
 * Centralised so the manifest builder, the future sync engine, and any test
 * fixture generator all produce identical hashes for the same content.
 */
final class ScaffoldHasher
{
    public function hashFile(string $absolutePath): string
    {
        $hash = hash_file('sha256', $absolutePath);
        if ($hash === false) {
            throw new \RuntimeException("Unable to hash file: {$absolutePath}");
        }
        return $hash;
    }

    public function hashBytes(string $bytes): string
    {
        return hash('sha256', $bytes);
    }
}
