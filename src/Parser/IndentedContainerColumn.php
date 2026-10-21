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
}
