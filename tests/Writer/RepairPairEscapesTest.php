<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-146: a write the read-back rounds settle is tested with the pair rules too (carve-php#5856). Each expected
 * form is the one carve-js writes.
 */
final class RepairPairEscapesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'paragraph' => ["a\\\\ *b* *c* x`y _e_\n", "a\\\\ *b* *c* x`y \\_e_\n"],
            'highlight in a quote' => ["> a\\\\ *b* ` =g={_i_} [k]\n", "> a\\\\ *b* ` \\=g={_i_} [k]\n"],
            'strikeout before a braced span' => [
                "> x\\\\ ^[n] x`y _ ~f~ {*h*}\n",
                "> x\\\\ ^[n] x`y _ \\~f~ *h*\n",
            ],
            'two pairs, one behind a span' => [
                "> x\\\\ _e_ ``z w {*h*}_e_ ^[n]\n",
                "> x\\\\ \\_e_ ``z w *h*\\_e_ ^[n]\n",
            ],
            'text across a soft break' => ["\\\\ *b* {=d=}=g=\\_\n``z\n", "\\\\ *b* {=d=}\\=g=_\n``z\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testRoundsTakeThePairEscapes(string $source, string $expected): void
    {
        $tree = Carve::parse($source);
        $out = Carve::write($tree);

        $this->assertSame($expected, $out);
        $this->assertSame(Carve::render($source), Carve::render($out));
    }
}
