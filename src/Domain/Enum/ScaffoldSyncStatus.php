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
    /**
     * semitexa/ultimate shipped this path once and no longer does (the
     * manifest's retired list), and the project still has it.
     */
    case Retired = 'retired';
}
