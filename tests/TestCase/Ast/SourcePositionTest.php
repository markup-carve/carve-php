<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
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
        $this->assertGreaterThan(400, count($files), 'the corpus was not found');

        $checked = 0;
        $wrong = [];
        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            $normalized = str_replace(["\r\n", "\r"], "\n", $source);
            if (str_starts_with($normalized, "\u{FEFF}")) {
                $normalized = substr($normalized, 3);
            }

            $document = (new BlockParser(trackPositions: true))->parse($source);
            foreach (self::walk($document) as $node) {
                $pos = $node->getPos();
                if (!$node instanceof Text || $pos === null) {
                    continue;
                }

                $checked++;
                $selected = mb_substr($normalized, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8');
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

        $this->assertGreaterThan(
            0.997,
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

    public function testACellWithAnEscapedPipeDeclinesAPosition(): void
    {
        // `\|` collapses to `|`, so the cell's text is one byte shorter than
        // the source it came from and offsets inside it would drift. Declining
        // is correct; a span that is nearly right is the failure mode section 4
        // rules out.
        $source = "| a\|b | c |\n|---|---|\n| x | y |\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);

        foreach (self::walk($document) as $node) {
            if ($node instanceof Text && $node->getContent() === 'a|b') {
                $this->assertNull($node->getPos(), 'a rewritten cell must not carry a span');

                return;
            }
        }

        $this->fail('the escaped-pipe cell was not found');
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
    private static function walk(Node $node): iterable
    {
        foreach ($node->getChildren() as $child) {
            yield $child;

            yield from self::walk($child);
        }
    }
}
