<?php

declare(strict_types=1);

namespace Semitexa\Update;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'update.lifecycle',
    summary: 'Framework update orchestration: version detection, deploy, and post-schema data patches via #[AsDataPatch].',
    useWhen: 'Installed deployments have to move to a newer framework version, and data has to be reshaped when they do.',
    avoidWhen: 'A single deployment you update by hand and can watch while it happens.',
    replaces: [
        'a shell script that composer-updates and hopes the schema kept up',
        'a one-off SQL file run manually on each environment, with no record of where it ran',
    ],
)]
final class Capabilities
{
}
