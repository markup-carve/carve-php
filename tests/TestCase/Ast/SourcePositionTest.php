<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Test\TestCase\CorpusPopulation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §4 source spans.
 *
 * The gate here is NOT coverage. §4 forbids inventing a position, so a node the
 * parser cannot place honestly carries none, and that is a correct outcome - the
 * codec omits `pos` for it. What must never happen is a span that points
 * somewhere else, because a consumer slicing with it gets the wrong text and
 * nothing says so.
 *
 * So every test below asks the same question: does cutting the source with this
 * span produce the bytes the node actually holds?
 */
class SourcePositionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function blockProvider(): array
    {
        return [
            'heading' => ["# Title\n\ntext\n", '# Title'],
            'paragraph' => ["# T\n\nSome text here.\n", 'Some text here.'],
            'fenced code' => ["```\ncode\n```\n", "```\ncode\n```"],
        ];
    }

    #[DataProvider('blockProvider')]
    public function testABlockSpanSelectsItsOwnSource(string $source, string $expected): void
    {
        $document = (new BlockParser(trackPositions: true))->parse($source);

        $found = null;
        foreach ($document->getChildren() as $child) {
            $pos = $child->getPos();
            if ($pos !== null && mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8') === $expected) {
                $found = $child;

                break;
            }
        }

        $this->assertNotNull($found, sprintf('no block span selected %s', json_encode($expected)));
    }

    public function testOffsetsAreCountedInCodepoints(): void
    {
        // PART 12 section 4 counts codepoints, and says why: a codepoint index
        // always lands on a character boundary, while a byte offset can point
        // inside a UTF-8 sequence and a UTF-16 offset inside a surrogate pair.
        // An emoji is 1 codepoint, 2 UTF-16 units and 4 bytes, so all three
        // answers differ here - the case no corpus fixture covered while the
        // units silently disagreed (markup-carve/carve#394).
        $source = "\u{1F600} text\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $pos = $document->getChildren()[0]->getPos();

        $this->assertNotNull($pos);
        $this->assertSame(6, $pos->endOffset, 'codepoints; UTF-16 would be 7 and bytes 9');
        $this->assertSame(
            "\u{1F600} text",
            mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8'),
        );
    }

    public function testAWrongSpanIsDeclinedRatherThanEmitted(): void
    {
        // A nested inline parse restarts its cursor at 0 while still holding the
        // enclosing map, so the text inside `*bold*` would be placed at the
        // start of the paragraph - plausible, wrong, and silent. SourceMap
        // verifies a span against the source before allowing it, so the node
        // ends up with none instead.
        $source = "\u{1F600} text and *bold* here\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        foreach (self::walk($document) as $node) {
            $pos = $node->getPos();
            if (!$node instanceof Text || $pos === null) {
                continue;
            }

            $this->assertSame(
                $node->getContent(),
                mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8'),
                'a text span must select exactly the text it belongs to',
            );
        }
    }

    public function testNoCorpusDocumentGetsAWrongTextSpan(): void
    {
        // The standing gate. Coverage may rise or fall as more of the parser is
        // threaded; a span that points at the wrong bytes must stay impossible.
        $files = glob(dirname(__DIR__, 3) . '/tests/spec/tests/corpus/*.crv') ?: [];
        $this->assertSame(
            CorpusPopulation::expectedSize(),
            count($files),
            'the corpus is truncated',
        );

        $checked = 0;
        $wrong = [];
        foreach ($files as $file) {
            // SLICED FROM THE SOURCE AS GIVEN. This used to fold CRLF and strip
            // a leading BOM first, on the theory that offsets index the
            // parser's normalized copy. They do not - they index the file the
            // caller passed, which is the only string a consumer holds
            // (carve#876, and carve-rs#707 for the same decision there). The
            // difference was unreachable while no corpus document contained a
            // carriage return or a mark; the four the spec added under
            // `line-endings-and-a-byte-order-mark` reach it, and this sweep
            // reported five wrong spans that were the sweep's own arithmetic.
            $source = (string)file_get_contents($file);

            $document = (new BlockParser(trackPositions: true))->parse($source);
            foreach (self::walk($document) as $node) {
                $pos = $node->getPos();
                if (!$node instanceof Text || $pos === null) {
                    continue;
                }

                $checked++;
                $selected = mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8');
                // Two verified classes, not one. A verbatim run's span selects
                // exactly its content. A run the parser REWROTE cannot - its
                // text is not its source by construction - so it is checked the
                // other way: the source it covers must PRODUCE the text under
                // the same rewrite. Anything that satisfies neither is wrong.
                if ($selected === $node->getContent()) {
                    continue;
                }
                if (self::applyEscapes($selected) === $node->getContent()) {
                    continue;
                }
                // A verse line's preserved whitespace is the other rewrite:
                // each SPACE of it becomes one U+E000, wherever on the line the
                // run sits, so the span covers the same characters while the
                // text differs in exactly those positions. One-for-one, which is
                // why it is placed at all - a tab widens to several and declines
                // instead.
                if (self::isVerseIndent($selected, $node->getContent())) {
                    continue;
                }

                $wrong[] = basename($file) . ': ' . json_encode($selected);
            }
        }

        $this->assertGreaterThan(400, $checked, 'the sweep placed almost nothing; the wiring regressed');
        $this->assertSame([], $wrong, sprintf('%d spans point at the wrong source', count($wrong)));
    }

    public function testNoSpanEscapesItsParent(): void
    {
        // The invariant that catches what the text sweep cannot. Verifying text
        // nodes only left every OTHER span unchecked, and there were 49 wrong
        // ones hiding there: a list item measured from its marker line stopped
        // at that line while the nested list inside it ran on for several more.
        // A node contains what it holds, so a child span outside its parent is
        // a defect no matter which node type it is.
        $files = glob(dirname(__DIR__, 3) . '/tests/spec/tests/corpus/*.crv') ?: [];
        $violations = [];
        $checked = 0;

        foreach ($files as $file) {
            $document = (new BlockParser(trackPositions: true))->parse((string)file_get_contents($file));
            foreach (self::walk($document) as $node) {
                $parent = $node->getPos();
                if ($parent === null) {
                    continue;
                }
                foreach ($node->getChildren() as $child) {
                    $span = $child->getPos();
                    if ($span === null) {
                        continue;
                    }
                    $checked++;
                    if ($span->startOffset < $parent->startOffset || $span->endOffset > $parent->endOffset) {
                        $violations[] = sprintf('%s: %s escapes %s', basename($file), $child->getType(), $node->getType());
                    }
                }
            }
        }

        $this->assertGreaterThan(1000, $checked, 'the sweep checked almost nothing');
        $this->assertSame([], $violations, sprintf('%d spans fall outside their parent', count($violations)));
    }

    public function testCoverageDoesNotRegress(): void
    {
        // A floor, not a target. Correctness is pinned by the sweep above; this
        // only catches a change that silently stops placing nodes it used to -
        // which would otherwise look like a passing suite with quietly emptier
        // output. Raise it when coverage rises.
        $files = glob(dirname(__DIR__, 3) . '/tests/spec/tests/corpus/*.crv') ?: [];
        $total = 0;
        $placed = 0;

        foreach ($files as $file) {
            $document = (new BlockParser(trackPositions: true))->parse((string)file_get_contents($file));
            foreach (self::walk($document) as $node) {
                $total++;
                if ($node->getPos() !== null) {
                    $placed++;
                }
            }
        }

        // carve-php#527 dropped this floor from 0.997: the parser now keeps a
        // real (empty) `table_cell` for every `^`/`<` span marker instead of
        // absorbing it into the origin as a rowspan/colspan count (carve-js
        // parity - a consumer walking `rows[i].cells` gets the same length for
        // every row). A span marker's placeholder declines a position, same as
        // it always did as a degenerate marker - there are just more of them
        // now that a CONSUMED marker keeps its own cell too, so the corpus's
        // unplaced-node count rose without any placement logic regressing.
        $this->assertGreaterThan(
            0.993,
            $placed / $total,
            sprintf('position coverage fell to %.1f%% (%d of %d nodes)', 100 * $placed / $total, $placed, $total),
        );
    }

    public function testTwoCellsWithTheSameTextGetDifferentPositions(): void
    {
        // The case that forced cell offsets to come from the split rather than
        // from searching the row. Both cells hold "a", so a search returns the
        // first for BOTH - and a span selecting the right bytes at the wrong
        // cell passes every check a consumer could apply, including this
        // engine's own verification. Only the splitter knows which is which.
        $source = "| a | a |\n|---|---|\n| x | y |\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        $offsets = [];
        foreach (self::walk($document) as $node) {
            $pos = $node->getPos();
            if ($node instanceof Text && $node->getContent() === 'a' && $pos !== null) {
                $offsets[] = $pos->startOffset;
            }
        }

        $this->assertCount(2, $offsets, 'both cells should be placed');
        $this->assertNotSame($offsets[0], $offsets[1], 'the second cell must not reuse the first cell position');
        $this->assertSame([2, 6], $offsets);
    }

    public function testACellWithAnEscapedPipeIsPlaced(): void
    {
        // The escape is no longer collapsed into the cell's text: it becomes an
        // `EscapedText` node, as it does everywhere else and as carve-js and
        // carve-rs publish it. So the cell's content is once again a verbatim
        // run of the row and every piece of it carries a span.
        //
        // This used to assert the opposite, on the reasoning that a collapsed
        // `\|` left the text a byte shorter than its source. That was true of
        // the collapsing, not of the escape - keeping the escape removes the
        // premise rather than working around it.
        $source = "| a\|b | c |\n|---|---|\n| x | y |\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        $pieces = [];
        foreach (self::walk($document) as $node) {
            if ($node instanceof EscapedText && $node->getContent() === '|') {
                $pieces['escape'] = $node;
            }
            if ($node instanceof Text && $node->getContent() === 'a') {
                $pieces['before'] = $node;
            }
        }

        $this->assertArrayHasKey('escape', $pieces, 'the escaped pipe is not its own node');
        $this->assertArrayHasKey('before', $pieces, 'the text before the escape was not found');
        $this->assertNotNull($pieces['escape']->getPos(), 'the escape must carry a span');
        $this->assertNotNull($pieces['before']->getPos(), 'the text before it must carry a span');

        $span = $pieces['escape']->getPos();
        $this->assertSame(
            '\\|',
            substr($source, $span->startOffset, $span->endOffset - $span->startOffset),
            'the escape span does not cover the two source characters it came from',
        );
    }

    public function testCellTextIsPlacedInsideItsOwnCellNotThePadding(): void
    {
        // The cell span covers the padding around the content, so handing it to
        // the text node produced spans covering bytes the node does not hold.
        $source = "| a | b |\n|---|---|\n| x | y |\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        $found = [];
        foreach (self::walk($document) as $node) {
            $pos = $node->getPos();
            if (!$node instanceof Text || $pos === null) {
                continue;
            }
            $found[$node->getContent()] = mb_substr(
                $source,
                $pos->startOffset,
                $pos->endOffset - $pos->startOffset,
                'UTF-8',
            );
        }

        $this->assertSame(['a' => 'a', 'b' => 'b', 'x' => 'x', 'y' => 'y'], $found);
    }

    public function testCellTextInsideAListItemIsPlacedDespiteReindentation(): void
    {
        // A table nested in a list item arrives already re-indented, so the cell
        // offset is short by whatever was stripped. Four spans landed on the
        // wrong bytes before the span was checked against the source and the
        // content looked up in the real line instead.
        $source = "- item\n\n  | H |\n  |---|\n  | x |\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        foreach (self::walk($document) as $node) {
            $pos = $node->getPos();
            if (!$node instanceof Text || $pos === null) {
                continue;
            }

            $this->assertSame(
                $node->getContent(),
                mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8'),
                'a nested cell span must still select its own text',
            );
        }
    }

    public function testARewrittenRunIsPlacedOnSourceThatProducesIt(): void
    {
        // `\ ` is Carve's non-breaking-space form: two source bytes become one
        // sentinel, so the span cannot equal the text. It is verified the other
        // way - the source it covers, put through the same rewrite, produces it.
        $source = "10\\ kg\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        foreach (self::walk($document) as $node) {
            if (!$node instanceof Text) {
                continue;
            }

            $pos = $node->getPos();
            $this->assertNotNull($pos, 'a rewritten run should still be placed');
            $selected = mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8');
            $this->assertNotSame($node->getContent(), $selected, 'the span cannot equal rewritten text');
            $this->assertSame(self::applyEscapes($selected), $node->getContent());

            return;
        }

        $this->fail('no text node was found');
    }

    public function testPositionsAreOffByDefault(): void
    {
        // Opt-in: normal parsing must not pay for spans it did not ask for.
        $document = (new BlockParser())->parse("# Title\n");

        $this->assertNull($document->getChildren()[0]->getPos());
    }

    /**
     * The escape rewrites a buffered text run can carry: `\ ` becomes the
     * non-breaking-space sentinel, and a backslash before ASCII punctuation
     * becomes the punctuation alone.
     */

    /**
     * A SPACE indent merges into its line and is placed WITH it.
     *
     * It used to be a node of its own, because each stanza line - in fact each
     * whitespace-delimited segment of one - was parsed separately, and the
     * indent was appended between those parses with a span the parser built
     * directly. That per-segment parse is exactly what stopped an unclosed
     * inline run at the line ending, so it is gone: the stanza is expanded and
     * parsed once, and the placeholders arrive as ordinary text
     * (markup-carve/carve-php#1327).
     *
     * The merged node then declined for a while, because the map could only say
     * that N source bytes became N built bytes and U+E000 is three bytes where
     * the space it replaced is one. That was a real omission rather than a §4
     * exemption - carve-rs publishes the span, and the source under it really is
     * the whitespace the sentinels stand for - so the map carries the rewrite
     * now and the node is placed again (carve-php#1351).
     *
     * A TAB is the one that still declines, and
     * {@see self::testATabIndentDeclinesAPosition()} keeps it declining: it
     * widens to a variable number of placeholders, so no count of source bytes
     * stands behind them.
     *
     * The break beside it keeps its own span: see
     * {@see self::testAVerseBreakIsStillPlacedOverItsLineEnding()}.
     */
    public function testAVerseIndentMergesIntoItsLineAndIsPlacedWithIt(): void
    {
        $source = "::: |\nRoses are red,\n  Violets are blue.\n:::\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        $merged = null;
        foreach (self::walk($document) as $node) {
            if ($node instanceof Text && str_contains($node->getContent(), "\u{E000}")) {
                $merged = $node;

                break;
            }
        }

        $this->assertNotNull($merged, 'the verse indent was not found');
        $this->assertSame(
            str_repeat("\u{E000}", 2) . 'Violets are blue.',
            $merged->getContent(),
            'the indent is part of its line rather than a node of its own',
        );

        $pos = $merged->getPos();
        $this->assertNotNull($pos, 'the spaced form has an honest span and must publish it');
        // ASSERTED AS THE SLICE, not as offsets alone. carve-rs publishes 21-40
        // for this document, and those numbers only mean something if they
        // select the indentation together with the line it belongs to.
        $this->assertSame(
            '  Violets are blue.',
            mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8'),
        );
    }

    /**
     * The line ending keeps its own span, in the form a line ending has.
     *
     * The break is no longer appended by the block layer with a span it chose;
     * it is the soft break the single parse produced, promoted. Its offsets are
     * resolved through the stanza's map, which needs a segment for the joined
     * newline - no literal run reaches it, so without one the break resolved
     * its start and not its end and lost its position entirely.
     */
    public function testAVerseBreakIsStillPlacedOverItsLineEnding(): void
    {
        $source = "::: |\nRoses are red,\n  Violets are blue.\n:::\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        $break = null;
        foreach (self::walk($document) as $node) {
            if ($node instanceof HardBreak) {
                $break = $node;

                break;
            }
        }

        $this->assertNotNull($break, 'the stanza break was not found');
        $pos = $break->getPos();
        $this->assertNotNull($pos, 'the line ending is measured and must be placed');
        $this->assertSame(
            "\n",
            substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset),
            'the span does not cover the newline that ends the line',
        );
        $this->assertSame(2, $pos->startLine);
        $this->assertSame(3, $pos->endLine);
        $this->assertSame(1, $pos->endColumn);
    }

    /**
     * The break lands on the NEWLINE even when a dropped space sits before it.
     *
     * The discriminating shape, and the one a fixture on well-formed input
     * cannot reach. A line ending's text offset means two things at once - the
     * exclusive end of the text before it, and the start of the newline - and
     * they are the same byte until a trailing one-column run is dropped. Then
     * they are one apart, and a break resolved through the map took the earlier
     * reading and was stamped over the discarded space: a span selecting `" "`
     * where the node is a line ending. Wrong, not merely absent, which is the
     * grade PART 12 §4 cares about.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function breakShapeProvider(): array
    {
        return [
            'nothing between the text and the ending' => ["::: |\na b\nc\n:::\n", 9],
            'a dropped one-column trailing run' => ["::: |\na b \nc\n:::\n", 10],
            'a preserved trailing gap' => ["::: |\na b  \nc\n:::\n", 11],
            'a leading indent on the NEXT line' => ["::: |\na b\n  c\n:::\n", 9],
        ];
    }

    #[DataProvider('breakShapeProvider')]
    public function testTheBreakCoversTheNewlineAndNothingElse(string $source, int $expectedStart): void
    {
        $document = (new BlockParser(trackPositions: true))->parse($source);

        foreach (self::walk($document) as $node) {
            if (!$node instanceof HardBreak) {
                continue;
            }

            $pos = $node->getPos();
            $this->assertNotNull($pos, 'the line ending is measured and must be placed');
            $this->assertSame($expectedStart, $pos->startOffset);
            $this->assertSame(
                "\n",
                substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset),
                'the span must select the newline, not the whitespace before it',
            );

            return;
        }

        $this->fail('no break was found');
    }

    /**
     * A NESTED line block places its text, prefix and all.
     *
     * The stanza is handed lines a container has already stripped its prefix
     * from, so a column measured against them is short by that width when it is
     * mapped from the physical line start. The span then selects the wrong
     * bytes, the check that a span covers the node's own text rejects it, and
     * every inline node in the stanza silently loses its position - a failure
     * that is invisible at the top level, where the prefix is empty, and so
     * exactly the kind a top-level-only fixture cannot see.
     *
     * @return array<string, array{0: string}>
     */
    public static function nestedLineBlockProvider(): array
    {
        return [
            'in a block quote' => ["> ::: |\n> alpha\n> beta\n> :::\n"],
            'in a list item' => ["- ::: |\n  alpha\n  beta\n  :::\n"],
            'in a quote inside a list item' => ["- > ::: |\n  > alpha\n  > beta\n  > :::\n"],
        ];
    }

    #[DataProvider('nestedLineBlockProvider')]
    public function testANestedLineBlockStillPlacesItsText(string $source): void
    {
        $document = (new BlockParser(trackPositions: true))->parse($source);

        $seen = [];
        foreach (self::walk($document) as $node) {
            if (!$node instanceof Text || !in_array($node->getContent(), ['alpha', 'beta'], true)) {
                continue;
            }

            $pos = $node->getPos();
            $this->assertNotNull($pos, "'{$node->getContent()}' lost its position");
            $this->assertSame(
                $node->getContent(),
                substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset),
                'the span does not select the text the node holds',
            );
            $seen[] = $node->getContent();
        }

        $this->assertSame(['alpha', 'beta'], $seen);
    }

    /**
     * A TAB widens to up to four placeholders from one character, so no span
     * can be honest about it.
     */
    public function testATabIndentDeclinesAPosition(): void
    {
        $source = "::: |\nRoses,\n\tViolets.\n:::\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        foreach (self::walk($document) as $node) {
            if ($node instanceof Text && str_contains($node->getContent(), "\u{E000}")) {
                $this->assertNull($node->getPos(), 'a tab indent is not one-for-one and has no honest span');

                return;
            }
        }

        $this->fail('the tab-indented verse line was not found');
    }

    /**
     * Whether `$content` is `$selected` with some of its spaces rewritten to the
     * generated-NBSP placeholder, which is what a line block does to a verse
     * line's preserved whitespace.
     *
     * IT IS NOT ONLY THE INDENT, and it is not a whole node. This used to
     * require the selection to be nothing BUT spaces, which was true while the
     * indent was a node of its own; the stanza is now expanded and parsed once,
     * so the placeholders merge into the line's text and sit at its start, in
     * its middle or at its end (carve-php#1351). Every other byte still has to
     * match exactly, so the rule stays as strict as the escape one beside it -
     * a span over the wrong region would have to differ from its text in
     * nothing but spaces.
     */
    private static function isVerseIndent(string $selected, string $content): bool
    {
        $read = 0;
        $wrote = 0;
        $sentinel = "\u{E000}";
        $width = strlen($sentinel);
        $length = strlen($selected);
        $rewrote = false;
        while ($read < $length) {
            if ($selected[$read] === ' ' && substr($content, $wrote, $width) === $sentinel) {
                $read++;
                $wrote += $width;
                $rewrote = true;

                continue;
            }
            if (($content[$wrote] ?? null) !== $selected[$read]) {
                return false;
            }
            $read++;
            $wrote++;
        }

        // A run that rewrote NOTHING is the plain comparison the caller already
        // made and rejected, so saying yes to it here would turn this into a
        // second chance rather than a second rule.
        return $rewrote && $wrote === strlen($content);
    }

    private static function applyEscapes(string $slice): string
    {
        $escapable = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';
        $out = '';
        $length = strlen($slice);
        for ($i = 0; $i < $length; $i++) {
            if ($slice[$i] === '\\' && $i + 1 < $length) {
                $next = $slice[$i + 1];
                if ($next === ' ') {
                    $out .= "\u{E000}";
                    $i++;

                    continue;
                }
                if (strpos($escapable, $next) !== false) {
                    $out .= $next;
                    $i++;

                    continue;
                }
            }
            $out .= $slice[$i];
        }

        return $out;
    }

    /**
     * @return \Generator<\MarkupCarve\Carve\Node\Node>
     */

    /**
     * @return array<string, array{string, string}>
     */
    public static function lineBlockStanzaProvider(): array
    {
        return [
            // A tab expands to indent sentinels and shifts every offset after it
            // WITHIN a line, so the stanza's inline text is deliberately left
            // unplaced. The stanza's own extent is a different fact - first-line
            // start to last-line end - and a tab moves neither.
            'tab stanza' => [
                "::: |\ntab\tgap\nwide\t\tgap\n\tlead\n:::\n",
                "tab\tgap\nwide\t\tgap\n\tlead",
            ],
            'tab-free stanza' => [
                "::: |\nRoses are red,\nViolets are blue.\n:::\n",
                "Roses are red,\nViolets are blue.",
            ],
        ];
    }

    #[DataProvider('lineBlockStanzaProvider')]
    public function testALineBlockStanzaSpansItsOwnLines(string $source, string $expected): void
    {
        // The span used to be derived from the first PLACED child. With the
        // first text unplaced by the tab, that was the hard break - so the
        // paragraph began at the newline ENDING its first line and that line
        // fell outside its own paragraph (#669).
        $document = (new BlockParser(trackPositions: true))->parse($source);

        $paragraph = null;
        foreach (self::walk($document) as $node) {
            if ($node instanceof Paragraph) {
                $paragraph = $node;

                break;
            }
        }

        $this->assertNotNull($paragraph);
        $pos = $paragraph->getPos();
        $this->assertNotNull($pos);
        $this->assertSame(
            $expected,
            mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8'),
        );
    }

    private static function walk(Node $node): iterable
    {
        foreach ($node->getChildren() as $child) {
            yield $child;

            yield from self::walk($child);
        }
    }
}
