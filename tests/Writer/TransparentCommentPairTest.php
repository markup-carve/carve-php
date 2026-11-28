<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** C2-I-026, C2-W-002 and C2-W-069: transparent comments do not make a bare pair marker flank whitespace. */
final class TransparentCommentPairTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function forms(): array
    {
        return [
            'strong after line break' => ["{*a\n{% x %}*}\n", "{*a\n{% x %}*}\n"],
            'strong after space' => ["{*a {% x %}*}\n", "{*a {% x %}*}\n"],
            'emphasis after line break' => ["{/a\n{% x %}/}\n", "{/a\n{% x %}/}\n"],
            'emphasis after space' => ["{/a {% x %}/}\n", "{/a {% x %}/}\n"],
            'strong before space' => ["{*{% x %} a*}\n", "{*{% x %} a*}\n"],
            'emphasis before space' => ["{/{% x %} a/}\n", "{/{% x %} a/}\n"],
            'two trailing comments' => ["{*a\n{% x %}{% y %}*}\n", "{*a\n{% x %}{% y %}*}\n"],
            'two leading comments' => ["{*{% x %}{% y %} a*}\n", "{*{% x %}{% y %} a*}\n"],
            'strong in a list' => ["- {*a\n  {% x %}*}\n", "- {*a\n  {% x %}*}\n"],
            'emphasis in a quote' => ["> {/a {% x %}/}\n", "> {/a {% x %}/}\n"],
            'safe trailing comment keeps bare form' => ["{*a{% x %}*}\n", "*a{% x %}*\n"],
            'safe leading comment keeps bare form' => ["{*{% x %}a*}\n", "*{% x %}a*\n"],
        ];
    }

    #[DataProvider('forms')]
    public function testFormatKeepsThePairTree(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame([], $result->diagnostics);
        self::assertSame($expected, $result->source);
        self::assertSame($expected, Carve::format($expected));
        self::assertTrue(TreeEquality::equal(
            json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR),
            json_decode(Carve::toJson($expected), true, 512, JSON_THROW_ON_ERROR),
        ));
        self::assertSame(Carve::toHtml($source), Carve::toHtml($expected));
    }
}
