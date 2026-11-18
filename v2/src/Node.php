<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

/**
 * One node of the AST 2.0 tree (C2-A-021). The type name is the schema type; fields other than
 * `type`, `children` and `pos` live in `$f` under their schema names. Positions are byte offsets
 * into the source; lines and columns are derived when the tree is encoded (C2-A-140).
 */
final class Node
{
    /**
     * @var array<\MarkupCarve\Carve2\Node>|null
     */
    public ?array $children = null;

    /**
     * @var array<string, mixed>
     */
    public array $f = [];

    /**
     * Content line spans of an inline leaf before the inline pass: flat start/end offsets.
     *
     * @var array<int>|null
     */
    public ?array $lines = null;

    public int $start = -1;

    public int $end = -1;

    public int $ownStart = -1;

    public ?string $file = null;

    /**
     * @var array<string>
     */
    public array $includeChain = [];

    public ?self $noteDefinition = null;

    /**
     * C2-X-032: an extension node in the slots of one whose renderer is registered shows its slots too.
     */
    public bool $slotView = false;

    /**
     * C2-X-008: the name after the opener run of a div, as written; null for every other node.
     */
    public ?string $sourceName = null;

    /**
     * The source-layout facts the parser knows when it builds the node (source-layout.md); never encoded in the tree.
     *
     * @var array<string, mixed>
     */
    public array $layout = [];

    /**
     * @var array<string, string>
     */
    public array $includeSources = [];

    /**
     * @param string $type
     * @param array<\MarkupCarve\Carve2\Node>|null $children
     */
    public function __construct(public string $type, ?array $children = null)
    {
        $this->children = $children;
    }

    /**
     * @param string $type
     * @param int $start
     * @param int $end
     *
     * @return self
     */
    public static function at(string $type, int $start, int $end): self
    {
        $node = new self($type);
        $node->start = $start;
        $node->end = $end;

        return $node;
    }

    /**
     * @param string $value
     * @param int $start
     * @param int $end
     *
     * @return self
     */
    public static function text(string $value, int $start, int $end): self
    {
        $node = new self('text');
        $node->f = ['value' => $value];
        $node->start = $start;
        $node->end = $end;

        return $node;
    }
}
