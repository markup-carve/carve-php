<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A marker-line `%%%` span closed below the item's column is still an invisible lead (PART 9 §28, §17 L1b).
 */
final class ACommentLeadClosedBelowTheColumnKeepsTheListTightTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'closer at column 0' => ["- %%%\n%%%\n\n  c\n", "<ul>\n  <li>c</li>\n</ul>"];
        yield 'closer at column 1' => ["- %%%\n %%%\n\n  c\n", "<ul>\n  <li>c</li>\n</ul>"];
        yield 'payload line' => ["- %%%\n  n\n%%%\n\n  c\n", "<ul>\n  <li>c</li>\n</ul>"];
        yield 'blank in the payload' => ["- %%%\n  n\n\n%%%\n\n  c\n", "<ul>\n  <li>c</li>\n</ul>"];
        yield 'empty span with a blank' => ["- %%%\n\n%%%\n\n  c\n", "<ul>\n  <li>c</li>\n</ul>"];
        yield 'wider fence' => ["- %%%%\n  n\n%%%%\n\n  c\n", "<ul>\n  <li>c</li>\n</ul>"];
        yield 'closer with a tail' => ["- %%%\n%%% end\n\n  c\n", "<ul>\n  <li>c</li>\n</ul>"];
        yield 'a visible paragraph above still loosens' => ["- %%%\n%%%\n  x\n\n  c\n", "<ul>\n  <li><p>x</p>\n    <p>c</p>\n  </li>\n</ul>"];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
