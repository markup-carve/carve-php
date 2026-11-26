<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-W-125 (carve#6566): a span the parse reads on the opener of a node of the tree is that node, also where
 * it reads on past the closer or ends early. It reports no kind; only the repair of C2-W-125 takes a backslash.
 * Outputs match carve-js.
 */
final class TreeOpenerSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'CC-2018 subscript reads on' => [
            '\\^^},} {,\\{,,}\\},}\\,{,',
            '^^},} {,\\{,,}},},{,',
        ];

        yield 'CC-2019 superscript ends early' => [
            '- {^\\}^{a^\\}{^^}^},,,}',
            '- {^}^{a\\^}{^^}^},,,}',
        ];

        yield 'CC-2020 subscript reads on in a list item' => [
            '- {^^}\\{\\},,{,\\^\\{,},}}}{,,}^{,,},}',
            '- {^^}{},,{,^\\{,},}}}{,,}^{,,},}',
        ];

        yield 'subscript reads on before more text' => [
            '- {^^}\\{\\},,{,\\^\\{,},}}}{,,}^{,,},}a',
            '- {^^}{},,{,^\\{,},}}}{,,}^{,,},}a',
        ];

        yield 'superscript ends early in a quote' => [
            '> {^\\}^{a^\\}{^^}^},,,}',
            '> {^}^{a\\^}{^^}^},,,}',
        ];
    }

    #[DataProvider('cases')]
    public function testSpanOnTheTreeOpenerReportsNoKind(string $input, string $expected): void
    {
        $out = Carve::format($input . "\n");
        self::assertSame($expected . "\n", $out);
        self::assertSame(
            TreeEquality::normalize(TreeEncoder::decodedForWriter(Carve::parse($input . "\n"))),
            TreeEquality::normalize(TreeEncoder::decodedForWriter(Carve::parse($out))),
        );
    }
}
