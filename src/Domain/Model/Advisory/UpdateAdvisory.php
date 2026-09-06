<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Advisory;

/**
 * Something a package wants the operator to know right after an update ran.
 *
 * Read-only by construction. An advisory never changes anything and never
 * blocks the update — it exists for the facts that only become interesting at
 * the moment the framework rewrote something underneath the install, and that
 * nobody would think to go and ask about afterwards.
 *
 * The first of them is prompt-override drift: a tenant override wins over the
 * shipped prompt forever, so an update that improves a prompt leaves that
 * tenant on a frozen copy and says nothing. `prompt:override list` has told the
 * truth about that for a while; the moment it matters is the update that caused
 * it, and nobody runs a read command on the off-chance.
 */
final readonly class UpdateAdvisory
{
    /**
     * @param string       $id       stable identity, `module:name`, for tests and ordering
     * @param string       $title    one line naming what this is about
     * @param list<string> $lines    the detail, already formatted for a terminal
     * @param bool         $actionable whether the operator has something to do
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $lines,
        public bool $actionable,
    ) {
    }

    /** Nothing to report — rendered as a clean line rather than silence. */
    public static function clean(string $id, string $title, string $line): self
    {
        return new self($id, $title, [$line], false);
    }

    /**
     * @param list<string> $lines
     */
    public static function actionable(string $id, string $title, array $lines): self
    {
        return new self($id, $title, $lines, true);
    }
}
