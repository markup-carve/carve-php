<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The offsets quoted in `SourceSpan`'s docblock are the offsets this engine
 * reports.
 *
 * That docblock is where someone comes to look up what a span indexes, and it
 * has now been wrong twice. First it asserted the opposite contract outright -
 * offsets into the parser's normalized copy - citing carve-js as agreeing.
 * Then the correction cited carve-js reporting 9 and 11, and a byte-order mark
 * shifting a span "from 2 to 3"; the real figures are 3 and 5, and the mark
 * shifts by ONE.
 *
 * Both survived because a number in a comment is not measured by anything. The
 * shift being one rather than three is the part worth pinning: PART 12 §4
 * counts CODEPOINTS, and U+FEFF is a single codepoint written as three bytes,
 * so a "3" there is a byte count - the unit confusion behind carve#876.
 */
class SourceSpanDocblockOffsetsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function quotedOffsetProvider(): array
    {
        return [
            'newline endings' => ["a\n\n*b*\n", 3, 6],
            'CRLF endings' => ["a\r\n\r\n*b*\r\n", 5, 8],
            'a leading byte order mark' => ["\u{FEFF}*b*\n", 1, 4],
            'no mark' => ["*b*\n", 0, 3],
        ];
    }

    #[DataProvider('quotedOffsetProvider')]
    public function testTheDocblockQuotesTheSpanThisEngineReports(string $source, int $start, int $end): void
    {
        $pos = $this->firstStrongPos($source);

        $this->assertNotNull($pos, 'no strong node was placed');
        $this->assertSame($start, $pos['startOffset']);
        $this->assertSame($end, $pos['endOffset']);
    }

    public function testTheMarkShiftsBySingleCodepointNotItsThreeBytes(): void
    {
        // Stated as a relationship rather than as two more literals, so it
        // keeps meaning something if the sample document ever changes.
        $withMark = $this->firstStrongPos("\u{FEFF}*b*\n");
        $without = $this->firstStrongPos("*b*\n");

        $this->assertNotNull($withMark);
        $this->assertNotNull($without);
        $this->assertSame(1, $withMark['startOffset'] - $without['startOffset']);
        $this->assertSame(3, strlen("\u{FEFF}"), 'the mark is still three bytes; the point is that the shift is not');
    }

    /**
     * @return array{startOffset: int, endOffset: int}|null
     */
    protected function firstStrongPos(string $source): ?array
    {
        $tree = (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));

        return $this->findStrong($tree);
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array{startOffset: int, endOffset: int}|null
     */
    protected function findStrong(array $node): ?array
    {
        if (($node['type'] ?? null) === 'strong' && isset($node['pos'])) {
            /** @var array{startOffset: int, endOffset: int} $pos */
            $pos = $node['pos'];

            return $pos;
        }

        foreach (['children', 'items', 'rows'] as $key) {
            /** @var array<mixed> $branch */
            $branch = $node[$key] ?? [];
            foreach ($branch as $child) {
                if (!is_array($child) || !isset($child['type'])) {
                    continue;
                }
                $found = $this->findStrong($child);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
