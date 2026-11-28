<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * The trees of the repair parses a writer keeps (carve-js `ParseCache` in write-check.ts). `trees` and `recent` decide
 * what a parse is charged (C2-W-133); `kept` only spares a parse and is shared with a cache made for a trial test.
 */
final class ParseCache
{
    /**
     * @var int The passes of one repair write the sources of earlier passes again; the trees kept for them are bounded
     *     by count and by characters, past the last two.
     */
    private const RECENT_TREES = 16;

    /**
     * @var int
     */
    private const RECENT_CHARS = 2_000_000;

    /**
     * @var int The characters of the sources whose trees the cache keeps on first sight; the blocks it reuses are short.
     */
    private const MAX_TREE_CHARS = 16_384;

    /**
     * @var array<string, \MarkupCarve\Carve\Writer\Check\Node|null> By source: its tree, or null where it was parsed
     *     once and not kept.
     */
    public array $trees = [];

    public int $treeChars = 0;

    /**
     * @var list<array{string, \MarkupCarve\Carve\Writer\Check\Node}>
     */
    public array $recent = [];

    public KeptTrees $kept;

    public function __construct(?KeptTrees $kept = null)
    {
        $this->kept = $kept ?? new KeptTrees();
    }

    /**
     * A repeated source keeps its tree from its second parse on, so each source is parsed uncounted at most once, which
     * is no longer than its counted first parse (carve-js `remember`).
     */
    public function remember(string $source, Node $parsed, bool $repeated): void
    {
        if (!$repeated && count($this->trees) >= 128) {
            $this->trees = [];
            $this->treeChars = 0;
        }
        $kept = $repeated || $this->treeChars + strlen($source) <= self::MAX_TREE_CHARS;
        if ($kept) {
            $this->treeChars += strlen($source);
        }
        $this->trees[$source] = $kept ? $parsed : null;
    }

    public function rememberRecent(string $source, Node $parsed): void
    {
        array_unshift($this->recent, [$source, $parsed]);
        $chars = 0;
        $keep = 0;
        foreach ($this->recent as [$known]) {
            $chars += strlen($known);
            if ($keep >= self::RECENT_TREES || ($keep >= 2 && $chars > self::RECENT_CHARS)) {
                break;
            }
            $keep++;
        }
        $this->recent = array_slice($this->recent, 0, $keep);
    }

    public function recentTree(string $source): ?Node
    {
        foreach ($this->recent as [$known, $tree]) {
            if ($known === $source) {
                return $tree;
            }
        }

        return null;
    }
}
