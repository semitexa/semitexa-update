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
     * The URL as it may be shown: scheme, host, port and path only. Userinfo,
     * query and fragment are dropped whole, with *** where they stood. Null
     * when the text has no such form (whitespace, an option, a helper, a path
     * with characters a repository path does not need): then none of it may
     * be shown, because nothing says which part of it is a secret.
     *
     * The text is matched whole against a strict form; nothing is masked in
     * place, so a value no pattern foresaw cannot slip past one.
     */
    public static function reduced(string $url): ?string
    {
        if ($url === '' || preg_match('~[\s\x00-\x1F\x7F]~', $url) === 1) {
            return null;
        }

        $path = '[A-Za-z0-9._/+-]';

        if (preg_match('~^(?:file://)?/' . $path . '*$~', $url) === 1) {
            return $url;
        }

        $form = '~^([A-Za-z][A-Za-z0-9+.-]*)://(?:([^/?#]*)@)?(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(:[0-9]+)?(/' . $path . '*)?(\?[^#]*)?(#.*)?$~';
        if (preg_match($form, $url, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            return $m[1] . '://'
                . ($m[2] !== null ? '***@' : '')
                . $m[3]
                . ($m[4] ?? '')
                . ($m[5] ?? '')
                . ($m[6] !== null ? '?***' : '')
                . ($m[7] !== null ? '#***' : '');
        }

        if (preg_match('~^[^@]+@([A-Za-z0-9][A-Za-z0-9.-]*):(' . $path . '+)$~', $url, $m) === 1) {
            return '***@' . $m[1] . ':' . $m[2];
        }

        return null;
    }

    /**
     * How a message names the URL: its reduced form in quotes, or a note that
     * it is not shown.
     */
    public static function label(string $url): string
    {
        $reduced = self::reduced($url);

        return $reduced !== null ? '"' . $reduced . '"' : '(not shown: it has no form free of credentials)';
    }
}
