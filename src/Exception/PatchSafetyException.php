<?php

declare(strict_types=1);

namespace Semitexa\Update\Exception;

/**
 * Raised when a patch attempts something that breaks the data-patch boundary —
 * either issuing DDL (must go through ORM) or running with a database schema
 * that is not compatible with the patch's declared requirements.
 */
final class PatchSafetyException extends UpdateException
{
}
