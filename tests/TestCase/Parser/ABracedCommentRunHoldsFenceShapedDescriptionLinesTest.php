<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ABracedCommentRunHoldsFenceShapedDescriptionLinesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runs(): iterable
    {
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\nplain</dd>\n</dl>";

        yield 'fence opener spelling in held run' => [":: t\n: {% a\n   %%%\n   z %}\nplain\n", $expected];
        yield 'fence tail spelling in held run' => [":: t\n: {% a\n    %%% x\n    z %}\nplain\n", $expected];
        yield 'longer fence spelling in held run' => [":: t\n: {% a\n     %%%%\n     z %}\nplain\n", $expected];
        yield 'plain held line control' => [":: t\n: {% a\n   b\n   z %}\nplain\n", $expected];
        yield 'refused run does not hold a fence line' => [
            ":: t\n: {% a\n %%%\n z %}\nplain\n",
            "<dl>\n  <dt>t</dt>\n  <dd>{% a</dd>\n</dl>",
        ];
    }

    #[DataProvider('runs')]
    public function testFenceShapedLineKeepsItsActualOwnership(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
