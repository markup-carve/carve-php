<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\EscapedText;
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
                // A verse line's indent is the other rewrite: each leading
                // SPACE becomes one U+E000, so the span covers the same
                // characters while the text differs in exactly those positions.
                // One-for-one, which is why it is placed at all - a tab widens
                // to several and declines instead.
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
        //
        // WALKED OVER THE SERIALIZED TREE, not over `getChildren()`
        // (carve-php#1249). A node's children are not all in its child list: a
        // block quote's attribution, a figure's caption and quote target, a
        // table's caption and rows, an admonition's title and an inline
        // footnote's body each live in a slot of their own. Walking the child
        // list alone made this sweep structurally unable to see any of them -
        // it could not fail on a wrong span there, and it did not, while a
        // block quote's span stopped before the attribution it owns for four
        // corpus documents. The codec publishes every slot, and the conformance
        // checker in the spec repo (`scripts/spec/ast-positions.mjs`) walks
        // exactly that shape, so measuring the same tree it does is also what
        // makes a green run here mean the same thing as a green run there.
        //
        // The comparison is against the nearest PLACED ancestor rather than the
        // immediate parent, again matching the checker: PART 12 §4 lets a node
        // whose content is not a contiguous slice omit `pos`, and stopping at
        // one would go quiet exactly where a span is most likely to be wrong.
        $files = glob(dirname(__DIR__, 3) . '/tests/spec/tests/corpus/*.crv') ?: [];
        $codec = new AstCodec();
        $violations = [];
        $checked = 0;

        foreach ($files as $file) {
            $document = (new BlockParser(trackPositions: true))->parse((string)file_get_contents($file));
            $violations = array_merge(
                $violations,
                self::containmentFindings($codec->encode($document), '$', null, '$', basename($file), $checked),
            );
        }

        // A CONTAINMENT PASS THAT EXAMINED NOTHING reports zero findings and is
        // indistinguishable from a clean one, which is why the pair count is
        // asserted alongside the findings (PART 12 §4, carve#913). The floor
        // rose with the walk: the child list reached about 1000 pairs, the
        // serialized tree reaches roughly 5000.
        $this->assertGreaterThan(4000, $checked, 'the sweep checked almost nothing');
        $this->assertSame([], $violations, sprintf('%d spans fall outside their parent', count($violations)));
    }

    public function testTheContainmentSweepReachesASlotOutsideTheChildList(): void
    {
        // The sweep above is only worth its runtime if it can see a slot the
        // child list does not carry. Asserting that directly, on the slot that
        // was wrong: without it, a rewrite back to `getChildren()` would leave
        // the sweep green with nothing to say (carve-php#1249).
        $source = "> q\n^ A\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $quote = $document->getChildren()[0];

        $this->assertInstanceOf(BlockQuote::class, $quote);
        $attribution = $quote->getAttribution();
        $this->assertNotNull($attribution, 'the caption did not attach as an attribution');
        $this->assertCount(1, $quote->getChildren(), 'the attribution is expected OUTSIDE the child list');

        // Break a span inside the attribution on purpose and require the sweep
        // to say so. The parser is not involved: this is the checker under
        // test. The codec publishes the attribution as the caption's INLINE
        // run, so the node to break is the one inside it.
        $inline = $attribution->getChildren()[0];
        $inline->setPos(new SourceSpan(
            startLine: 9,
            endLine: 9,
            startColumn: 1,
            endColumn: 2,
            startOffset: 900,
            endOffset: 901,
        ));

        $checked = 0;
        $findings = self::containmentFindings(
            (new AstCodec())->encode($document),
            '$',
            null,
            '$',
            'synthetic',
            $checked,
        );

        $this->assertGreaterThan(0, $checked, 'the sweep compared nothing');
        $this->assertNotSame([], $findings, 'a broken attribution span went unreported');
    }

    public function testAQuoteSpanReachesTheEndOfItsAttribution(): void
    {
        // PART 12 §4: "A container ends after its explicit closer when it has
        // one, otherwise after its LAST PLACED CHILD", and a span contains its
        // children's spans. The attribution is written after the quoted lines
        // and is the quote's own child, so the quote runs to the end of that
        // line - not to the end of the last quoted line (carve-php#1249).
        //
        // Offsets, not just containment: containment alone is satisfied by a
        // span that overshoots, and §4 also excludes the trailing terminator.
        $source = "> Stay hungry.\n^ Steve Jobs\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $quote = $document->getChildren()[0];

        $this->assertInstanceOf(BlockQuote::class, $quote);
        $pos = $quote->getPos();
        $this->assertNotNull($pos);
        $this->assertSame(0, $pos->startOffset);
        $this->assertSame(27, $pos->endOffset, 'the quote must end after "Jobs", before the newline');
        $this->assertSame(2, $pos->endLine);
        $this->assertSame(
            "> Stay hungry.\n^ Steve Jobs",
            mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8'),
        );
    }

    public function testABlankLineBeforeTheAttributionIsInsideTheQuoteSpan(): void
    {
        // The separated spelling, which the corpus carries twice
        // (`55-blockquote-caption-after-a-blank-line`,
        // `282-two-blank-lines-detach-a-caption-5`). §4 excludes a blank line
        // that FOLLOWS a construct; this one is interior - it sits between two
        // children the quote owns - so it is covered like any other gap between
        // siblings, exactly as the table arm already covers its own.
        $source = "> quoted\n\n^ Source\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $quote = $document->getChildren()[0];

        $this->assertInstanceOf(BlockQuote::class, $quote);
        $pos = $quote->getPos();
        $this->assertNotNull($pos);
        $this->assertSame(0, $pos->startOffset);
        $this->assertSame(18, $pos->endOffset);
        $this->assertSame(
            "> quoted\n\n^ Source",
            mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8'),
        );
    }

    public function testAQuoteWithNoAttributionIsNotWidened(): void
    {
        // The control the two above need. A rule that widened unconditionally -
        // or that widened to the following line whatever it held - would pass
        // both of them and be wrong here, where the `^` line is missing and the
        // paragraph after the quote belongs to nobody but the document.
        $source = "> quoted\n\nafter\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $quote = $document->getChildren()[0];

        $this->assertInstanceOf(BlockQuote::class, $quote);
        $this->assertNull($quote->getAttribution());
        $pos = $quote->getPos();
        $this->assertNotNull($pos);
        $this->assertSame(8, $pos->endOffset, 'the quote must stop at the end of its own line');
    }

    /**
     * The containment rule of PART 12 §4, over the SERIALIZED tree.
     *
     * Every key is descended into except `pos`, which holds integers rather
     * than nodes, so a node reached through a slot of its own is compared like
     * any other. `$checked` counts the pairs compared and is by reference for
     * the reason §4 states: a pass that compared nothing must not be able to
     * come back looking clean.
     *
     * @param mixed $node
     * @param string $path
     * @param array<string, mixed>|null $parent The nearest PLACED ancestor.
     * @param string $parentPath
     * @param string $label
     * @param int $checked
     *
     * @return array<string>
     */
    private static function containmentFindings(
        mixed $node,
        string $path,
        ?array $parent,
        string $parentPath,
        string $label,
        int &$checked,
    ): array {
        if (!is_array($node)) {
            return [];
        }

        if (array_is_list($node)) {
            $findings = [];
            foreach ($node as $index => $child) {
                $findings = array_merge(
                    $findings,
                    self::containmentFindings($child, $path . '[' . $index . ']', $parent, $parentPath, $label, $checked),
                );
            }

            return $findings;
        }

        $findings = [];
        $placed = isset($node['type']) && is_string($node['type']) && isset($node['pos']);
        if ($placed && $parent !== null) {
            $checked++;
            if (
                $node['pos']['startOffset'] < $parent['pos']['startOffset']
                || $node['pos']['endOffset'] > $parent['pos']['endOffset']
            ) {
                $findings[] = sprintf(
                    '%s: "%s" at %s [%d, %d] is not inside "%s" at %s [%d, %d]',
                    $label,
                    $node['type'],
                    $path,
                    $node['pos']['startOffset'],
                    $node['pos']['endOffset'],
                    $parent['type'],
                    $parentPath,
                    $parent['pos']['startOffset'],
                    $parent['pos']['endOffset'],
                );
            }
        }

        $nextParent = $placed ? $node : $parent;
        $nextPath = $placed ? $path : $parentPath;
        foreach ($node as $key => $value) {
            if ($key === 'pos') {
                continue;
            }
            $findings = array_merge(
                $findings,
                self::containmentFindings($value, $path . '.' . $key, $nextParent, $nextPath, $label, $checked),
            );
        }

        return $findings;
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
     * A verse line's indent is REWRITTEN one placeholder per space, so it spans
     * exactly the characters it replaced and is placeable. The corpus-wide
     * wrong-span guard cannot cover this on its own: without a position the
     * node is simply skipped there, so absence looks identical to correctness.
     */
    public function testAVerseIndentIsPlacedOverTheSpacesItReplaced(): void
    {
        $source = "::: |\nRoses are red,\n  Violets are blue.\n:::\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        $indent = null;
        foreach (self::walk($document) as $node) {
            if ($node instanceof Text && $node->getContent() === str_repeat("\u{E000}", 2)) {
                $indent = $node;

                break;
            }
        }

        $this->assertNotNull($indent, 'the verse indent did not become its own node');
        $pos = $indent->getPos();
        $this->assertNotNull($pos, 'a one-for-one rewrite must still be placed');
        $this->assertSame(
            '  ',
            mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8'),
            'the span does not cover the two spaces the placeholders replaced',
        );
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
     * Whether `$content` is `$selected` with every space rewritten to the
     * generated-NBSP placeholder, which is what a line block does to a verse
     * line's indent.
     */
    private static function isVerseIndent(string $selected, string $content): bool
    {
        if ($selected === '' || trim($selected, ' ') !== '') {
            return false;
        }

        return str_repeat("\u{E000}", strlen($selected)) === $content;
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
