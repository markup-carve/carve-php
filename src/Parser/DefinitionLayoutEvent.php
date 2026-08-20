<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * A definition-shaped line after the shared opacity/container walk.
 *
 * This is deliberately not yet an activation decision. The three definition
 * grammars apply their kind-specific scope rules to this immutable layout
 * fact, which keeps "the block reader consumes it" separate from "it defines
 * globally".
 */
final readonly class DefinitionLayoutEvent
{
    /**
     * @var string
     */
    public const REFERENCE = 'reference';

    /**
     * @var string
     */
    public const FOOTNOTE = 'footnote';

    /**
     * @var string
     */
    public const ABBREVIATION = 'abbreviation';

    public function __construct(
        public string $kind,
        public int $line,
        public int $contentColumn,
        public int $reachedColumn,
        public string $subject,
        public bool $inQuote,
        public bool $inList,
    ) {
    }
}
