<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownNativeDeletionWhitespaceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'space only' => ['<del> </del>', '<p><s> </s></p>' . "\n"],
            'leading space' => ['<del> x</del>', '<p><s> x</s></p>' . "\n"],
            'trailing space' => ['<del>x </del>', '<p><s>x </s></p>' . "\n"],
            's tag' => ['<s> y </s>', '<p><s> y </s></p>' . "\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testWhitespaceInNativeStrikeTags(string $markdown, string $expectedHtml): void
    {
        $carve = (new MarkdownToCarve())->convert($markdown);

        self::assertSame($expectedHtml, CarveConverter::create()->convert($carve));
    }
}
