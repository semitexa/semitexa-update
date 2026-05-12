<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

/**
 * Classification of a single downstream project file against the scaffold
 * manifest. Computed by {@see \Semitexa\Update\Application\Service\Scaffold\ScaffoldFileClassifier}
 * before any file I/O is performed.
 */
enum ScaffoldSyncStatus: string
{
    case Current = 'current';
    case KnownPrevious = 'known_previous';
    case LocallyModified = 'locally_modified';
    case Missing = 'missing';
}
