<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A refused `+` on a quote's marker line opens no paragraph, also in a quote nested at the document level, so a column-0 line after it starts its own block (PART 9 §17 L3). Expected HTML is the spec model's.
 */
final class ARefusedPlusInADocumentLevelQuoteOpensNoParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'nested quote, then an item that takes a quote' => ["> > +\n- p\n  > q\n", "<blockquote>\n  <blockquote>\n\n  </blockquote>\n</blockquote>\n<ul>\n  <li>p\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>"];
        yield 'nested quote, then an item that takes a heading' => ["> > +\n- p\n  # q\n", "<blockquote>\n  <blockquote>\n\n  </blockquote>\n</blockquote>\n<ul>\n  <li>p\n    <h1 id=\"q\">q</h1>\n  </li>\n</ul>"];
        yield 'three quotes deep' => ["> > > +\n- p\n  > q\n", "<blockquote>\n  <blockquote>\n    <blockquote>\n\n    </blockquote>\n  </blockquote>\n</blockquote>\n<ul>\n  <li>p\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>"];
        yield 'nested quote, then a paragraph' => ["> > +\np\n  > q\n", "<blockquote>\n  <blockquote>\n\n  </blockquote>\n</blockquote>\n<p>p</p>\n<blockquote><p>q</p></blockquote>"];
    }

    #[DataProvider('documents')]
    public function testTheLineAfterTheQuoteIsNotLazy(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
