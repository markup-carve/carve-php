<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lazy comment's continuations use source columns after quote prefixes (CARVE-P2-035, carve#5203).
 */
final class ALazyBracedCommentUsesSourceColumnsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function shapes(): iterable
    {
        yield 'list, one column past' => ["- pre\n{% b\n b %}\n", true];
        yield 'list, equal column' => ["- pre\n{% b\nb %}\n", false];
        yield 'list, indented lazy opener' => ["- pre\n {% b\n  b %}\n", true];
        yield 'list, indented lazy closer at opener' => ["- pre\n {% b\n b %}\n", false];
        yield 'quote, one column after prefix' => ["> pre\n{% b\n>  b %}\n", true];
        yield 'quote, equal column after prefix' => ["> pre\n{% b\n> b %}\n", false];
        yield 'footnote, one column past' => ["[^n]: pre\n{% b\n b %}\n\nx[^n]\n", true];
        yield 'footnote, equal column' => ["[^n]: pre\n{% b\nb %}\n\nx[^n]\n", false];
        yield 'div, one column past' => ["::: note\npre\n{% b\n b %}\n:::\n", true];
        yield 'div, equal column' => ["::: note\npre\n{% b\nb %}\n:::\n", false];
        yield 'nested list, one column past' => ["- outer\n  - pre\n{% b\n b %}\n", true];
        yield 'nested list, equal column' => ["- outer\n  - pre\n{% b\nb %}\n", false];
        yield 'comment opened on a list marker line' => ["- {% b\n  b %}\n", false];
        yield 'comment opened on a quoted list marker line' => ["> - {% b\n>   b %}\n", false];
    }

    #[DataProvider('shapes')]
    public function testTheRunUsesItsWrittenColumns(string $source, bool $erased): void
    {
        $html = (new CarveConverter())->convert($source);

        if ($erased) {
            self::assertStringNotContainsString('{% b', $html);
        } else {
            self::assertStringContainsString("{% b\nb %}", $html);
        }
    }
}
