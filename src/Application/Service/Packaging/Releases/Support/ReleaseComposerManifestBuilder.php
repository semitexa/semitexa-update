<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

final class ReleaseComposerManifestBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $projectRoot): array
    {
        $composerPath = $projectRoot . '/composer.json';
        $lockPath = $projectRoot . '/composer.lock';

        if (!is_file($composerPath)) {
            throw new \RuntimeException('composer.json not found.');
        }

        if (!is_file($lockPath)) {
            throw new \RuntimeException('composer.lock not found.');
        }

        $composer = $this->decodeJsonFile($composerPath);
        $versions = (new InstalledSemitexaPackageReader())->read($projectRoot);
        if ($versions === []) {
            throw new \RuntimeException('No installable Semitexa package versions were discovered in composer.lock.');
        }

        $this->materializeSemitexaConstraints($composer, $versions);

        $composer['repositories'] = $this->materializeRepositories(
            $projectRoot,
            is_array($composer['repositories'] ?? null) ? $composer['repositories'] : [],
        );

        foreach (['require', 'require-dev'] as $bucket) {
            if (!is_array($composer[$bucket] ?? null)) {
                continue;
            }

            ksort($composer[$bucket]);
        }

        $this->promoteOperationalDependencies($composer);

        $composer['minimum-stability'] = 'stable';
        $composer['prefer-stable'] = true;

        return $composer;
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function promoteOperationalDependencies(array &$composer): void
    {
        if (!is_array($composer['require-dev'] ?? null)) {
            return;
        }

        $devConstraint = $composer['require-dev']['semitexa/dev'] ?? null;
        if (!is_string($devConstraint) || $devConstraint === '') {
            return;
        }

        if (!is_array($composer['require'] ?? null)) {
            $composer['require'] = [];
        }

        if (!isset($composer['require']['semitexa/dev'])) {
            $composer['require']['semitexa/dev'] = $devConstraint;
        }

        unset($composer['require-dev']['semitexa/dev']);
        ksort($composer['require']);
        ksort($composer['require-dev']);
    }

    /**
     * @param array<string, mixed> $composer
     * @param array<string, string> $versions
     */
    private function materializeSemitexaConstraints(array &$composer, array $versions): void
    {
        foreach (['require', 'require-dev'] as $bucket) {
            if (!is_array($composer[$bucket] ?? null)) {
                continue;
            }

            foreach ($composer[$bucket] as $packageName => $_constraint) {
                if (!is_string($packageName) || !str_starts_with($packageName, 'semitexa/')) {
                    continue;
                }

                $version = $versions[$packageName] ?? null;
                if (!is_string($version) || $version === '') {
                    throw new \RuntimeException(sprintf(
                        'Locked version for package "%s" was not found in composer.lock.',
                        $packageName,
                    ));
                }

                $composer[$bucket][$packageName] = $version;
            }
        }
    }

    /**
     * @param array<int, mixed> $repositories
     * @return array<int, array<string, mixed>>
     */
    private function materializeRepositories(string $projectRoot, array $repositories): array
    {
        $result = [];
        $seenVcsUrls = [];

        foreach ($repositories as $repository) {
            if (!is_array($repository)) {
                continue;
            }

            $type = $repository['type'] ?? null;
            $url = $repository['url'] ?? null;

            if ($type !== 'path' || !is_string($url) || !str_starts_with($url, 'packages/semitexa-')) {
                $result[] = $repository;
                continue;
            }

            $packageRoot = $projectRoot . '/' . $url;
            $remoteUrl = $this->originRemoteUrl($packageRoot);

            if ($remoteUrl === null) {
                $remoteUrl = $this->canonicalGitHubRemoteFromPackageComposer($packageRoot);
            }

            if ($remoteUrl === null) {
                throw new \RuntimeException(sprintf(
                    'Unable to derive VCS URL for local package at "%s". Ensure the package has a .git origin or a composer.json with a semitexa/* name.',
                    $url,
                ));
            }

            $remoteUrl = $this->normalizeSemitexaGitHubRemote($remoteUrl);

            // Open-licensed packages are published to public Packagist and resolve
            // from there over anonymous HTTPS, so they need no repository entry —
            // dropping them keeps the deploy from depending on git access for openly
            // available packages. Only proprietary (private) packages keep a VCS
            // entry below (HTTPS, so the github-oauth token authenticates it).
            if ($this->isPubliclyPublishedPackage($packageRoot)) {
                continue;
            }

            if (isset($seenVcsUrls[$remoteUrl])) {
                continue;
            }

            // Private packages keep a VCS entry, but over HTTPS so composer
            // authenticates with the github-oauth token (no deploy SSH key needed).
            $result[] = [
                'type' => 'vcs',
                'url' => $remoteUrl,
            ];
            $seenVcsUrls[$remoteUrl] = true;
        }

        return $result;
    }

    private function originRemoteUrl(string $packageRoot): ?string
    {
        if (!is_dir($packageRoot . '/.git')) {
            return null;
        }

        $command = sprintf('git -C %s remote get-url origin 2>/dev/null', escapeshellarg($packageRoot));
        $remoteUrl = trim((string) shell_exec($command));

        return $remoteUrl !== '' ? $remoteUrl : null;
    }

    private function canonicalGitHubRemoteFromPackageComposer(string $packageRoot): ?string
    {
        $composerPath = $packageRoot . '/composer.json';
        if (!is_file($composerPath)) {
            return null;
        }

        $composer = $this->decodeJsonFile($composerPath);
        $packageName = $composer['name'] ?? null;
        if (!is_string($packageName) || !str_starts_with($packageName, 'semitexa/')) {
            return null;
        }

        $repoName = str_replace('/', '-', $packageName);
        return sprintf('git@github.com:semitexa/%s.git', $repoName);
    }

    private function normalizeSemitexaGitHubRemote(string $remoteUrl): string
    {
        // Emit HTTPS GitHub URLs so composer authenticates VCS access with the
        // github-oauth token instead of requiring an SSH deploy key on the server.
        if (preg_match('~(?:git@github\.com:|https://github\.com/)(semitexa/[^/]+?)(?:\.git)?$~i', $remoteUrl, $matches) === 1) {
            return sprintf('https://github.com/%s.git', strtolower($matches[1]));
        }

        return $remoteUrl;
    }

    /**
     * A package is publicly published (and therefore resolvable from Packagist with
     * no repository entry) when its license is NOT proprietary. Proprietary packages
     * are private and keep a token-authenticated HTTPS VCS entry. A missing/unknown
     * license is treated as proprietary (fail-safe: keep the VCS entry so a private
     * package never becomes unresolvable).
     */
    private function isPubliclyPublishedPackage(string $packageRoot): bool
    {
        $composerPath = $packageRoot . '/composer.json';
        if (!is_file($composerPath)) {
            return false;
        }

        $license = $this->decodeJsonFile($composerPath)['license'] ?? null;

        $licenses = is_array($license) ? $license : [$license];
        foreach ($licenses as $entry) {
            if (is_string($entry) && stripos($entry, 'proprietary') !== false) {
                return false;
            }
        }

        // An explicit open license means it is published publicly; no license at all
        // is treated as private (fail-safe).
        return $license !== null && $license !== '' && $license !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Failed to read ' . basename($path) . '.');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Failed to decode ' . basename($path) . '.');
        }

        return $data;
    }
}
