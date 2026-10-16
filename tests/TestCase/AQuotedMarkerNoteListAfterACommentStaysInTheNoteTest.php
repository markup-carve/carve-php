<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AQuotedMarkerNoteListAfterACommentStaysInTheNoteTest extends TestCase
{
    public function testCommentEndsParagraphBeforeNoteList(): void
    {
        $source = "> - [^n]: text\n>     - child\n>     %% k\n>       - deep\nafter [^n]\n";
        $expected = '<blockquote><ul><li></li></ul></blockquote><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>';
        $converter = new CarveConverter();
        self::assertSame(preg_replace('/\s+/', '', $expected), preg_replace('/\s+/', '', $converter->convert($source)));
        $codec = new AstCodec();
        $tree = $codec->encode($converter->parse($source));
        self::assertSame($tree, $codec->encode($codec->decodeJson(json_encode($tree, JSON_THROW_ON_ERROR))));
    }

    public function testMarkerWithoutCommentRemainsParagraphText(): void
    {
        $source = "> - [^n]: text\n>     - child\n>       - deep\nafter [^n]\n";
        $converter = new CarveConverter();
        self::assertStringContainsString('<p>text - child - deep', preg_replace('/\s+/', ' ', $converter->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function noteExtentCases(): iterable
    {
        yield 'b007' => [
            "- [^n]: text\n    - child\n    %%%\n    k\n    %%%\n      - deep\nafter [^n]\n",
            '<ul><li></li></ul><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b083' => [
            "1. [^n]: text\n     more\n     %%%\n     k\n     %%%\n      - deep\nafter [^n]\n",
            '<ol><li></li></ol><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text more</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b159' => [
            "> 1. [^n]: text\n>      - child\n>      %%%\n>      k\n>      %%%\n>      - deep\nafter [^n]\n",
            '<blockquote><ol><li></li></ol></blockquote><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b003' => [
            "- [^n]: text\n    - child\n    %% k\n     - deep\nafter [^n]\n",
            '<ul><li></li></ul><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b004' => [
            "- [^n]: text\n    - child\n    %% k\n    - deep\nafter [^n]\n",
            '<ul><li></li></ul><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b013' => [
            "- [^n]: text\n    - child\n    [r]: /u\n      - deep\nafter [^n]\n",
            '<ul><li></li></ul><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b018' => [
            "- [^n]: text\n    - child\n    {#x}\n      - deep\nafter [^n]\n",
            '<ul><li></li></ul><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul id="x"><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b027' => [
            "- [^n]: text\n    more\n    %% k\n      - deep\nafter [^n]\n",
            '<ul><li></li></ul><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text more</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b052' => [
            "1. [^n]: text\n     - child\n     %% k\n       - deep\nafter [^n]\n",
            '<ol><li></li></ol><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b063' => [
            "1. [^n]: text\n     - child\n     [r]: /u\n       - deep\nafter [^n]\n",
            '<ol><li></li></ol><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b093' => [
            "1. [^n]: text\n     more\n     {#x}\n       - deep\nafter [^n]\n",
            '<ol><li></li></ol><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text more</p><ul id="x"><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b104' => [
            "> - [^n]: text\n>     - child\n>     %% k\n>     - deep\nafter [^n]\n",
            '<blockquote><ul><li></li></ul></blockquote><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'b153' => [
            "> 1. [^n]: text\n>      - child\n>      %% k\n>       - deep\nafter [^n]\n",
            '<blockquote><ol><li></li></ol></blockquote><p>after<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>text - child</p><ul><li>deep</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];
    }

    #[DataProvider('noteExtentCases')]
    public function testMarkerReachingNoteBodyAfterBlockStaysInNote(string $source, string $expected): void
    {
        $actual = (new CarveConverter())->convert($source);
        self::assertSame(preg_replace('/\s+/', '', $expected), preg_replace('/\s+/', '', $actual));
    }
}
