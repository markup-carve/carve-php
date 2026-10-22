<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Djot;

use LogicException;

final class NoteStack
{
    /**
     * @var list<\MarkupCarve\Carve\Converter\Djot\NoteContext>
     */
    private array $notes = [];

    public function push(NoteContext $note): void
    {
        $this->notes[] = $note;
    }

    public function pop(): NoteContext
    {
        $note = array_pop($this->notes);
        if ($note === null) {
            throw new LogicException('Cannot finish a note without an owner.');
        }

        return $note;
    }

    public function last(): ?NoteContext
    {
        return $this->notes !== [] ? $this->notes[array_key_last($this->notes)] : null;
    }

    public function isEmpty(): bool
    {
        return $this->notes === [];
    }
}
