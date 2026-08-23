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
 * A definition written behind a CONTAINER PREFIX - inside a quote, a list item,
 * a definition-list `dd` - ends with its body. The container ends at its last
 * prefixed line and the blank below belongs to the document underneath, so
 * reaching there put the definition's span one codepoint past the block that
 * holds it, which is past the last source codepoint the construct owns
 * (PART 12 §4, markup-carve/carve#1451).
 *
 * THE COLUMN-0 REACH THIS ALSO USED TO PIN HAS BEEN WITHDRAWN. carve#1451 let a
 * definition written at column 0 reach the start of the blank line below it, on
 * the reasoning that its body may resume under that blank so nothing else can
 * claim the line, and recorded that all three engines did so. That is no longer
 * true of any of them: carve#1522 and carve#1524 ruled that a container with no
 * closer ends at its LAST PLACED CHILD, which a footnote definition has no
 * exemption from, and carve-js#1354 moved 27 documents to it. Measured against
 * carve-js `main` while making this change, `[^f]: note` followed by a blank
 * publishes `[0, 10]` and not `[0, 11]`, and the resuming-body shape publishes
 * `[0, 18]` and not `[0, 19]`.
 *
 * So the two directions this pins today are: a prefixed definition ends with its
 * body, and a column-0 definition ends there too. The blank line below is owned
 * by neither - it is the separator, and it reaches no child.
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
            // Offset 10 ends `note`, and the blank line below reaches no child,
            // so the definition stops there rather than at 11.
            'a definition at column 0' => ["[^f]: note\n\nx [^f]\n", 10],
            // A body that actually resumes under the blank. The second block is
            // a child, so the definition reaches IT - and stops at its end, 18,
            // rather than taking the newline that ended it as well.
            'a body resuming under the blank' => ["[^f]: note\n\n  more\n\nx [^f]\n", 18],
        ];
    }

    #[DataProvider('columnZeroProvider')]
    public function testAColumnZeroDefinitionAlsoEndsWithItsBody(string $source, int $end): void
    {
        $node = $this->footnote($source);

        $this->assertNotNull($node, 'the document has no footnote node');
        $this->assertSame(0, $node['pos']['startOffset']);
        $this->assertSame($end, $node['pos']['endOffset']);
    }
}
