<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * Column decision shared by indented list, description, and footnote bodies.
 *
 * @internal
 */
final class IndentedContainerColumn
{
    public function __construct(
        public readonly int $contentColumn,
        public readonly ?int $authoredFloor = null,
    ) {
    }

    public function reaches(string $line, ?int $authoredColumn = null): bool
    {
        if ($this->authoredFloor !== null && $authoredColumn !== null) {
            return $authoredColumn >= $this->authoredFloor;
        }

        return IndentationHelper::getLeadingColumns($line, $this->contentColumn) >= $this->contentColumn;
    }

    public function strip(string $line): string
    {
        return IndentationHelper::stripLeadingColumns($line, $this->contentColumn);
    }

    /**
     * Check whether a continuation marker belongs to this container kind.
     *
     * @param array{owner: ?array<string, mixed>} $decision
     * @param string $kind
     */
    public function ownsContinuation(array $decision, string $kind): bool
    {
        return ($decision['owner']['kind'] ?? null) === $kind;
    }

    /**
     * Classify an admitted line against the container content column.
     *
     * @param string $line
     * @param int|null $authoredColumn
     * @param array{reason: ?string, owner: ?array<string, mixed>}|null $decision
     * @param string|null $kind
     *
     * @return 'blank'|'content'|'owned-continuation'|'outside'
     */
    public function classify(string $line, ?int $authoredColumn = null, ?array $decision = null, ?string $kind = null): string
    {
        if (IndentationHelper::isBlankLine($line)) {
            return 'blank';
        }

        if ($this->reaches($line, $authoredColumn)) {
            return 'content';
        }

        if ($decision !== null && $kind !== null && $this->ownsContinuation($decision, $kind)) {
            return 'owned-continuation';
        }

        return 'outside';
    }
}
