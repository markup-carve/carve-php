<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotTableCaptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function captionProvider(): iterable
    {
        $row = '<tbody><tr><td>a</td><td>b</td></tr></tbody>';

        yield 'multiline caption' => ["| a | b |\n\n^ With a _caption_\nand another line.\n", '<table><caption>With a <em>caption</em> and another line.</caption>' . $row . '</table>'];
        yield 'quoted caption' => ["> | a | b |\n>\n> ^ Quoted _caption_\n", '<blockquote><table><caption>Quoted <em>caption</em></caption>' . $row . '</table></blockquote>'];
        yield 'list caption' => ["- | a | b |\n\n  ^ Listed _caption_\n", '<ul><li><table><caption>Listed <em>caption</em></caption>' . $row . '</table></li></ul>'];
        yield 'tab after marker' => ["| a | b |\n\n^\tCaption\n", '<table><caption>Caption</caption>' . $row . '</table>'];
        yield 'superscript in caption' => ["| a | b |\n\n^ Power ^two^\n", '<table><caption>Power <sup>two</sup></caption>' . $row . '</table>'];
        yield 'escaped marker' => ["| a | b |\n\n\\^ Literal _caret_\n", '<table>' . $row . '</table><p>^ Literal <em>caret</em></p>'];
        yield 'code marker' => ["| a | b |\n\n```\n^ code\n```\n", '<table>' . $row . '</table><pre><code>^ code </code></pre>'];
        yield 'caret paragraph' => ["^ Plain _caret_\n", '<p>^ Plain <em>caret</em></p>'];
        yield 'superscript paragraph' => ["^two^\n", '<p><sup>two</sup></p>'];
        yield 'caption without blank line' => ["| a | b |\n^ Caption\n", '<table><caption>Caption</caption>' . $row . '</table>'];
        yield 'image continuation stays literal' => ["![img](x.png)\n^ text\n", '<p><img src="x.png" alt="img"> ^ text</p>'];
        yield 'code successor stays literal' => ["```\ncode\n```\n\n^ text\n", '<pre><code>code </code></pre><p>^ text</p>'];
        yield 'quote successor stays literal' => ["> quote\n\n^ text\n", '<blockquote><p>quote</p></blockquote><p>^ text</p>'];
        yield 'lazy quote superscript closer' => ["> ^a\n>^ b\n", '<blockquote><p><sup>a &gt;</sup> b</p></blockquote>'];
    }

    #[DataProvider('captionProvider')]
    public function testCaptionStructure(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));
        $html = preg_replace('/>\s+</', '><', trim($html));
        $html = preg_replace('/\s+/', ' ', $html);
        self::assertSame($expected, $html);
    }
}
