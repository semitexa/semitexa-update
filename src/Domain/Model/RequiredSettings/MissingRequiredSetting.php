<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\RequiredSettings;

/**
 * One failing doctor check that names the env settings it judges
 * (`#[AsDoctorCheck(settings: …)]`): the installed code needs them and this
 * environment does not have them.
 */
final readonly class MissingRequiredSetting
{
    /**
     * @param non-empty-list<string> $settings
     */
    public function __construct(
        public string $check,
        public string $package,
        public array $settings,
        public string $message,
        public ?string $hint,
    ) {
    }

    /** One line: the settings, the check that judged them, and how to fix it. */
    public function describe(): string
    {
        return sprintf(
            '%s (check %s%s): %s',
            implode(', ', $this->settings),
            $this->check,
            $this->package !== '' ? ', ' . $this->package : '',
            $this->hint ?? $this->message,
        );
    }
}
