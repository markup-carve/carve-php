<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lone `+` at column 0 below an indented list is refused and reads like a letter (carve#4409).
 */
final class APlusAtAnIndentedItemsMovedColumnAttachesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function indentations(): array
    {
        return ['one space' => [' '], 'two spaces' => ['  '], 'three spaces' => ['   '], 'tab' => ["\t"]];
    }

    #[DataProvider('indentations')]
    public function testItRendersAsTheColumnZeroSpelling(string $indent): void
    {
        foreach (["- a\n+\n> q\n", "- a\n  - b\n+\n|= a |\n- after\n", "1. a\n+\n```\nc\n```\nafter\n"] as $source) {
            $indented = (string)preg_replace('/^(?= *(?:-|1\.) )/m', $indent, $source);
            $letter = (string)preg_replace('/^\+$/m', 'z', $indented);
            self::assertSame(
                str_replace('z', '+', CarveConverter::create()->convert($letter)),
                CarveConverter::create()->convert($indented),
                $indented,
            );
        }
    }

    public function testAQuoteAfterADeadListStillRefusesIt(): void
    {
        self::assertSame(
            "<ol>\n  <li>o</li>\n</ol>\n<blockquote><p>quote\n+\n- item</p></blockquote>",
            CarveConverter::create()->convert("1. o\n > quote\n+\n- item\n"),
        );
    }
}
