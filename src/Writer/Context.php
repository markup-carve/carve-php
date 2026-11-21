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
     * The rest of this source line when the next inline span crosses a line break.
     */
    public ?string $lineTail = null;

    /**
     * Text written after a multiline span, through the next line break or non-text node.
     */
    public string $lineAfterSpan = '';

    /**
     * Offset of the pipe in following text that must be escaped to keep the span's final line out of a table.
     */
    public int $escapeFollowingPipeAt = -1;

    /**
     * A line comment immediately precedes the current continuation line.
     */
    public bool $lineCommentBefore = false;

    /**
     * C2-W-092: what holds the paragraph: `top`, `quote`, `body` for an item or footnote, or `description` for a
     * description body. List markers interrupt every body line; description marks also interrupt description lines.
     */
    public string $owner = 'top';

    /**
     * C2-W-121: the paragraph follows a description list, so a description mark at its start would join the list.
     */
    public bool $afterDescriptions = false;

    /**
     * C2-B-046: the content starts directly behind the marker of an item without a task.
     */
    public bool $boxLead = false;

    public bool $inCell = false;

    public bool $inBracket = false;

    /**
     * Whether this leaf has a backtick in a neighboring nonverbatim inline node.
     */
    public ?bool $neighborTick = null;

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
     * @var array<string, array<int, true>>|null The marks that form a reader pair in the flat leaf.
     */
    public ?array $partners = null;

    /**
     * @var array<string, array<int, true>>|null The marks paired within the text currently being escaped inside a node.
     */
    public ?array $localPartners = null;

    /**
     * @var array<string, array<int, true>>|null Brackets paired across the text of the current bracketed content.
     */
    public ?array $bracketPairs = null;

    public string $textPath = '';

    public ?State $writerState = null;

    /**
     * The offset in `$flat` of the text being escaped, -1 for a text inside another node.
     */
    public int $here = -1;

    /**
     * @var array<int, bool> Whether each character of the text being escaped took a backslash, so far.
     */
    public array $escapedAt = [];

    /**
     * C2-W-146: the offsets of the text being written that take a backslash because a pair rule of lint reports them.
     *
     * @var array<int, bool>
     */
    public array $pairEscapes = [];

    /**
     * C2-W-146: the offsets the pair rules ask for across the text nodes of a run, by the path of the text node.
     *
     * @var array<string, array<int, bool>>
     */
    public array $runPairEscapes = [];

    /**
     * C2-W-146: each text node and soft break of the content as written, in order, for the pair test of a run.
     *
     * @var array<array{path: string, out: string, prefix: int, escaped: array<int, bool>, value: string}>
     */
    public array $runLog = [];

    /**
     * @var array<int, bool>|null Whether each opening bracket of `$flat` takes a backslash, by offset.
     */
    public ?array $brackets = null;

    /**
     * @var array<string, true> The keys of the document's link definitions (C2-W-094).
     */
    public array $labels = [];
}
