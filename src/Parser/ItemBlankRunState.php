<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Blank-run decisions for one collected item.
 *
 * @internal
 */
final class ItemBlankRunState
{
    private bool $active = false;

    private bool $nestedNoteContinues = false;

    private bool $noteAfterCodeFence = false;

    public function active(): bool
    {
        return $this->active;
    }

    public function rememberNoteAfterCodeFence(bool $value): void
    {
        $this->noteAfterCodeFence = $value;
    }

    public function begin(bool $nestedNoteContinues): void
    {
        $this->nestedNoteContinues = $nestedNoteContinues;
        $this->active = true;
    }

    public function end(): void
    {
        $this->active = false;
        $this->nestedNoteContinues = false;
    }

    public function nestedNoteContinues(): bool
    {
        return $this->nestedNoteContinues;
    }

    public function noteAfterCodeFence(): bool
    {
        return $this->noteAfterCodeFence;
    }

    public function clearNoteAfterCodeFence(): void
    {
        $this->noteAfterCodeFence = false;
    }
}
