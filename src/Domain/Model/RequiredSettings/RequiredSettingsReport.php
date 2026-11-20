<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\RequiredSettings;

/**
 * What the doctor of the installed code said about required settings.
 *
 * Only `missing` fails the stage. A doctor that could not be run or read is
 * carried in `probeError` and shown, never taken for a missing setting: an
 * update must not stop because a diagnosis did.
 */
final readonly class RequiredSettingsReport
{
    /**
     * @param string $appEnv the APP_ENV the checks judged (process env, then .env)
     * @param list<MissingRequiredSetting> $missing
     */
    public function __construct(
        public string $appEnv,
        public array $missing = [],
        public ?string $probeError = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->missing === [];
    }

    /** @return list<string> */
    public function settingNames(): array
    {
        $names = [];
        foreach ($this->missing as $missing) {
            array_push($names, ...$missing->settings);
        }

        return array_values(array_unique($names));
    }

    /** One line for the run journal and the deployment log; null when nothing is missing. */
    public function describe(): ?string
    {
        if ($this->missing === []) {
            return null;
        }

        return sprintf(
            'Required settings are missing for APP_ENV=%s: %s',
            $this->appEnv,
            implode('; ', array_map(static fn (MissingRequiredSetting $m): string => $m->describe(), $this->missing)),
        );
    }
}
