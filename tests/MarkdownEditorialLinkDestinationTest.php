<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownEditorialLinkDestinationTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function cases(): array
    {
        return [
            'bare destination' => ['[x](u-})', '[x](u-})', '<a href="u-}">x</a>'],
            'angle destination' => ['[x](<u-}>)', '[x](u-})', '<a href="u-}">x</a>'],
        ];
    }

    #[DataProvider('cases')]
    public function testLinkCloserStaysInsideEditorialDeletion(string $link, string $writtenLink, string $linkHtml): void
    {
        $markdown = '<del class="critic-delete">a ' . $link . ' b</del>';
        $carve = (new MarkdownToCarve())->convert($markdown);

        self::assertSame('{-a ' . $writtenLink . ' b-}', $carve);
        self::assertSame(preg_replace('/\n\z/', '', '<p><del>a ' . $linkHtml . ' b</del></p>' . "\n"), CarveConverter::create()->convert($carve));
    }
}
