<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * List item
 *
 * For task lists, stores the raw marker character from inside brackets:
 * - ' ' (space) or '_' (underscore) = unchecked
 * - 'x' or 'X' = checked/completed
 * - Extended markers (for custom rendering via events):
 *   - '-' = cancelled/not applicable
 *   - '/' = in progress (partial)
 *   - '>' = deferred/forwarded
 *   - '?' = question/needs clarification
 *   - '*' = in process/active
 *   - '=' = paused
 *   - '.' = stopped
 *   - etc.
 */
class ListItem extends BlockNode
{
    /**
     * @param string|null $taskMarker Raw character from inside brackets, null if not a task
     */
    public function __construct(protected ?string $taskMarker = null)
    {
    }

    /**
     * Get the raw task marker character
     *
     * Returns the character inside the brackets: ' ', '_', 'x', 'X', '-', '/', '>', '?', etc.
     * Returns null if this is not a task list item.
     */
    public function getTaskMarker(): ?string
    {
        return $this->taskMarker;
    }

    /**
     * For task lists: null = not a task, true = checked, false = unchecked
     *
     * Note: This method only recognizes standard markers (' ', '_', 'x', 'X').
     * For extended markers, use getTaskMarker() and handle in render events.
     */
    public function getChecked(): ?bool
    {
        if ($this->taskMarker === null) {
            return null;
        }

        // Standard markers - space and underscore are both unchecked
        if ($this->taskMarker === ' ' || $this->taskMarker === '_') {
            return false;
        }
        if (strtolower($this->taskMarker) === 'x') {
            return true;
        }

        // Extended markers default to unchecked for backward compatibility
        return false;
    }

    /**
     * Check if this is a task list item
     */
    public function isTask(): bool
    {
        return $this->taskMarker !== null;
    }

    /**
     * Check if task is completed (marker is 'x' or 'X')
     */
    public function isCompleted(): bool
    {
        return $this->taskMarker !== null && strtolower($this->taskMarker) === 'x';
    }

    /**
     * The authored state, when it is not the default for the box (PART 11 6g).
     *
     * A checked box records nothing: 'X' folds to 'x', so recording the case
     * would make two spellings of one state two documents.
     *
     * ONLY THE ENUMERATED STATES. This constructor takes any character, and its
     * own docblock names markers the language does not spell ('/', '.', '='), so
     * a hand-built item can hold one. Publishing it would put a value on the
     * wire the schema refuses, and writing it would emit `- [/] x`, which the
     * reader does not read back as a task at all. Such an item keeps the
     * default box it already rendered.
     */
    public function getAuthoredTaskState(): ?string
    {
        if ($this->taskMarker === null || $this->isCompleted()) {
            return null;
        }

        return in_array($this->taskMarker, ['-', '_', '>', '?'], true) ? $this->taskMarker : null;
    }

    public function getType(): string
    {
        return 'list_item';
    }
}
