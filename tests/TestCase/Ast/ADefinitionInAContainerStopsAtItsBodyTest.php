<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A footnote definition's span stops where its body does.
 *
 * A definition written at COLUMN 0 owns the blank line below it: its body may
 * resume under that blank, so nothing else can claim the line, and all three
 * engines reach to the start of it. A definition written behind a CONTAINER
 * PREFIX - inside a quote, a list item, a definition-list `dd` - does not. The
 * container ends at its last prefixed line and the blank below belongs to the
 * document underneath, so reaching there put the definition's span one
 * codepoint past the block that holds it, which is past the last source
 * codepoint the construct owns (PART 12 §4).
 *
 * It was one predicate, applied to both: the reach fired wherever a blank line
 * followed the body, without asking whether that blank was inside the same
 * container. carve-js and carve-rs stop at the body for the prefixed shapes and
 * reach for the column-0 one, which is what this now does; the three disagreed
 * on three corpus documents until they did (markup-carve/carve#1451).
 *
 * Both directions are pinned, because a fix that dropped the reach altogether
 * would move the column-0 documents instead.
 */
class ADefinitionInAContainerStopsAtItsBodyTest extends TestCase
{
    /**
     * @return array<string, mixed>|null
     */
    private function footnote(string $source): ?array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $found = null;
        $walk = static function (array $node) use (&$walk, &$found): void {
            if ($found === null && ($node['type'] ?? null) === 'footnote') {
                $found = $node;
            }
            foreach (['children', 'items', 'rows', 'cells'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    $walk($child);
                }
            }
        };
        $walk((new AstCodec())->encode($converter->parse($source)));

        return $found;
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function containerProvider(): array
    {
        return [
            // A quote. The definition ends with the quote's last line, at
            // offset 12; offset 13 is the blank line below the quote.
            'inside a block quote' => ["> [^f]: note\n\nx [^f]\n", 2, 12],
            // A list item, same shape one marker over.
            'inside a list item' => ["- [^f]: note\n\nx [^f]\n", 2, 12],
            // A definition-list description, which is the reported document's
            // shape (corpus 227).
            'inside a dd' => [":: term\n:  [^f]: x\n\nsee[^f]\n", 11, 18],
            // A quote that goes on below the definition: the blank the reach
            // used to take is not even adjacent here, and the answer is the
            // same - the definition's body is its own line.
            'inside a quote with more below' => ["> [^f]: note\n> more\n\nx [^f]\n", 2, 12],
        ];
    }

    #[DataProvider('containerProvider')]
    public function testAPrefixedDefinitionEndsWithItsBody(string $source, int $start, int $end): void
    {
        $node = $this->footnote($source);

        $this->assertNotNull($node, 'the document has no footnote node');
        $this->assertSame($start, $node['pos']['startOffset']);
        $this->assertSame($end, $node['pos']['endOffset']);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function columnZeroProvider(): array
    {
        return [
            // The reach that must survive: offset 10 ends `note`, and 11 is the
            // start of the blank line the definition owns.
            'a definition at column 0' => ["[^f]: note\n\nx [^f]\n", 11],
            // A body that actually resumes under the blank, which is why the
            // column-0 reach exists at all.
            'a body resuming under the blank' => ["[^f]: note\n\n  more\n\nx [^f]\n", 19],
        ];
    }

    #[DataProvider('columnZeroProvider')]
    public function testAColumnZeroDefinitionStillReachesTheBlankBelowIt(string $source, int $end): void
    {
        $node = $this->footnote($source);

        $this->assertNotNull($node, 'the document has no footnote node');
        $this->assertSame(0, $node['pos']['startOffset']);
        $this->assertSame($end, $node['pos']['endOffset']);
    }
}
