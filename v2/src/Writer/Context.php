<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Writer;

/**
 * Where inline content is being written: the last character written, whether the next one starts a line of a block
 * that tests its lines for openers (C2-W-092), and the host it stands in.
 */
final class Context
{
    /**
     * Last code point written, -1 at the start of the content, 10 after a line break.
     */
    public int $last = -1;

    public bool $lineStart = false;

    public bool $inCell = false;

    public bool $inBracket = false;

    /**
     * C2-W-080: a hard break is the bare line ending.
     */
    public bool $bareBreaks = false;

    /**
     * Whether line starts of this content are tested for block openers at all.
     */
    public bool $blockLines = true;

    /**
     * @var array<string, int> C2-W-093: the `/` and `*` of the whole leaf, text and markup.
     */
    public array $counts = [];

    /**
     * @var array<string, int> The bare kinds open around the content: `emphasis`, `strong`.
     */
    public array $open = [];
}
