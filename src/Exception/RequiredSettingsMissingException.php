<?php

declare(strict_types=1);

namespace Semitexa\Update\Exception;

/**
 * The code a deploy installed needs settings this environment does not have.
 * An ordinary deploy failure to whoever catches it; it carries the names for
 * the blocked marker.
 */
final class RequiredSettingsMissingException extends \RuntimeException
{
    /**
     * @param list<string> $settings
     */
    public function __construct(string $message, public readonly array $settings)
    {
        parent::__construct($message);
    }
}
