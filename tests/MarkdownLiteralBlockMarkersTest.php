<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownLiteralBlockMarkersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'list' => ["- one\n  ::: note\n  b\n  :::\n", "<ul>\n  <li>one\n::: note\nb\n:::</li>\n</ul>\n"],
            'quote' => ["> one\n> ::: note\n> b\n> :::\n", "<blockquote><p>one\n::: note\nb\n:::</p></blockquote>\n"],
            'short colon run' => ["- one\n  :: note\n", "<ul>\n  <li>one\n:: note</li>\n</ul>\n"],
            'caret after image' => ["![x](u)\n\n^ c\n", "<img src=\"u\" alt=\"x\">\n<p>^ c</p>\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testLiteralBlockMarkersRemainText(string $markdown, string $expectedHtml): void
    {
        $carve = (new MarkdownToCarve())->convert($markdown);

        self::assertSame(preg_replace('/\n\z/', '', $expectedHtml), CarveConverter::create()->convert($carve));
    }
}
