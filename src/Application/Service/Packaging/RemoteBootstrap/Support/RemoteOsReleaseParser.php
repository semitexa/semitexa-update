<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support;

use Semitexa\Update\Domain\Model\RemoteOsInfo;

final class RemoteOsReleaseParser
{
    public function parse(string $content): RemoteOsInfo
    {
        $values = [];
        foreach (preg_split('/\R/', trim($content)) ?: [] as $line) {
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, "\"'");
        }

        $id = strtolower((string) ($values['ID'] ?? ''));
        $versionId = (string) ($values['VERSION_ID'] ?? '');
        $prettyName = isset($values['PRETTY_NAME']) ? (string) $values['PRETTY_NAME'] : null;

        if ($id === '' || $versionId === '') {
            throw new \RuntimeException('Remote /etc/os-release is missing ID or VERSION_ID.');
        }

        return new RemoteOsInfo(
            id: $id,
            versionId: $versionId,
            prettyName: $prettyName,
        );
    }
}
