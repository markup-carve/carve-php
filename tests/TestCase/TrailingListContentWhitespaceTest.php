<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrailingListContentWhitespaceTest extends TestCase
{
    #[DataProvider('sources')]
    public function testDefaultConverterDropsTrailingWhitespace(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source));
    }

    public static function sources(): iterable
    {
        yield 'loose bullet with tab' => ["- a\n\n- b\t\n", "<ul>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ul>\n"];
        yield 'ordered markers with tabs' => ["1. a\t\n2. b\t\n", "<ol>\n  <li>a</li>\n  <li>b</li>\n</ol>\n"];
        yield 'task marker with tab' => ["- [x] done\t\n", "<ul class=\"task-list\">\n  <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled aria-label=\"done\"> done</li>\n</ul>\n"];
    }
}
