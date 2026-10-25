<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * List item
 *
 * For task lists, stores the authored marker character:
 * - ' ' and '_' are open; '_' retains its authored spelling.
 * - 'x' and 'X' are done.
 * - '/' is progress, '!' is blocked, '-' is dropped, '>' is deferred,
 *   and '?' is uncertain. Only done is checked.
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
     * Returns the character inside the brackets: ' ', '_', 'x', 'X', '-', '/', '!', '>', or '?'.
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
     * Only PART 2's states: this constructor takes any character, and a marker
     * the language does not spell would write `- [z] x`, which reads back as a
     * paragraph.
     */
    public function getAuthoredTaskState(): ?string
    {
        if ($this->taskMarker === null || $this->isCompleted()) {
            return null;
        }

        return in_array($this->taskMarker, ['-', '_', '/', '!', '>', '?'], true) ? $this->taskMarker : null;
    }

    public function getType(): string
    {
        return 'list_item';
    }
}
