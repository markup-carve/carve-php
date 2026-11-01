<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An open comment span tests its closer before ownership (PART 0 S1, carve#4752).
 */
final class AnUnmarkedCloserEndsAQuotedCommentSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a same-width payload at depth 2 is no closer' => [
            "> > %%%%\n> > %%% in\n%%%%\n> more\n",
            '<blockquote><blockquote></blockquote><p>more</p></blockquote>',
        ];

        yield 'a marked line short of the depth ends the span first' => [
            "> > %%%\n> x\n%%%\n",
            '<blockquote><blockquote></blockquote><p>x</p></blockquote>',
        ];

        yield 'a closer with text' => ["> %%%\n> s\n%%% tail\nafter\n", '<blockquote></blockquote><p>after</p>'];
        yield 'a blank line releases the span' => ["> %%%\n> s\n\n%%%\n", '<blockquote><p>s</p></blockquote>'];
        yield 'another width closes nothing' => ["> %%%\n> s\n%%%%\n", '<blockquote><p>s</p></blockquote>'];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        $html = (string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source));

        self::assertSame($expected, trim($html), $source);
    }
}
