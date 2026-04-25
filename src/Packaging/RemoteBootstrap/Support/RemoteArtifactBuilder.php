<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\RemoteBootstrap\Support;

use Semitexa\Update\Packaging\Releases\Support\ReleaseComposerManifestBuilder;
use Semitexa\Update\Packaging\RemoteBootstrap\Data\RemoteDeployArtifact;

final class RemoteArtifactBuilder
{
    /**
     * @var list<string>
     */
    private array $excludedPaths = [
        '.git',
        '.codex',
        '.claude',
        '.idea',
        '.env',
        'node_modules',
        'vendor',
        '.phpunit.cache',
        'var/cache',
        'var/log',
        'var/run',
    ];

    public function build(string $projectRoot): RemoteDeployArtifact
    {
        $artifactDir = sys_get_temp_dir() . '/semitexa-remote-artifacts';
        if (!is_dir($artifactDir) && !mkdir($artifactDir, 0777, true) && !is_dir($artifactDir)) {
            throw new \RuntimeException('Failed to create temporary artifact directory.');
        }

        $artifactPath = sprintf(
            '%s/%s-%s.tar.gz',
            $artifactDir,
            basename($projectRoot),
            gmdate('YmdHis'),
        );

        if ($this->hasReleaseManifest($projectRoot)) {
            $this->buildReleaseArtifact($projectRoot, $artifactPath);
        } else {
            $this->buildWorkspaceArtifact($projectRoot, $artifactPath);
        }

        if (!is_file($artifactPath)) {
            throw new \RuntimeException('Remote deployment artifact was not created.');
        }

        $sha256 = hash_file('sha256', $artifactPath);
        if ($sha256 === false) {
            throw new \RuntimeException('Failed to hash remote deployment artifact.');
        }

        return new RemoteDeployArtifact(
            path: $artifactPath,
            sizeBytes: filesize($artifactPath) ?: 0,
            sha256: $sha256,
        );
    }

    private function hasReleaseManifest(string $projectRoot): bool
    {
        return is_file($projectRoot . '/composer.release.json');
    }

    private function buildWorkspaceArtifact(string $projectRoot, string $artifactPath): void
    {
        $command = array_merge(
            ['tar', '-czf', $artifactPath],
            $this->buildExcludeArgs($this->excludedPaths),
            ['-C', $projectRoot, '.'],
        );

        $this->run($command);
    }

    private function buildReleaseArtifact(string $projectRoot, string $artifactPath): void
    {
        $stageRoot = $this->createTempStage($projectRoot);

        try {
            $releaseExcludes = array_values(array_unique(array_merge(
                array_values(array_filter(
                    $this->excludedPaths,
                    static fn(string $path): bool => $path !== 'vendor',
                )),
                [
                    'packages',
                ],
            )));

            $this->copyTree(
                $projectRoot,
                $stageRoot,
                $releaseExcludes,
                true,
            );

            $this->writeReleaseComposerManifest($projectRoot, $stageRoot . '/composer.json');

            @unlink($stageRoot . '/composer.release.json');
            @unlink($stageRoot . '/composer.dev-workspace.json');
            @unlink($stageRoot . '/composer.lock');

            $this->refreshComposerAutoload($stageRoot);
            $this->run(['tar', '-czf', $artifactPath, '-C', $stageRoot, '.']);
        } finally {
            $this->deleteDirectory($stageRoot);
        }
    }

    /**
     * @return list<string>
     */
    private function buildExcludeArgs(array $paths): array
    {
        $args = [];
        foreach ($paths as $path) {
            $args[] = '--exclude=' . $path;
        }

        return $args;
    }

    /**
     * @param list<string> $excludedPaths
     */
    private function copyTree(string $sourceRoot, string $targetRoot, array $excludedPaths, bool $dereferenceSymlinks): void
    {
        $command = ['tar'];
        if ($dereferenceSymlinks) {
            $command[] = '-h';
        }

        $command = array_merge(
            $command,
            ['-cf', '-'],
            $this->buildExcludeArgs($excludedPaths),
            ['-C', $sourceRoot, '.'],
        );

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $sourceProcess = proc_open($command, $descriptor, $sourcePipes);
        if (!is_resource($sourceProcess)) {
            throw new \RuntimeException('Failed to start source tar process.');
        }

        $targetProcess = proc_open(
            ['tar', '-xf', '-', '-C', $targetRoot],
            $descriptor,
            $targetPipes,
        );

        if (!is_resource($targetProcess)) {
            fclose($sourcePipes[0]);
            fclose($sourcePipes[1]);
            fclose($sourcePipes[2]);
            proc_close($sourceProcess);
            throw new \RuntimeException('Failed to start extraction tar process.');
        }

        fclose($sourcePipes[0]);
        fclose($targetPipes[1]);

        stream_copy_to_stream($sourcePipes[1], $targetPipes[0]);
        fclose($sourcePipes[1]);
        fclose($targetPipes[0]);

        $sourceStderr = stream_get_contents($sourcePipes[2]);
        $targetStderr = stream_get_contents($targetPipes[2]);
        fclose($sourcePipes[2]);
        fclose($targetPipes[2]);

        $sourceExitCode = proc_close($sourceProcess);
        $targetExitCode = proc_close($targetProcess);

        if ($sourceExitCode !== 0 || $targetExitCode !== 0) {
            throw new \RuntimeException("Artifact staging failed.\n" . trim($sourceStderr . "\n" . $targetStderr));
        }
    }

    private function createTempStage(string $projectRoot): string
    {
        $stageRoot = sys_get_temp_dir() . '/semitexa-remote-artifact-stage-' . basename($projectRoot) . '-' . bin2hex(random_bytes(4));
        if (!mkdir($stageRoot, 0777, true) && !is_dir($stageRoot)) {
            throw new \RuntimeException('Failed to create temporary artifact staging directory.');
        }

        return $stageRoot;
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $this->run(['rm', '-rf', $path]);
    }

    private function refreshComposerAutoload(string $projectRoot): void
    {
        $this->run([
            'composer',
            'dump-autoload',
            '--working-dir=' . $projectRoot,
            '--no-interaction',
            '--optimize',
        ]);
    }

    private function writeReleaseComposerManifest(string $projectRoot, string $destinationPath): void
    {
        $json = null;

        try {
            $manifest = (new ReleaseComposerManifestBuilder())->build($projectRoot);
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            $releaseManifestPath = $projectRoot . '/composer.release.json';
            if (is_file($releaseManifestPath)) {
                $json = file_get_contents($releaseManifestPath);
            }
        }

        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Failed to materialize release composer manifest for remote artifact.');
        }

        if (file_put_contents($destinationPath, $json . (str_ends_with($json, "\n") ? '' : "\n")) === false) {
            throw new \RuntimeException('Failed to write staged release composer.json.');
        }
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command): void
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptor, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start tar process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Artifact build failed.\n" . trim($stderr . "\n" . $stdout));
        }
    }
}
