<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\Attrs;

/**
 * An open block on the block pass stack (C2-B-001): what its opener set.
 */
final class Frame
{
    public const DOCUMENT = 0;
    public const QUOTE = 1;
    public const LIST = 2;
    public const ITEM = 3;
    public const DLIST = 4;
    public const DESC = 5;
    public const FOOTNOTE = 6;
    public const DIV = 7;
    public const PARAGRAPH = 10;
    public const HEADING = 11;
    public const CODE = 12;
    public const TABLE = 13;
    public const TERM = 14;
    public const CAPTION = 15;
    public const COMMENT_BLOCK = 16;
    public const LINE_BLOCK = 17;
    public const FRONTMATTER = 18;
    public const THEMATIC = 19;
    public const LINK_DEF = 20;
    public const ABBR_DEF = 21;
    public const INCLUDE = 22;
    public const COMMENT_LINE = 23;
    public const ATTR_PARAGRAPH = 24;

    /** Relative column of the marker (item, description, footnote, heading) or of the list. */
    public int $markerCol = 0;

    /** Relative column of the first content character on the marker line. */
    public int $contentCol = 0;

    public int $end = -1;

    /**
     * Line spans of a text leaf, or rows of a table: flat start/end byte offsets.
     *
     * @var array<int>
     */
    public array $spans = [];

    /** List kind key: bullet char, or style and delimiter. */
    public string $key = '';

    public bool $loose = false;

    public int $items = 0;

    /** Fence: character and run length; heading: level. */
    public string $fenceChar = '';

    public int $level = 0;

    /** Fence: indent of the opener, stripped from the body (C2-B-029). */
    public int $indent = 0;

    public ?Attrs $attrs = null;

    /**
     * Opener payload: marker, task, info string parts, table rows and so on.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    /** Sink handle: a node in the tree shape, an event index in the event shape. */
    public mixed $handle = null;

    /** Trailing blank lines held back at the end of an opaque body (C2-B-113). */
    public int $heldBlank = 0;

    /**
     * @param int $kind
     * @param int $start
     */
    public function __construct(public int $kind, public int $start)
    {
    }
}
