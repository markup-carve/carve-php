<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-125 case eight (carve#6730): only the first caret link behind an early note closer keeps its caret bare.
 */
final class CaretLinksBehindCloserTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function forms(): array
    {
        return [
            'second link keeps case five' => ["^[\\] text [\\^](u) [\\^](v)\n", "^[\\] text [^](u) [\\^](v)\n"],
            'bare first link' => ["^[\\] text [^](u) [\\^](v)\n", "^[\\] text [^](u) [\\^](v)\n"],
            'across a soft break' => ["^[\\]\n[\\^](u) [\\^](v)\n", "^[\\]\n[^](u) [\\^](v)\n"],
            'footnote reference between' => ["^[\\] [^a](u) [\\^](u)\n", "^[\\] [^a](u) [\\^](u)\n"],
            'caret and space text' => ["^[\\] [\\^ b](u)\n", "^[\\] [\\^ b](u)\n"],
            'authored full reference behind a node' => [
                "^[\\] *s* [\\^][n]\n\n[n]: /d\n",
                "^[\\] *s* [\\^][n]\n\n[n]: /d\n",
            ],
        ];
    }

    #[DataProvider('forms')]
    public function testForms(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame([], $result->diagnostics);
        self::assertSame($expected, $result->source);
        self::assertSame($expected, Carve::format($expected));
        self::assertTrue(TreeEquality::equal(json_decode(Carve::toJson($source), true), json_decode(Carve::toJson($expected), true)));
    }
}
