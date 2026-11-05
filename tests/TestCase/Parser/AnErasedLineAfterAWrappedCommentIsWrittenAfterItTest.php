<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A braced comment that starts a paragraph line keeps its line breaks in fmt (carve#5189), so an erased `%%` line
 * after it is placed by the lines the comment spans too.
 */
final class AnErasedLineAfterAWrappedCommentIsWrittenAfterItTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'in a note' => [
            "[^n]: pre\n  {% a\n   b %}\n%% c\nx[^n]\n",
            "[^n]: pre\n  {% a\n   b %}\n%% c\n  x[^n]\n",
        ];

        yield 'at the root' => [
            "pre\n{% a\n b\n c %}\n%% d\nnext\n",
            "pre\n{% a\n b\n c %}\n%% d\nnext\n",
        ];
    }

    #[DataProvider('documents')]
    public function testTheLineIsWrittenAfterTheComment(string $source, string $expected): void
    {
        $writer = static fn (string $text): string => CarveConverter::carve(new BlockParser(false, false, false, true))->convert($text);
        $once = $writer($source);

        self::assertSame($expected, $once);
        self::assertSame($once, $writer($once));
    }
}
