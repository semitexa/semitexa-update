<?php

declare(strict_types=1);

namespace Semitexa\Update\Context;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;

/**
 * The only object an UpdateStep receives. Keeps step bodies decoupled from
 * the container and from the rest of Semitexa. V1 exposes database access;
 * future versions will add batching, logger, and env-config accessors.
 */
final class UpdateContext
{
    public function __construct(
        public readonly DatabaseAdapterInterface $db,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): QueryResult
    {
        return $this->db->execute($sql, $params);
    }

    public function query(string $sql): QueryResult
    {
        return $this->db->query($sql);
    }
}
