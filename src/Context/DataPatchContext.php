<?php

declare(strict_types=1);

namespace Semitexa\Update\Context;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Update\Exception\PatchSafetyException;

/**
 * The only object a DataPatch receives.
 *
 * Surface is intentionally narrow:
 *   - execute() / query() forward to the database adapter for row-level work
 *   - both methods reject DDL statements at runtime (best-effort keyword scan);
 *     schema mutations belong to `semitexa-orm` (`orm:diff` / `orm:sync`)
 *
 * This is the architectural boundary: a patch operates on existing data; if the
 * patch needs new tables or columns, that work belongs to ORM and must happen
 * before the patch runs.
 */
final class DataPatchContext
{
    /**
     * Keywords whose presence in the leading non-comment, non-whitespace tokens of
     * a SQL statement indicates schema mutation. Matched case-insensitively.
     *
     * @var list<string>
     */
    private const FORBIDDEN_LEADING_KEYWORDS = [
        'CREATE',
        'ALTER',
        'DROP',
        'RENAME',
        'TRUNCATE',
    ];

    public function __construct(
        public readonly DatabaseAdapterInterface $db,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): QueryResult
    {
        $this->guardDataOnly($sql);
        return $this->db->execute($sql, $params);
    }

    public function query(string $sql): QueryResult
    {
        $this->guardDataOnly($sql);
        return $this->db->query($sql);
    }

    private function guardDataOnly(string $sql): void
    {
        $leading = $this->firstStatementKeyword($sql);
        if ($leading !== null && in_array($leading, self::FORBIDDEN_LEADING_KEYWORDS, true)) {
            throw new PatchSafetyException(sprintf(
                "Data patches must not issue DDL. Got '%s …'. "
                . 'Schema migrations belong to the ORM (orm:diff / orm:sync); '
                . "this context only accepts row-level statements.",
                $leading,
            ));
        }
    }

    /**
     * Return the first SQL keyword (uppercased), skipping leading comments and
     * whitespace. Returns null for an empty / fully-commented input.
     */
    private function firstStatementKeyword(string $sql): ?string
    {
        $stripped = $this->stripLeadingNoise($sql);
        if ($stripped === '') {
            return null;
        }

        if (preg_match('/^([A-Za-z]+)/', $stripped, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function stripLeadingNoise(string $sql): string
    {
        $sql = ltrim($sql);

        while ($sql !== '') {
            if (str_starts_with($sql, '--')) {
                $newline = strpos($sql, "\n");
                $sql = $newline === false ? '' : ltrim(substr($sql, $newline + 1));
                continue;
            }
            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/');
                $sql = $end === false ? '' : ltrim(substr($sql, $end + 2));
                continue;
            }
            break;
        }

        return $sql;
    }
}
