<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AWrappedAttributeBeforeAQuotedNoteFirstBlockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'wrapped run' => ["> [^a]: +\n>   {.x\n>    .y}\n> para\n\nsee[^a]\n", 'x y'];
        yield 'three-line run' => ["> [^a]: +\n>   {.x\n>    .y\n>    #z}\n> para\n\nsee[^a]\n", 'x y" id="z'];
        yield 'one-line control' => ["> [^a]: +\n>   {.x .y}\n> para\n\nsee[^a]\n", 'x y'];
        yield 'tabbed continuation' => ["> [^a]: +\n>   {.x\n> \t.y}\n> para\n\nsee[^a]\n", 'x y'];
    }

    #[DataProvider('documents')]
    public function testTheRunLabelsTheAttachedParagraph(string $source, string $classes): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<blockquote>\n\n</blockquote>", $html);
        self::assertStringContainsString('<p class="' . $classes . '">para', $html);
        self::assertStringNotContainsString('<blockquote><p>para</p></blockquote>', $html);
    }
}
