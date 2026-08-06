<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §4: positions index the source, counted in codepoints.
 *
 * The parser rewrites the text before it reads it - a leading byte order mark
 * is dropped and every CRLF or lone CR becomes a single newline. Both make the
 * text shorter, so an offset table measured against the rewritten copy names
 * characters that sit earlier in the file than the ones the node came from. A
 * consumer slicing the file it passed in gets the wrong text back, and it is
 * wrong silently: the numbers are in range and look plausible.
 *
 * These assert the property a consumer actually relies on - slice the ORIGINAL
 * source by a reported span and the node's characters come back - rather than
 * pinning particular numbers, which would have to be recomputed by the same
 * arithmetic under test (carve#876).
 */
class PositionsIndexTheOriginalFileTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function normalizingSourceProvider(): array
    {
        return [
            'a leading byte order mark' => ["\u{FEFF}# T\n\nabc\n"],
            'CRLF endings' => ["# T\r\n\r\nabc\r\n"],
            'lone CR endings' => ["# T\r\rabc\r"],
            'a mark and CRLF together' => ["\u{FEFF}# T\r\n\r\nabc\r\n"],
            // The control. If positions broke everywhere this would fail too,
            // and the four rows above would not be about normalization at all.
            'nothing to normalize' => ["# T\n\nabc\n"],
        ];
    }

    #[DataProvider('normalizingSourceProvider')]
    public function testASpanNamesTheCharactersItCameFrom(string $source): void
    {
        $tree = (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));

        $paragraph = $tree['children'][1];
        $this->assertSame('paragraph', $paragraph['type']);

        foreach ([$paragraph, $paragraph['children'][0]] as $node) {
            $pos = $node['pos'] ?? null;
            $this->assertNotNull($pos, "no position on a {$node['type']} the parser read from the source");
            $this->assertSame('abc', $this->sliceCodepoints($source, $pos['startOffset'], $pos['endOffset']));
        }
    }

    #[DataProvider('normalizingSourceProvider')]
    public function testTheHeadingKeepsItsOwnCharactersToo(string $source): void
    {
        // The first line is where a BOM shifts things and the last is where the
        // line endings accumulate, so pin both ends of the document.
        $tree = (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));

        $pos = $tree['children'][0]['pos'];
        $this->assertSame('# T', $this->sliceCodepoints($source, $pos['startOffset'], $pos['endOffset']));
    }

    protected function sliceCodepoints(string $source, int $start, int $end): string
    {
        $codepoints = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_slice($codepoints, $start, $end - $start));
    }
}
