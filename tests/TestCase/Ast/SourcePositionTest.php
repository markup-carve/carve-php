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
            if ($pos !== null && substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset) === $expected) {
                $found = $child;

                break;
            }
        }

        $this->assertNotNull($found, sprintf('no block span selected %s', json_encode($expected)));
    }

    public function testOffsetsAreBytesNotUtf16(): void
    {
        // The unit is the whole of markup-carve/carve#394. An emoji is 4 bytes
        // and 2 UTF-16 code units, so the two answers differ by 2 here - which
        // is exactly the case no corpus fixture covers.
        $source = "\u{1F600} text\n";
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $pos = $document->getChildren()[0]->getPos();

        $this->assertNotNull($pos);
        $this->assertSame(9, $pos->endOffset, 'bytes; UTF-16 would be 7');
        $this->assertSame("\u{1F600} text", substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset));
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
                substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset),
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
                $selected = substr($normalized, $pos->startOffset, $pos->endOffset - $pos->startOffset);
                if ($selected !== $node->getContent()) {
                    $wrong[] = basename($file) . ': ' . json_encode($selected);
                }
            }
        }

        $this->assertGreaterThan(400, $checked, 'the sweep placed almost nothing; the wiring regressed');
        $this->assertSame([], $wrong, sprintf('%d spans point at the wrong source', count($wrong)));
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

    public function testPositionsAreOffByDefault(): void
    {
        // Opt-in: normal parsing must not pay for spans it did not ask for.
        $document = (new BlockParser())->parse("# Title\n");

        $this->assertNull($document->getChildren()[0]->getPos());
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
