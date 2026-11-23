<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

use MarkupCarve\Carve\Attrs;

/**
 * An open block on the block pass stack (C2-B-001): what its opener set.
 */
final class Frame
{
    /**
     * @var int
     */
    public const DOCUMENT = 0;

    /**
     * @var int
     */
    public const QUOTE = 1;

    /**
     * @var int
     */
    public const LIST = 2;

    /**
     * @var int
     */
    public const ITEM = 3;

    /**
     * @var int
     */
    public const DLIST = 4;

    /**
     * @var int
     */
    public const DESC = 5;

    /**
     * @var int
     */
    public const FOOTNOTE = 6;

    /**
     * @var int
     */
    public const DIV = 7;

    /**
     * @var int
     */
    public const PARAGRAPH = 10;

    /**
     * @var int
     */
    public const HEADING = 11;

    /**
     * @var int
     */
    public const CODE = 12;

    /**
     * @var int
     */
    public const TABLE = 13;

    /**
     * @var int
     */
    public const TERM = 14;

    /**
     * @var int
     */
    public const CAPTION = 15;

    /**
     * @var int
     */
    public const COMMENT_BLOCK = 16;

    /**
     * @var int
     */
    public const LINE_BLOCK = 17;

    /**
     * @var int
     */
    public const FRONTMATTER = 18;

    /**
     * @var int
     */
    public const THEMATIC = 19;

    /**
     * @var int
     */
    public const LINK_DEF = 20;

    /**
     * @var int
     */
    public const ABBR_DEF = 21;

    /**
     * @var int
     */
    public const INCLUDE = 22;

    /**
     * @var int
     */
    public const COMMENT_LINE = 23;

    /**
     * @var int
     */
    public const ATTR_PARAGRAPH = 24;

    public int $markerCol = 0;

    public int $contentCol = 0;

    public int $end = -1;

    /**
     * Line spans of a text leaf or an opaque body: flat start/end byte offsets.
     *
     * @var array<int>
     */
    public array $spans = [];

    /**
     * @var array<\MarkupCarve\Carve\Block\Frame>
     */
    public array $hiddenComments = [];

    public ?Frame $lineComment = null;

    /**
     * @var array<int, array<array{0: int, 1: int, 2: string}>>
     */
    public array $lazyLineComments = [];

    public string $key = '';

    public bool $loose = false;

    public int $items = 0;

    public int $level = 0;

    public int $indent = 0;

    /**
     * C2-B-003: the opener column, counted behind the innermost quote mark of its line.
     */
    public int $openCol = 0;

    public ?Attrs $attrs = null;

    public ?Marker $marker = null;

    public ?string $task = null;

    public string $node = '';

    public string $divKind = '';

    public string $nameClass = '';

    public string $sigilClass = '';

    /**
     * The quoted title of a fence or div: start and end offsets of its text.
     *
     * @var array{0: int, 1: int}|null
     */
    public ?array $title = null;

    public ?string $label = null;

    public string $syntax = '';

    public ?string $raw = null;

    public string $delimited = '';

    public bool $closed = false;

    /**
     * The parse findings of the block (C2-B-105, C2-B-122, C2-B-181): the code and the offset its message starts from.
     *
     * @var array<array{0: string, 1: int}>
     */
    public array $findings = [];

    public ?string $role = null;

    public int $own = -1;

    public ?Frame $target = null;

    public string $value = '';

    public string $href = '';

    public ?string $tooltip = null;

    /**
     * Include directive fields (C2-A-181).
     *
     * @var array<string, mixed>
     */
    public array $include = [];

    /**
     * Rows and comments of a pipe table.
     *
     * @var array<\MarkupCarve\Carve\Block\TableItem>
     */
    public array $rows = [];

    public int $n = 0;

    public int $depth = 0;

    public mixed $handle = null;

    /**
     * @param int $kind
     * @param int $start
     */
    public function __construct(public int $kind, public int $start)
    {
    }
}
