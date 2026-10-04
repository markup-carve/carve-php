<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\ContentNodeInterface;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * Builds line blocks and maps verse comments.
 *
 * @internal
 */
final class LineBlockBuilder
{
    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \MarkupCarve\Carve\Parser\BlockSourceMapper $source
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\FencedBlockParser $getFencedBlockParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\InlineParser $getInlineParser
     * @param \Closure(\MarkupCarve\Carve\Node\Node): (void) $applyPendingAttributesCallback
     * @param \Closure(array<string>, int, int, bool): array{lines: list<string>, lineMap: array<int, int>, consumed: int, closed: bool, lineMapBase?: int} $collectColonFenceBodyCallback
     * @param \Closure(): (string) $positionSourceCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Block\LineBlock, list<array{0: string, 1: int}>): (void))|null $appendLineBlockStanzaCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Block\Paragraph, list<array{0: int, 1: int}>): (void))|null $convertParagraphSoftBreaksToHardBreaksCallback
     * @param (\Closure(string, int): (array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>, 2: int}))|null $expandLineBlockLineCallback
     * @param (\Closure(string): (array{length: int, attrs: string|null}|null))|null $parseLineBlockOpenerCallback
     */
    public function __construct(
        private BlockParserState $state,
        private BlockSourceMapper $source,
        private Closure $getFencedBlockParser,
        private Closure $getInlineParser,
        private Closure $applyPendingAttributesCallback,
        private Closure $positionSourceCallback,
        private Closure $collectColonFenceBodyCallback,
        private ?Closure $appendLineBlockStanzaCallback = null,
        private ?Closure $convertParagraphSoftBreaksToHardBreaksCallback = null,
        private ?Closure $expandLineBlockLineCallback = null,
        private ?Closure $parseLineBlockOpenerCallback = null,
    ) {
    }

    /**
     * Split lines into blocks separated by blank lines
     *
     * @param array<string> $lines
     *
     * @return array<array<string>>
     */
    public function splitByBlankLines(array $lines): array
    {
        $blocks = [];
        $current = [];

        // Skip leading blank lines using index (avoid O(n) array_shift)
        $start = 0;
        $count = count($lines);
        while ($start < $count && IndentationHelper::isBlankLine($lines[$start])) {
            $start++;
        }

        for ($i = $start; $i < $count; $i++) {
            $line = $lines[$i];
            if (IndentationHelper::isBlankLine($line)) {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }
            } else {
                $current[] = $line;
            }
        }

        // Don't forget the last block
        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Whether a line opens a LINE BLOCK, and with which fence length.
     *
     * A bare pipe `|` is the line-block type token (carve spec, jgm/djot#29);
     * `::: |` is the only line-block opener, so an ordinary `::: note` div is
     * not one. Shared by the parser and by the footnote-definition pre-pass,
     * which has to skip a line block's body: two copies of this predicate would
     * drift, and the pre-pass having no copy at all is what made a definition
     * written inside a line block register a footnote (carve-php#685).
     *
     * @param string $line
     *
     * @return array{length: int, attrs: string|null}|null
     */
    public function parseLineBlockOpener(string $line): ?array
    {
        $divInfo = ($this->getFencedBlockParser)()->parseDivFenceOpener($line);
        if ($divInfo === null) {
            return null;
        }

        if (
            preg_match(
                '/^\|(?:[ \t]*(?<attrs>\{.*\}))?[ \t]*$/s',
                $divInfo['className'],
                $openerMatches,
                PREG_UNMATCHED_AS_NULL,
            ) !== 1
        ) {
            return null;
        }

        /** @var int $length */
        $length = $divInfo['length'];

        return ['length' => $length, 'attrs' => $openerMatches['attrs'] ?? null];
    }

    /**
     * Try to parse a line block (preserves author line layout).
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    public function tryParseLineBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        $divInfo = $this->callParseLineBlockOpener($line);
        if ($divInfo === null) {
            return null;
        }

        $body = ($this->collectColonFenceBodyCallback)($lines, $start, $divInfo['length'], false);
        $contentLines = $body['lines'];

        $lineBlock = new LineBlock();
        $this->applyPendingAttributes($lineBlock);
        if ($divInfo['attrs'] !== null) {
            AttributeParser::applyToNode($lineBlock, substr($divInfo['attrs'], 1, -1));
        }

        $stanza = [];
        $lineNumber = $start + 1;
        foreach ($contentLines as $contentLine) {
            $contentLine = BlockGrammar::stripLazyFrame($contentLine);
            if (IndentationHelper::isBlankLine($contentLine)) {
                $this->callAppendLineBlockStanza($lineBlock, $stanza);
                $stanza = [];
            } else {
                $stanza[] = [$contentLine, $lineNumber];
            }

            $lineNumber++;
        }
        $this->callAppendLineBlockStanza($lineBlock, $stanza);

        $parent->appendChild($lineBlock);

        return $body['consumed'];
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\LineBlock $lineBlock
     * @param list<array{0: string, 1: int}> $lines
     */
    public function appendLineBlockStanza(LineBlock $lineBlock, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $paragraph = new Paragraph();
        $lastIndex = count($lines) - 1;

        $lastLine = $lines[$lastIndex][0];
        $lastSourceLine = $this->sourceLineFor($lines[$lastIndex][1]);
        [, , $keptOnLastLine] = $this->callExpandLineBlockLine($lastLine, $lines[$lastIndex][1]);
        $authoredLength = strlen($this->state->source->sourceLines[$lastSourceLine] ?? $lastLine);
        $keptOnLastLine -= max(0, strlen($lastLine) - $authoredLength);
        $this->stampBlockSpan(
            $paragraph,
            $this->sourceLineFor($lines[0][1]),
            $this->sourceLineFor($lines[$lastIndex][1]),
            $keptOnLastLine,
        );

        $verseCommentSources = [];
        $verseComments = $this->verseCommentLines($lines, $verseCommentSources);

        $texts = [];
        $segments = [];
        $endingSegments = [];
        $lineEndings = [];
        $offsetInStanza = 0;
        foreach ($lines as $index => [$line, $lineNumber]) {
            [$expanded, $runs, $kept] = $this->callExpandLineBlockLine($line, $lineNumber);
            if (isset($verseComments[$index])) {
                $expanded = '';
                $runs = [];
            }
            foreach ($runs as [$offsetInLine, $sourceColumn, $length, $sourceLength]) {
                $segments[] = [$offsetInStanza + $offsetInLine, $sourceColumn, $length, $lineNumber, false, $sourceLength];
            }
            $texts[] = $expanded;
            if ($index < $lastIndex) {
                // THE JOINED NEWLINE NEEDS A SEGMENT OF ITS OWN, so a break can
                // be resolved at all: no literal run reaches it, because a
                // preserved trailing gap or a dropped one-column run can sit
                // between the last mapped byte and the line ending.
                //
                // It is enough to IDENTIFY the break, not to place it - see the
                // promotion below for why the two are different here.
                $lineEndings[] = [
                    $offsetInStanza + strlen($expanded),
                    $lineNumber,
                ];
                // A FALLBACK SEGMENT, because lookup takes the FIRST segment
                // covering an offset and this one deliberately overlaps its
                // neighbours at both ends. A line ending's offset is also the
                // exclusive end of the text before it, and its end is also the
                // first offset of the line after it; the run segments own both
                // of those readings, so this one must only answer where no run
                // does - which is exactly the case it exists for, a line whose
                // ending no literal run reaches. Keeping it out of the primary
                // list is also what leaves that list TILING, and so searchable
                // rather than scanned.
                $endingSegments[] = [
                    $offsetInStanza + strlen($expanded),
                    strlen($this->state->source->sourceLines[$this->sourceLineFor($lineNumber)] ?? $line),
                    1,
                    $lineNumber,
                    true,
                    1,
                ];
            }
            // +1 for the "\n" the join inserts after this line.
            $offsetInStanza += strlen($expanded) + 1;
        }

        ($this->getInlineParser)()->parse(
            $paragraph,
            implode("\n", $texts),
            $lines[0][1],
            sourceMap: $this->lineBlockMap(array_merge($segments, $endingSegments)),
            lineBlock: true,
        );
        $this->callConvertParagraphSoftBreaksToHardBreaks($paragraph, $lineEndings);
        $this->placeVerseComments($paragraph, $verseComments, $verseCommentSources);

        $lineBlock->appendChild($paragraph);
    }

    /**
     * The stanza's comment-only body lines, as `comment` nodes keyed by their
     * index in the stanza.
     *
     * @param list<array{0: string, 1: int}> $lines
     * @param array<int, string> $sources Set to each comment's AUTHORED line.
     *
     * @return array<int, \MarkupCarve\Carve\Node\Block\Comment>
     */
    public function verseCommentLines(array $lines, array &$sources = []): array
    {
        $comments = [];
        $sources = [];
        foreach ($lines as $index => [$line, $lineNumber]) {
            if (!str_starts_with($line, '%%')) {
                continue;
            }

            // The same content the inline reader takes: everything after the
            // marker, less exactly one separating space or tab. Any further
            // spacing is the comment's own.
            $content = substr($line, 2);
            if ($content !== '' && ($content[0] === ' ' || $content[0] === "\t")) {
                $content = substr($content, 1);
            }
            $comment = new Comment(rtrim($content, " \t"));
            // The node keeps the SPAN the inline reader used to give it: its
            // own line, from the container's content column to the end. A node
            // that loses its position when the layer deciding it moves is a
            // silent PART 12 §4 regression - the surrounding text and breaks
            // still carry theirs, so nothing else would have shown it.
            $sourceLine = $this->sourceLineFor($lineNumber);
            $this->stampBlockSpan($comment, $sourceLine, $sourceLine);
            $comments[$index] = $comment;
            // The line AS AUTHORED, kept so a reference's stored source can be
            // repaired with the bytes the author wrote rather than with a form
            // rebuilt from the content.
            $sources[$index] = $line;
        }

        return $comments;
    }

    /**
     * Put each removed comment back into the stanza, in document order.
     *
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     * @param array<int, \MarkupCarve\Carve\Node\Block\Comment> $comments
     * @param array<int, string> $sources Each comment's AUTHORED line, by the same index.
     */
    public function placeVerseComments(Paragraph $paragraph, array $comments, array $sources = []): void
    {
        if ($comments === []) {
            return;
        }

        ksort($comments);
        // A SORTED LIST WITH A CURSOR, not an array consumed by key. Both
        // consumers take the lowest pending index, so a cursor answers in
        // constant time where a lookup has to walk: unsetting from the front of
        // a PHP array leaves tombstones that `array_key_first()` re-skips on
        // every call, which turned a stanza alternating runs with comment lines
        // quadratic - a regression on an input this fix has no other effect on.
        $pending = [];
        foreach ($comments as $index => $comment) {
            $pending[] = [$index, $comment, $sources[$index] ?? ''];
        }

        $cursor = 0;
        $line = 0;
        // A comment on the stanza's FIRST line needs no boundary at all - the
        // stanza opens it - and that opening is the PARAGRAPH's, so it is drawn
        // here rather than inside whatever container the first line begins.
        $this->placeVerseCommentsIn($paragraph, $pending, $cursor, $line, true, []);
    }

    /**
     * Walk one node's children in document order, placing comments by line.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param list<array{0: int, 1: \MarkupCarve\Carve\Node\Block\Comment, 2: string}> $pending Line index, node and authored line, ascending.
     * @param int $cursor The first entry of `$pending` neither placed nor dropped.
     * @param int $line Boundaries seen so far, carried across the whole stanza.
     * @param bool $atStanzaStart Whether this call opens the stanza itself.
     * @param array<\MarkupCarve\Carve\Node\Node> $rawReferenceHosts Every enclosing reference, outermost first.
     */
    public function placeVerseCommentsIn(
        Node $parent,
        array &$pending,
        int &$cursor,
        int &$line,
        bool $atStanzaStart,
        array $rawReferenceHosts,
    ): void {
        $placed = [];
        $inserted = false;
        if ($atStanzaStart) {
            $inserted = $this->takeVerseCommentAt($placed, $pending, $cursor, $line, $rawReferenceHosts);
        }

        foreach ($parent->getChildren() as $child) {
            // NOTHING LEFT TO PLACE ends the walk of this node. The boundary
            // count only matters while a comment is pending, and a node this
            // pass does not touch must not have its child list rebuilt.
            if (!isset($pending[$cursor])) {
                if (!$inserted) {
                    return;
                }
                $placed[] = $child;

                continue;
            }

            if ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $placed[] = $child;
                $line++;
                $inserted = $this->takeVerseCommentAt($placed, $pending, $cursor, $line, $rawReferenceHosts) || $inserted;

                continue;
            }

            $placed[] = $child;
            if ($child->hasChildren()) {
                // EVERY ENCLOSING REFERENCE, carried down rather than
                // searched for. A comment inside `[a /b` / `%% c` / `d/][r]`
                // sits under the emphasis while the snapshot that has to hear
                // about it is the LINK's - and a reference nested in another
                // reference's LABEL, `[x [y` / `%% c` / `z][inner] w][outer]`,
                // gives two snapshots that both contain the emptied line.
                // Repairing only the nearest left the outer one stale, and the
                // writer emits the outer as a whole.
                $host = $this->referenceSnapshotHost($child);
                $this->placeVerseCommentsIn(
                    $child,
                    $pending,
                    $cursor,
                    $line,
                    false,
                    $host === null ? $rawReferenceHosts : [...$rawReferenceHosts, $host],
                );

                continue;
            }

            // A verbatim run holds the boundaries it swallowed inside its own
            // content, where they are newlines rather than nodes.
            $swallowed = $child instanceof ContentNodeInterface
                ? substr_count($child->getContent(), "\n")
                : 0;
            if ($swallowed === 0) {
                continue;
            }
            $line += $swallowed;
            $this->dropVerseCommentsThrough($child, $pending, $cursor, $line);
        }

        if ($inserted) {
            $parent->setChildren($placed);
        }
    }

    /**
     * Take the comment opened by the boundary just passed, if there is one.
     *
     * @param array<int, \MarkupCarve\Carve\Node\Node> $placed
     * @param list<array{0: int, 1: \MarkupCarve\Carve\Node\Block\Comment, 2: string}> $pending
     * @param int $cursor
     * @param int $line The stanza line the boundary opens.
     * @param array<\MarkupCarve\Carve\Node\Node> $rawReferenceHosts Every enclosing reference.
     */
    public function takeVerseCommentAt(
        array &$placed,
        array &$pending,
        int &$cursor,
        int $line,
        array $rawReferenceHosts,
    ): bool {
        if (!isset($pending[$cursor]) || $pending[$cursor][0] !== $line) {
            return false;
        }

        $placed[] = $pending[$cursor][1];
        foreach ($rawReferenceHosts as $host) {
            $this->restoreCommentInReferenceSnapshot($host, $pending[$cursor][2]);
        }
        $cursor++;

        return true;
    }

    /**
     * This node's stored reference source, if it is the kind that keeps one.
     */
    public function referenceSnapshotHost(Node $node): ?Node
    {
        if (!$node instanceof Link && !$node instanceof Image) {
            return null;
        }

        return $node->getRawReferenceLabel() === null ? null : $node;
    }

    /**
     * Put a comment's authored LINE back into a reference's stored source.
     */
    public function restoreCommentInReferenceSnapshot(Node $host, string $authoredLine): void
    {
        if (!$host instanceof Link && !$host instanceof Image) {
            return;
        }

        $raw = $host->getRawReferenceLabel();
        if ($raw === null || $authoredLine === '') {
            return;
        }

        $lines = explode("\n", $raw);
        foreach ($lines as $index => $lineText) {
            if ($lineText !== '') {
                continue;
            }

            $lines[$index] = $authoredLine;
            $host->setRawReferenceLabel(implode("\n", $lines));

            return;
        }
    }

    /**
     * Drop every comment a run's swallowed newlines carried away.
     *
     * @param \MarkupCarve\Carve\Node\Node $run
     * @param list<array{0: int, 1: \MarkupCarve\Carve\Node\Block\Comment, 2: string}> $pending
     * @param int $cursor
     * @param int $line The stanza line the run's content reaches.
     */
    public function dropVerseCommentsThrough(Node $run, array &$pending, int &$cursor, int $line): void
    {
        while (isset($pending[$cursor]) && $pending[$cursor][0] <= $line) {
            $cursor++;
        }
    }

    /**
     * A stanza's source map: one segment per run of the expansion a segment can
     * describe.
     *
     * A preserved run of PLAIN SPACES is one of them, through a shape that
     * records both lengths. Each placeholder stands for one source column, but
     * U+E000 is three bytes in UTF-8 where the space it replaced is one, so an
     * ordinary segment - which maps N source bytes onto N built bytes - cannot
     * describe it, and the whole region used to be left out. Everything over it
     * then went unplaced where other engines place it (carve-php#1351).
     *
     * A preserved run holding a TAB still is skipped. A tab widens to between
     * one and four placeholders depending on the column it starts at, so no
     * fixed count of source bytes stands behind its sentinels, and a node over
     * it gets no position - which PART 12 §4 rates well above a wrong one.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int, 4: bool, 5: int}> $segments
     *   Text offset, source column, byte length in the built string, line
     *   number, whether the segment answers only where no other one does, and
     *   byte length in the source - which differs from the built length exactly
     *   for a rewritten run.
     *
     * @return \MarkupCarve\Carve\Parser\SourceMap|null
     */
    public function lineBlockMap(array $segments): ?SourceMap
    {
        if (!$this->state->source->trackPositions || $segments === []) {
            return null;
        }

        $map = new SourceMap();
        $any = false;
        foreach ($segments as [$textOffset, $sourceColumn, $length, $lineNumber, $fallback, $sourceLength]) {
            $sourceLine = $this->sourceLineFor($lineNumber);
            $lineStart = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
            if ($lineStart === null || $length <= 0) {
                continue;
            }
            // THE COLUMN IS MEASURED AGAINST THE LINE THE STANZA WAS HANDED,
            // which a container has already stripped its prefix from. Mapping
            // it straight from the physical line start put every span inside a
            // quoted or listed line block short by the prefix width, the check
            // that a span selects the node's own text then failed, and the
            // nodes lost their positions - visibly, and only when nested.
            $prefix = $this->state->frame->currentContentColumns[$sourceLine] ?? 0;
            if ($fallback) {
                $map->addFallback(
                    $textOffset,
                    $lineStart + $prefix + $sourceColumn,
                    $length,
                    $sourceLine + 1,
                    $prefix + $sourceColumn + 1,
                );
            } elseif ($sourceLength !== $length) {
                $map->addSentinelRun(
                    $textOffset,
                    $lineStart + $prefix + $sourceColumn,
                    $sourceLength,
                    $sourceLine + 1,
                    $prefix + $sourceColumn + 1,
                );
            } else {
                $map->add(
                    $textOffset,
                    $lineStart + $prefix + $sourceColumn,
                    $length,
                    $sourceLine + 1,
                    $prefix + $sourceColumn + 1,
                );
            }
            $any = true;
        }

        return $any ? $map->withSource($this->positionSource(), $this->state->source->positionIndex) : null;
    }

    /**
     * Promote a stanza's soft breaks to hard ones, AT EVERY DEPTH.
     *
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     * @param list<array{0: int, 1: int}> $lineEndings Text offset and line number, ascending.
     */
    public function convertParagraphSoftBreaksToHardBreaks(Paragraph $paragraph, array $lineEndings = []): void
    {
        $next = 0;
        $this->hardenSoftBreaksIn($paragraph, $lineEndings, $next);
    }

    /**
     * Walk one node's children in document order, hardening the breaks.
     *
     * The line-ending cursor is carried ACROSS the whole stanza rather than per
     * node, because the breaks and the line endings are both in document order
     * and this walk visits them in it - a descent that restarted the cursor at
     * each container would hand the second container the first one's spans.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param list<array{0: int, 1: int}> $lineEndings Text offset and line number, ascending.
     * @param int $next The first line ending no break has claimed.
     */
    public function hardenSoftBreaksIn(Node $parent, array $lineEndings, int &$next): void
    {
        $count = count($lineEndings);
        foreach ($parent->getChildren() as $index => $inline) {
            if ($inline instanceof ContentNodeInterface && method_exists($inline, 'setContent')) {
                $inline->setContent(str_replace("\0", "\u{00A0}", $inline->getContent()));
            }
            if (!$inline instanceof SoftBreak && !$inline instanceof HardBreak) {
                if ($inline->hasChildren()) {
                    $this->hardenSoftBreaksIn($inline, $lineEndings, $next);
                }

                continue;
            }

            $pos = $inline->getPos();
            $span = $pos;
            if ($pos !== null) {
                while ($next < $count && ($this->lineEndingStart($lineEndings[$next][1]) ?? $pos->startOffset) < $pos->startOffset) {
                    $next++;
                }
                if ($next < $count) {
                    $span = $inline instanceof HardBreak
                        ? $this->lineEndingCoordinates($pos, $lineEndings[$next][1])
                        : $this->endOfLineSpan($lineEndings[$next][1]);
                    $next++;
                }
            }

            if ($inline instanceof HardBreak) {
                $inline->setPos($span);

                continue;
            }

            $hardBreak = new HardBreak();
            $hardBreak->setPos($span);
            $parent->replaceChild($index, $hardBreak);
        }
    }

    public function lineEndingStart(int $index): ?int
    {
        return $this->source->lineEndingStart($index);
    }

    /**
     * Expand one line-block line, preserving significant whitespace.
     *
     * @param string $line
     * @param int $lineNo
     *
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>, 2: int}
     */
    public function expandLineBlockLine(string $line, int $lineNo): array
    {
        $length = strlen($line);
        $kept = $length;
        $offset = 0;
        $column = 0;
        $expanded = '';
        $runs = [];
        $runStartInSource = null;
        $runStartInExpanded = 0;
        $seenContent = false;

        while ($offset < $length) {
            $char = $line[$offset];
            if ($char !== ' ' && $char !== "\t") {
                if ($runStartInSource === null) {
                    $runStartInSource = $offset;
                    $runStartInExpanded = strlen($expanded);
                }
                $runLength = strcspn($line, " \t", $offset);
                $text = substr($line, $offset, $runLength);
                $expanded .= $text;
                $seenContent = true;
                // UTF-8 continuation bytes advance no source column.
                $column += $runLength - preg_match_all('/[\x80-\xBF]/', $text);
                $offset += $runLength;

                continue;
            }

            $width = 0;
            $wsStart = $offset;
            while ($offset < $length && ($line[$offset] === ' ' || $line[$offset] === "\t")) {
                if ($line[$offset] === "\t") {
                    $width += 4 - (($column + $width) % 4);
                } else {
                    $width++;
                }
                $offset++;
            }
            $column += $width;

            if (!$seenContent || $width >= 2) {
                if ($runStartInSource !== null) {
                    $runs[] = [$runStartInExpanded, $runStartInSource, $wsStart - $runStartInSource, $wsStart - $runStartInSource];
                    $runStartInSource = null;
                }
                if (!str_contains(substr($line, $wsStart, $offset - $wsStart), "\t")) {
                    $runs[] = [strlen($expanded), $wsStart, $width * strlen(SourceMap::INDENT_SENTINEL), $width];
                }
                $expanded .= str_repeat(SourceMap::INDENT_SENTINEL, $width);

                continue;
            }

            // A ONE-COLUMN run at the END of the line is TRAILING WHITESPACE and
            // is dropped like anywhere else (PART 2, markup-carve/carve#926).
            // The order is what makes this reachable: §23 converts an inner or
            // trailing run of TWO OR MORE columns into NBSP CONTENT above, and
            // content is not whitespace - so the rule never reaches that run.
            // What is left here is §23's one-column case, and at the end of a
            // line it is the only kind of whitespace still standing.
            if ($offset >= $length) {
                // The open run ends where the DROPPED whitespace begins, not
                // where the line does. Carrying it to the line end left the run
                // one byte longer than the text it describes, and since lookup
                // takes the first segment covering an offset, the line ending's
                // own segment was shadowed: the break landed on the discarded
                // space instead of the newline. A wrong span, which §4 rates
                // below no span at all.
                if ($runStartInSource !== null) {
                    $runs[] = [$runStartInExpanded, $runStartInSource, $wsStart - $runStartInSource, $wsStart - $runStartInSource];
                    $runStartInSource = null;
                }
                // The line KEEPS nothing past here, so neither may a span over
                // it. A paragraph stamped with whole-line geometry covered this
                // discarded space, and §4 has a span end immediately after the
                // last codepoint the construct owns (carve-php#1363).
                $kept = $wsStart;

                break;
            }

            // One source character, one space: the run stays mappable, so it
            // continues whatever literal run is already open rather than
            // breaking it.
            if ($runStartInSource === null) {
                $runStartInSource = $wsStart;
                $runStartInExpanded = strlen($expanded);
            }
            $expanded .= ' ';
        }

        if ($runStartInSource !== null) {
            $runs[] = [$runStartInExpanded, $runStartInSource, $offset - $runStartInSource, $offset - $runStartInSource];
        }

        return [$expanded, $this->rebaseStrippedTabColumns($runs, $line, $lineNo), $kept];
    }

    /**
     * Bring a stanza line's runs back onto the AUTHORED line when the container
     * strip left part of a tab behind.
     *
     * A tab that straddles the strip's boundary comes back as the spaces it
     * still claims ({@see \MarkupCarve\Carve\Parser\Utility\IndentationHelper::stripLeadingColumns()}),
     * so the line this stanza reads is LONGER than the line the author wrote and
     * every offset past the tab is short by the difference. The tab's own byte
     * still backs the first column it kept; the columns before that one stand for
     * no byte at all, and PART 12 section 4 rates no position above a wrong one,
     * so their run is trimmed rather than guessed (markup-carve/carve#2353).
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $runs
     * @param string $line
     * @param int $lineNo
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public function rebaseStrippedTabColumns(array $runs, string $line, int $lineNo): array
    {
        $authored = $this->state->source->sourceLines[$this->sourceLineFor($lineNo)] ?? null;
        if ($authored === null) {
            return $runs;
        }
        $shift = strlen($line) - strlen($authored);
        if ($shift <= 0) {
            return $runs;
        }

        $rebased = [];
        foreach ($runs as [$textOffset, $sourceOffset, $length, $sourceLength]) {
            $short = $shift - $sourceOffset;
            if ($short > 0) {
                $textOffset += $short;
                $length -= $short;
                $sourceLength -= $short;
                $sourceOffset = $shift;
                if ($length <= 0 || $sourceLength <= 0) {
                    continue;
                }
            }
            $rebased[] = [$textOffset, $sourceOffset - $shift, $length, $sourceLength];
        }

        return $rebased;
    }

    private function applyPendingAttributes(Node $node): void
    {
        ($this->applyPendingAttributesCallback)($node);
    }

    private function endOfLineSpan(int $index): ?SourceSpan
    {
        return $this->source->endOfLineSpan($index);
    }

    private function lineEndingCoordinates(SourceSpan $span, int $sourceLine): SourceSpan
    {
        return $this->source->lineEndingCoordinates($span, $sourceLine);
    }

    private function positionSource(): string
    {
        return ($this->positionSourceCallback)();
    }

    private function sourceLineFor(int $index): int
    {
        return $this->source->sourceLineFor($index);
    }

    private function stampBlockSpan(Node $node, int $startLine, int $endLine, ?int $endBytesOnEndLine = null): void
    {
        $this->source->stampBlockSpan($node, $startLine, $endLine, $endBytesOnEndLine);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\LineBlock $lineBlock
     * @param list<array{0: string, 1: int}> $lines
     */
    private function callAppendLineBlockStanza(LineBlock $lineBlock, array $lines): void
    {
        if ($this->appendLineBlockStanzaCallback !== null) {
            ($this->appendLineBlockStanzaCallback)($lineBlock, $lines);

            return;
        }

        $this->appendLineBlockStanza($lineBlock, $lines);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     * @param list<array{0: int, 1: int}> $lineEndings Text offset and line number, ascending.
     */
    private function callConvertParagraphSoftBreaksToHardBreaks(Paragraph $paragraph, array $lineEndings = []): void
    {
        if ($this->convertParagraphSoftBreaksToHardBreaksCallback !== null) {
            ($this->convertParagraphSoftBreaksToHardBreaksCallback)($paragraph, $lineEndings);

            return;
        }

        $this->convertParagraphSoftBreaksToHardBreaks($paragraph, $lineEndings);
    }

    /**
     * @param string $line
     * @param int $lineNo
     *
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>, 2: int}
     */
    private function callExpandLineBlockLine(string $line, int $lineNo): array
    {
        if ($this->expandLineBlockLineCallback !== null) {
            return ($this->expandLineBlockLineCallback)($line, $lineNo);
        }

        return $this->expandLineBlockLine($line, $lineNo);
    }

    /**
     * @param string $line
     *
     * @return array{length: int, attrs: string|null}|null
     */
    private function callParseLineBlockOpener(string $line): ?array
    {
        if ($this->parseLineBlockOpenerCallback !== null) {
            return ($this->parseLineBlockOpenerCallback)($line);
        }

        return $this->parseLineBlockOpener($line);
    }
}
