<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

/**
 * Decides whether a configured repository URL may be handed to git.
 *
 * git reads a leading "-" as an option (--upload-pack runs a program) and
 * "<helper>::<address>" as a transport helper (ext:: runs a command), so only
 * the shapes named here pass: https://, ssh://, user@host:path, file:/// and
 * an absolute local path.
 */
final class GitRepositoryUrl
{
    public const ACCEPTED = 'https://, ssh://, user@host:path, file:/// or an absolute local path';

    private const ALLOWED = [
        '~^(?:https|ssh)://(?:[^/@\s-][^/@\s]*@)?[A-Za-z0-9\[]\S*$~',
        '~^[A-Za-z0-9_][A-Za-z0-9_.-]*@[A-Za-z0-9][A-Za-z0-9.-]*:(?!:)\S+$~',
        '~^file:///\S*$~',
        '~^/\S*$~',
    ];

    /**
     * @return string|null why the URL is refused, or null when git may read it
     */
    public static function refusal(string $url): ?string
    {
        if ($url === '') {
            return 'it is empty';
        }

        if ($url[0] === '-') {
            return 'it starts with "-", which git would read as an option';
        }

        if (preg_match('~[\s\x00-\x1F\x7F]~', $url) === 1) {
            return 'it contains whitespace or control characters';
        }

        foreach (self::ALLOWED as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return null;
            }
        }

        return 'its form is not accepted (transport helpers such as ext:: or fd:: run programs)';
    }

    /**
     * The URL as it may be printed in a message: control characters replaced.
     */
    public static function printable(string $url): string
    {
        return (string) preg_replace('~[\x00-\x1F\x7F]~', '?', $url);
    }
}
