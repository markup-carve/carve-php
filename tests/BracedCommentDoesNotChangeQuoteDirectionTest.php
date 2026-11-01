<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BracedCommentDoesNotChangeQuoteDirectionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function comments(): array
    {
        return [
            'after closing double quote' => ['"a-"{%%}', '<p>“a-”</p>'],
            'after closing single quote' => ["'a-'{%%}", '<p>‘a-’</p>'],
            'before a number' => ["'{%%}90s", '<p>’90s</p>'],
            'before an elision' => ["'{%%}tis", '<p>’tis</p>'],
            'before a quoted elision' => ["'{%%}em{%x%}'", '<p>‘em’</p>'],
            'two comments' => ['"a-"{% one %}{% two %}', '<p>“a-”</p>'],
            'escaped space after comment' => ['"a-"{%%}\\ x', '<p>“a-”&nbsp;x</p>'],
            'inside a quote host' => ['> "a-"{%%}', '<blockquote><p>“a-”</p></blockquote>'],
            'unclosed comment stays text' => ['"a-"{% open', '<p>“a-“{% open</p>'],
        ];
    }

    #[DataProvider('comments')]
    public function testCommentsAreTransparentToQuoteDirection(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
