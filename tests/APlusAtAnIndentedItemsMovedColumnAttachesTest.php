<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An indented list reads as if written at column 0 (PART 0 S3), so a lone `+`
 * at column 0 stands at its moved marker column and attaches the next block.
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
            self::assertSame(
                CarveConverter::create()->convert($source),
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
