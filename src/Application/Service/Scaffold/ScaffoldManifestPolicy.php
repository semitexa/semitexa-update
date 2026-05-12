<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Scaffold;

use Semitexa\Update\Domain\Enum\ScaffoldFileCategory;

/**
 * Per-file sync policy. A file present in the scaffold source MUST appear in
 * this table; an uncategorised file aborts the manifest builder so a human
 * decides whether the file is critical, auto-updateable, executable, etc.
 *
 * `auto_update` here is metadata on the manifest entry; the actual decision
 * to overwrite a downstream file always also requires a matching prior hash
 * (the manifest never grants a free pass to overwrite arbitrary content).
 */
final class ScaffoldManifestPolicy
{
    /**
     * @return array<string, array{
     *     category: ScaffoldFileCategory,
     *     critical: bool,
     *     auto_update: bool,
     *     preserve_executable: bool,
     *     notes: string,
     * }>
     */
    public function defaults(): array
    {
        return [
            'bin/semitexa' => [
                'category' => ScaffoldFileCategory::Executable,
                'critical' => true,
                'auto_update' => true,
                'preserve_executable' => true,
                'notes' => 'Project CLI entry point. Auto-replace only when the project file matches a recorded prior hash; preserve +x; backup before replace.',
            ],
            '.env.default' => [
                'category' => ScaffoldFileCategory::EnvTemplate,
                'critical' => false,
                'auto_update' => true,
                'preserve_executable' => false,
                'notes' => 'Template only. The project .env file is never touched by scaffold sync.',
            ],
            '.gitignore' => [
                'category' => ScaffoldFileCategory::Vcs,
                'critical' => false,
                'auto_update' => true,
                'preserve_executable' => false,
                'notes' => '',
            ],
            'Dockerfile' => [
                'category' => ScaffoldFileCategory::Infrastructure,
                'critical' => false,
                'auto_update' => true,
                'preserve_executable' => false,
                'notes' => '',
            ],
            'docker-compose.yml' => [
                'category' => ScaffoldFileCategory::Infrastructure,
                'critical' => false,
                'auto_update' => true,
                'preserve_executable' => false,
                'notes' => '',
            ],
            'docker-compose.mysql.yml' => [
                'category' => ScaffoldFileCategory::Infrastructure,
                'critical' => false,
                'auto_update' => true,
                'preserve_executable' => false,
                'notes' => '',
            ],
            'docker-compose.nats.yml' => [
                'category' => ScaffoldFileCategory::Infrastructure,
                'critical' => false,
                'auto_update' => true,
                'preserve_executable' => false,
                'notes' => '',
            ],
            'docker-compose.redis.yml' => [
                'category' => ScaffoldFileCategory::Infrastructure,
                'critical' => false,
                'auto_update' => true,
                'preserve_executable' => false,
                'notes' => '',
            ],
            'docker-compose.ollama.yml' => [
                'category' => ScaffoldFileCategory::Infrastructure,
                'critical' => false,
                'auto_update' => true,
                'preserve_executable' => false,
                'notes' => '',
            ],
            'docker-compose.test.yml' => [
                'category' => ScaffoldFileCategory::Infrastructure,
                'critical' => false,
                'auto_update' => true,
                'preserve_executable' => false,
                'notes' => '',
            ],
            'docker-compose.override.yml.example' => [
                'category' => ScaffoldFileCategory::Example,
                'critical' => false,
                'auto_update' => true,
                'preserve_executable' => false,
                'notes' => 'Template only. The project-managed docker-compose.override.yml is never touched.',
            ],
        ];
    }
}
