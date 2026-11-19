<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

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

    /**
     * C2-W-092: what holds the paragraph, read from its prefix: `top`, `quote`, or `body` for an item, a description or a
     * footnote, where a list marker interrupts on every line.
     */
    public string $owner = 'top';

    /**
     * C2-W-121: the paragraph follows a description list, so a description mark at its start would join the list.
     */
    public bool $afterDescriptions = false;

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

    /**
     * The marks of the braced, emphasis and strong nodes open around the content since the last bracket (C2-I-055).
     */
    public string $marks = '';

    /**
     * The text of the leaf's own text nodes, each other node one `\0`, each break a line feed; null until the leaf
     * is first written. C2-W-094, C2-W-096: the bracket and brace tests read what follows a character in it.
     */
    public ?string $flat = null;

    /**
     * @var array<string, int> The offset in `$flat` of each text node of the leaf's own list, by path.
     */
    public array $flatAt = [];

    /**
     * @var array<int, string> The type of the node that each `\0` of `$flat` stands for, by offset.
     */
    public array $flatTypes = [];

    /**
     * @var array<int, int> The first code point of the spelling of the node that each `\0` of `$flat` stands for.
     */
    public array $flatFirst = [];

    /**
     * @var array<int, string> The whole spelling of each `\0` that stands for an atom, by offset.
     */
    public array $flatAtoms = [];

    /**
     * @var array<int, bool> Whether the spelling of each `\0` may hold a closing brace, by offset.
     */
    public array $flatBraces = [];

    /**
     * @var array<int, array<mixed>> The node of each `\0` that is no atom, by offset.
     */
    public array $flatNodes = [];

    /**
     * @var array{0: array<int>, 1: array<int>, 2: array<string, int>, 3: array<string, int>}|null The closer facts of
     *   the leaf for the brace test, found on first use.
     */
    public ?array $closers = null;

    /**
     * @var array<string, array{0: int, 1: int}>|null The first offset in `$flat` that may open each of `*` and `/` and
     *   the last that may close it, for the pairing test; found on first use.
     */
    public ?array $partners = null;

    /**
     * The offset in `$flat` of the text being escaped, -1 for a text inside another node.
     */
    public int $here = -1;

    /**
     * @var array<int, bool> Whether each character of the text being escaped took a backslash, so far.
     */
    public array $escapedAt = [];

    /**
     * @var array<int, bool>|null Whether each opening bracket of `$flat` takes a backslash, by offset.
     */
    public ?array $brackets = null;

    /**
     * @var array<string, true> The keys of the document's link definitions (C2-W-094).
     */
    public array $labels = [];
}
