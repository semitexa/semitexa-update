<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\RequiredSettings;

/**
 * What the doctor of the installed code said about required settings.
 *
 * `missing` fails the stage. A doctor that could not be run or read is
 * carried in `probeError` and shown, never taken for a missing setting: an
 * update must not stop because a diagnosis did.
 *
 * `failure` fails the stage too: the gate could not read a file of its own
 * (.env, to know which environment it judges). That is not a diagnosis that
 * broke; it is a gate that would otherwise pass without having looked.
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
        public ?string $failure = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->missing === [] && $this->failure === null;
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

    /** One line for the run journal and the deployment log; null when the stage passes. */
    public function describe(): ?string
    {
        if ($this->failure !== null) {
            return 'Required settings could not be checked: ' . $this->failure;
        }
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
