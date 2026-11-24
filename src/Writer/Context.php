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
     * @var string|null The current line of the leaf as the line test read it, where it took a backslash as a block
     *   opener; and the same for the line before (C2-W-088).
     */
    public ?string $openedLine = null;

    public ?string $prevOpenedLine = null;

    /**
     * Open block containers around this text leaf.
     */
    public int $blockDepth = 0;

    public bool $hasFencedAncestor = false;

    public bool $inQuote = false;

    /**
     * The rest of this source line when the next inline span crosses a line break.
     */
    public ?string $lineTail = null;

    /**
     * Source written after a multiline span, through the next line break or unknown inline node.
     */
    public string $lineAfterSpan = '';

    public int $lineAfterSpanPipeSource = -1;

    public int $lineAfterSpanPipeText = -1;

    /**
     * Whether the following text reaches the end of its source line without another inline node.
     */
    public bool $lineAfterSpanEnds = false;

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

    /**
     * Whether a cell keeps a backslash on every backtick: a bare run of its row would pair across a pipe (C2-B-169),
     * or it continues on continuation rows, whose bounds the tick plan of the leaf does not see (C2-B-184).
     */
    public bool $cellWrapped = false;

    /**
     * C2-A-069: the content is one line of a line block, so every line ending in it ends the line.
     */
    public bool $inLineBlock = false;

    /**
     * The node being written is followed by a soft break.
     */
    public bool $beforeSoftBreak = false;

    public bool $beforeHardBreak = false;

    public bool $inBracket = false;

    /**
     * C2-W-099: tree hyphens that the text nodes directly before end with, and whether the next node is text that
     * starts with one, so a run split over text nodes is escaped as one run.
     */
    public int $hyphensBefore = 0;

    public bool $hyphenAfter = false;

    /**
     * The text node is the last of its content, so a hyphen written behind it is the closing mark of its parent.
     */
    public bool $lastInContent = false;

    /**
     * C2-I-114: the dots of the run a text node continues from the nodes before it, and goes on with in the nodes after.
     */
    public int $dotsBefore = 0;

    public int $dotsAfter = 0;

    /**
     * Whether this leaf has a backtick in a neighboring nonverbatim inline node.
     */
    public ?bool $neighborTick = null;

    /**
     * @var array<array{int, int, bool}>|null The backtick runs of this leaf in order: the offset in `$flat` of the
     *   text or node that holds each, its length, and whether it is text.
     */
    public ?array $tickRuns = null;

    /**
     * @var array<mixed>|null The inline nodes of the leaf whose text `$flat` holds, and their path.
     */
    public ?array $leafNodes = null;

    public string $leafPath = '';

    /**
     * @var array<string, array<int, true>>|false|null C2-W-088: the backticks of each text of the leaf that take a
     *   backslash, by path; false where the leaf has no such plan, null until first asked.
     */
    public array|false|null $tickPlan = null;

    /**
     * @var array<string, array<int, int>> The offset in the plan's flat leaf of each backtick of each text, by path.
     */
    public array $tickAt = [];

    /**
     * @var array<string, array<int, true>> The backticks inside a literal attribute block of each text that take a
     *   backslash where the writer escapes the block's brace, by text path.
     */
    public array $tickShielded = [];

    /**
     * @var array<int, true> The backticks of the plan's flat leaf that a line-start escape takes, by offset.
     */
    public array $tickForced = [];

    /**
     * C2-W-088: the backtick rounds escape the reported openers only, for a leaf whose kind rounds lose a node.
     */
    public bool $tickReportedOnly = false;

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
     * @var array{0: array<int>, 1: array<int>, 2: array<string, int>, 3: array<string, int>, 4: array<string, int>, 5: string}|null
     *   The closer facts of the leaf for the brace test, found on first use.
     */
    public ?array $closers = null;

    /**
     * @var array<string, array<int, bool>>|null The marks that form a reader pair in the flat leaf; false where the read-back decides.
     */
    public ?array $partners = null;

    /**
     * @var array<string, array<int, bool>>|null The marks paired within the text currently being escaped inside a node.
     */
    public ?array $localPartners = null;

    /**
     * The content of a node being written, flattened as the leaf is, so its texts pair across their siblings.
     *
     * @var array{flat: string, at: array<string, int>, types: array<int, string>, nodes: array<int, array<mixed>>,
     *   next: int, partners?: array<string, array<int, bool>>}|null
     */
    public ?array $siblings = null;

    /**
     * @var array<string, array<int, true>>|null Brackets paired across the text of the current bracketed content.
     */
    public ?array $bracketPairs = null;

    public string $textPath = '';

    /**
     * @var array<string, array{0: string, 1: bool, 2: bool, 3: bool, 4: int, 5: array<mixed>}> The text children of
     * braced and paired spans, by path: the mark of the span, whether a sibling node stands behind and before the text,
     * whether the span is written braced whatever its escapes, the character behind its bare closer, and the siblings
     * behind the text.
     */
    public array $spanTexts = [];

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
     * C2-W-088: the offsets of the text being written where a block opener behind the marker of the line's block
     * starts; each takes its own escape and gives no kind.
     *
     * @var array<int, true>
     */
    public array $behindMarker = [];

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
     * @var array<\MarkupCarve\Carve\Writer\RunEntry>
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

    /**
     * C2-W-088: the opener kinds whose every potential opener in the leaf takes a backslash.
     *
     * @var array<string, true>
     */
    public array $kinds = [];

    /**
     * Whether the node written before the current text is text too, so a run of its characters goes on.
     */
    public bool $afterText = false;

    /**
     * C2-W-088: the kind of each repair escape of the text being written, by offset.
     *
     * @var array<int, string>
     */
    public array $escapeKinds = [];

    /**
     * Whether the brace just tested takes the backslash of C2-W-125 case one, which gives no kind (C2-W-088).
     */
    public bool $gluedBrace = false;

    /**
     * C2-W-125, case one: the offsets of the text being written whose brace is glued before a node.
     *
     * @var array<int, true>
     */
    public array $gluedBraces = [];
}
