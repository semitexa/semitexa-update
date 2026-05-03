<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support;

final class LocalPrerequisiteChecker
{
    /**
     * @return list<string>
     */
    public function missingBaseTools(): array
    {
        $missing = [];
        foreach (['ssh', 'scp', 'tar'] as $binary) {
            if (!$this->hasBinary($binary)) {
                $missing[] = $binary;
            }
        }

        return $missing;
    }

    public function hasSshpass(): bool
    {
        return $this->hasBinary('sshpass');
    }

    private function hasBinary(string $binary): bool
    {
        return trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null')) !== '';
    }
}
