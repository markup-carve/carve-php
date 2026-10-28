<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StructuralAttributeOrderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'reference below use' => ["[a][r]{.c #b}\n\n[r]: /u {:x #a}\n", '<a href="/u" lang="x" id="b" class="c">a</a>'];
        yield 'reference above use' => ["[r]: /u {:x #a}\n\n[a][r]{.c #b}\n", '<a href="/u" lang="x" id="b" class="c">a</a>'];
        yield 'cell colspan' => ["|{x} |<|\n", '<td colspan="2" x=""></td>'];
        yield 'cell rowspan' => ["|{x} a|\n|^|\n", '<td rowspan="2" x="">a</td>'];
    }

    #[DataProvider('cases')]
    public function testStructuralAttributesPrecedeAuthoredAttributes(string $source, string $fragment): void
    {
        self::assertStringContainsString($fragment, (new CarveConverter())->convert($source));
    }
}
