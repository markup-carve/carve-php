<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A note head inside item and quote prefixes measures its closer in the same source frame (carve#5787).
 */
final class AQuotedNoteHeadCommentUsesSourceColumnsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'valid item quote note' => ["- > [^q]: {%c\n  >        %}\n\nx[^q]\n", ''];
        yield 'closer at the note body column is refused' => ["- > [^q]: {%c\n  >   %}\n\nx[^q]\n", "{%c\n%}"];
        yield 'valid two quote levels' => ["- > > [^q]: {%c\n  > >        %}\n\nx[^q]\n", ''];
    }

    #[DataProvider('shapes')]
    public function testTheNoteBodyKeepsOnlyRefusedText(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertSame(1, preg_match('/<li id="fn1">\n      <p>(.*?)<a href="#fnref1"/s', $html, $match));
        self::assertSame($expected, $match[1]);
        self::assertStringContainsString('<blockquote>', $html);
    }
}
