<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use JsonException;
use ReflectionClass;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lint every discovered data patch class.
 *
 * Fails when:
 *   - a #[AsDataPatch] class does not implement DataPatchInterface
 *   - id or module is empty
 *   - neither requires nor requiresColumns is declared
 *   - the patch source file contains a forbidden DDL keyword
 *     (CREATE TABLE / ALTER TABLE / DROP TABLE / RENAME TABLE / TRUNCATE)
 *
 * The DDL keyword scan is intentionally conservative — it greps for the keyword
 * pair. False positives are easier to fix (move the SQL to ORM) than the data
 * corruption that the boundary prevents.
 */
#[AsCommand(name: 'update:lint:patches', description: 'Lint every #[AsDataPatch] for required metadata and the data-only boundary.')]
final class UpdateLintPatchesCommand extends BaseCommand
{
    /** @var list<string> */
    private const FORBIDDEN_PATTERNS = [
        '/\bCREATE\s+TABLE\b/i',
        '/\bALTER\s+TABLE\b/i',
        '/\bDROP\s+TABLE\b/i',
        '/\bRENAME\s+TABLE\b/i',
        '/\bTRUNCATE\s+TABLE\b/i',
        '/\bTRUNCATE\b\s+\w+/i',
    ];

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the lint result as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issues = [];
        $patchCount = 0;

        $fqcns = $this->classDiscovery->findClassesWithAttribute(AsDataPatch::class);

        foreach ($fqcns as $fqcn) {
            $patchCount++;
            $issues = array_merge($issues, $this->lint($fqcn));
        }

        if ((bool) $input->getOption('json')) {
            $payload = [
                'artifact'      => 'semitexa.update.lint-patches/v1',
                'patches_count' => $patchCount,
                'issues'        => $issues,
                'status'        => $issues === [] ? 'ok' : 'fail',
            ];
            try {
                $output->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } catch (JsonException $e) {
                $output->writeln('<error>Failed to encode lint result as JSON: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
            return $issues === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Semitexa Data-Patch Lint');
        $io->writeln(sprintf('Discovered %d patch(es).', $patchCount));

        if ($issues === []) {
            $io->success('All data patches passed lint.');
            return Command::SUCCESS;
        }

        foreach ($issues as $issue) {
            $io->writeln(sprintf('  · %s — %s', $issue['fqcn'], $issue['message']));
        }
        $io->error(sprintf('%d issue(s) found.', count($issues)));
        return Command::FAILURE;
    }

    /**
     * @return list<array{fqcn: string, message: string}>
     */
    private function lint(string $fqcn): array
    {
        $issues = [];

        if (!class_exists($fqcn)) {
            return [['fqcn' => $fqcn, 'message' => 'class is not autoloadable']];
        }

        $ref = new ReflectionClass($fqcn);

        if (!$ref->implementsInterface(DataPatchInterface::class)) {
            $issues[] = ['fqcn' => $fqcn, 'message' => 'does not implement DataPatchInterface'];
        }

        $attrs = $ref->getAttributes(AsDataPatch::class);
        if ($attrs === []) {
            return $issues;
        }

        /** @var AsDataPatch $attr */
        $attr = $attrs[0]->newInstance();

        if ($attr->id === '') {
            $issues[] = ['fqcn' => $fqcn, 'message' => 'AsDataPatch->id is empty'];
        }
        if ($attr->module === '') {
            $issues[] = ['fqcn' => $fqcn, 'message' => 'AsDataPatch->module is empty'];
        }
        if ($attr->requires === [] && $attr->requiresColumns === []) {
            $issues[] = [
                'fqcn'    => $fqcn,
                'message' => 'declares neither requires nor requiresColumns; the schema-compatibility gate cannot run',
            ];
        }

        $filePath = $ref->getFileName();
        if (is_string($filePath) && is_readable($filePath)) {
            $source = (string) file_get_contents($filePath);
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $issues[] = [
                        'fqcn'    => $fqcn,
                        'message' => sprintf(
                            'source contains a forbidden DDL keyword (matched %s); schema changes belong to the ORM',
                            $pattern,
                        ),
                    ];
                    break;
                }
            }
        }

        return $issues;
    }
}
