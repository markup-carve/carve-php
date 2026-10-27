<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownLiteralColonFenceInContainersTest extends TestCase
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
        ];
    }

    #[DataProvider('cases')]
    public function testLiteralColonRunsRemainText(string $markdown, string $expectedHtml): void
    {
        $carve = (new MarkdownToCarve())->convert($markdown);

        self::assertSame($expectedHtml, CarveConverter::create()->convert($carve));
    }
}
