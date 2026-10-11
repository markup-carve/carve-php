<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use LogicException;

final class DjotNoteStack
{
    /**
     * @var list<\MarkupCarve\Carve\Converter\DjotNoteContext>
     */
    private array $notes = [];

    public function push(DjotNoteContext $note): void
    {
        $this->notes[] = $note;
    }

    public function pop(): DjotNoteContext
    {
        $note = array_pop($this->notes);
        if ($note === null) {
            throw new LogicException('Cannot finish a note without an owner.');
        }

        return $note;
    }

    public function last(): ?DjotNoteContext
    {
        return $this->notes !== [] ? $this->notes[array_key_last($this->notes)] : null;
    }

    public function isEmpty(): bool
    {
        return $this->notes === [];
    }
}
