<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownEscapedFootnoteCloserTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'caret only' => ['see [^\\].', '<p>see [^].</p>' . "\n"],
            'longer label' => ['see [^foo\\].', '<p>see [^foo].</p>' . "\n"],
            'code remains opaque' => ['`[^\\]`', '<p><code>[^\\]</code></p>' . "\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testEscapedCloserKeepsItsMarkdownMeaning(string $markdown, string $expectedHtml): void
    {
        $carve = (new MarkdownToCarve())->convert($markdown);

        self::assertSame(preg_replace('/\n\z/', '', $expectedHtml), CarveConverter::create()->convert($carve));
    }
}
