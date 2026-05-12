<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

/**
 * Per-package drift classification produced by PackageDriftInspector.
 *
 * Statuses are computed purely from local state (composer.json, composer.lock,
 * vendor/composer/installed.json). The inspector never talks to Packagist or
 * any remote source in this default mode — that lives behind the (future)
 * --check-upstream extension point.
 */
enum PackageDriftStatus: string
{
    case Clean = 'clean';
    case LockStale = 'lock_stale';
    case VendorStale = 'vendor_stale';
    case MissingFromVendor = 'missing_from_vendor';
    case MissingFromLock = 'missing_from_lock';
    case VersionMismatch = 'version_mismatch';
    case MixedReleaseSet = 'mixed_release_set';
    case PathRepository = 'path_repository';
    case DevConstraint = 'dev_constraint';

    public function isActionable(): bool
    {
        return match ($this) {
            self::Clean,
            self::PathRepository,
            self::DevConstraint => false,
            default => true,
        };
    }
}
