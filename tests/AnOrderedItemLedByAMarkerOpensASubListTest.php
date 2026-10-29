<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A list item whose content starts with a list marker opens a sub-list (CARVE-P0-007); the flat-list fast paths must not read it as text. Expected HTML is the spec model's.
 */
final class AnOrderedItemLedByAMarkerOpensASubListTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'ordered in ordered' => ["1. 1. p\n", "<ol>\n  <li>\n    <ol>\n      <li>p</li>\n    </ol>\n  </li>\n</ol>"];
        yield 'ordered in ordered with a sibling' => ["1. 1. p\n1. a\n", "<ol>\n  <li>\n    <ol>\n      <li>p</li>\n    </ol>\n  </li>\n  <li>a</li>\n</ol>"];
        yield 'alphabetical in ordered' => ["1. a) p\n2. b\n", "<ol>\n  <li>\n    <ol type=\"a\" data-delim=\")\">\n      <li>p</li>\n    </ol>\n  </li>\n  <li>b</li>\n</ol>"];
        yield 'start number' => ["2. 1. p\n", "<ol start=\"2\">\n  <li>\n    <ol>\n      <li>p</li>\n    </ol>\n  </li>\n</ol>"];
        yield 'parenthesis markers' => ["1) 1) p\n1) a\n", "<ol data-delim=\")\">\n  <li>\n    <ol data-delim=\")\">\n      <li>p</li>\n    </ol>\n  </li>\n  <li>a</li>\n</ol>"];
        yield 'loose bullet list with a parenthesis lead' => ["- p\n\n- 1) p\n", "<ul>\n  <li><p>p</p></li>\n  <li>\n    <ol data-delim=\")\">\n      <li>p</li>\n    </ol>\n  </li>\n</ul>"];
        yield 'loose bullet list with an alphabetical lead' => ["- p\n\n- a) p\n\ntail\n", "<ul>\n  <li><p>p</p></li>\n  <li>\n    <ol type=\"a\" data-delim=\")\">\n      <li>p</li>\n    </ol>\n  </li>\n</ul>\n<p>tail</p>"];
    }

    #[DataProvider('documents')]
    public function testTheLeadMarkerOpensASubList(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
