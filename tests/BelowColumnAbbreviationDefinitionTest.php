<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BelowColumnAbbreviationDefinitionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documentOwnedDefinitions(): iterable
    {
        yield 'list item' => ["- n\n *[A]: b\n\nA\n", "<ul>\n  <li>n</li>\n</ul>\n<p><abbr title=\"b\">A</abbr></p>"];
        yield 'description' => [":: t\n: n\n *[A]: b\n\nA\n", "<dl>\n  <dt>t</dt>\n  <dd>n</dd>\n</dl>\n<p><abbr title=\"b\">A</abbr></p>"];
    }

    #[DataProvider('documentOwnedDefinitions')]
    public function testBelowColumnAbbreviationDefines(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testDefinitionAtItemContentColumnStaysText(): void
    {
        $source = "- n\n  *[A]: b\n\nA\n";

        self::assertSame("<ul>\n  <li>n\n*[A]: b</li>\n</ul>\n<p>A</p>", CarveConverter::create()->convert($source));
    }
}
