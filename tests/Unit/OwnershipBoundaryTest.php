<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Regression guard for the data-patch / schema-migration boundary.
 *
 * semitexa-update owns post-schema data patches but is NOT a schema migration
 * system. The ORM (`orm:diff` / `orm:sync`) is the sole owner of structural DDL.
 * The journal table CREATE is the only DDL allowed in this package; anything else
 * is drift and this test fires.
 *
 * Also asserts the README explicitly disclaims schema-migration use.
 *
 * Companion docs: var/docs/UPDATE_DEV_ORM_OWNERSHIP_AUDIT.md, epic ep-update-dev-orm-ownership.
 */
final class OwnershipBoundaryTest extends TestCase
{
    public function testOnlyJournalRepositoryIssuesDdl(): void
    {
        $srcRoot = realpath(__DIR__ . '/../../src');
        self::assertNotFalse($srcRoot, 'package src/ directory not found');

        $offending = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            if ($item->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($srcRoot));
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            // The journal table CREATE is the one place where DDL is legitimate
            // (the journal is per-database bookkeeping that the runner owns).
            if ($relative === '/Journal/JournalRepository.php') {
                continue;
            }

            $contents = (string) file_get_contents($item->getPathname());
            $stripped = $this->stripCommentsAndDocblocks($contents);

            // Look only for the keyword pair that would actually mutate schema —
            // not the FORBIDDEN_LEADING_KEYWORDS constant in DataPatchContext or
            // the literal class names in error messages.
            foreach (['CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'RENAME TABLE'] as $forbidden) {
                if (str_contains(strtoupper($stripped), $forbidden)) {
                    $offending[] = sprintf('%s contains "%s"', $relative, $forbidden);
                }
            }
        }

        self::assertSame(
            [],
            $offending,
            "Only Journal/JournalRepository.php may issue DDL inside semitexa-update.\n"
            . 'Schema migrations belong to semitexa-orm. Offending: ' . implode('; ', $offending),
        );
    }

    public function testReadmeDoesNotEndorseSchemaMigrations(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../../README.md');

        // Allow the disclaimers ("not a schema migration system", "schema migrations belong to ORM")
        // by only failing when the README USES "schema migrations" affirmatively.
        $forbiddenPhrases = [
            'use semitexa/update for schema migrations',
            'schema migrations are a use-case',
            'schema migrations are supported',
        ];

        foreach ($forbiddenPhrases as $phrase) {
            self::assertStringNotContainsStringIgnoringCase(
                $phrase,
                $readme,
                "README must not endorse semitexa-update for schema migrations (matched: '{$phrase}').",
            );
        }
    }

    private function stripCommentsAndDocblocks(string $php): string
    {
        // Strip /* ... */ blocks
        $php = preg_replace('!/\*.*?\*/!s', '', $php) ?? $php;
        // Strip // line comments
        $php = preg_replace('!^\s*//.*$!m', '', $php) ?? $php;
        return $php;
    }
}
