<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TabAfterListMarkerStaysContentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'bullet and quote' => ["- \t> a", "<ul>\n  <li>\t&gt; a</li>\n</ul>\n"];
        yield 'ordered and quote' => ["1. \t> a", "<ol>\n  <li>\t&gt; a</li>\n</ol>\n"];
        yield 'bullet and heading' => ["- \t# h", "<ul>\n  <li>\t# h</li>\n</ul>\n"];
        yield 'space before quote' => ['- > a', "<ul>\n  <li>\n    <blockquote><p>a</p></blockquote>\n  </li>\n</ul>\n"];
    }

    #[DataProvider('cases')]
    public function testTabAfterRequiredSpaceDoesNotExposeBlockOpener(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source));
    }
}
