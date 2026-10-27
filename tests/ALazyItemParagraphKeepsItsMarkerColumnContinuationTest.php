<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ALazyItemParagraphKeepsItsMarkerColumnContinuationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'bullet paragraph' => ["- q\nr\n+\nb\n", "<ul>\n  <li>q\nr\n    b\n  </li>\n</ul>\n"];
        yield 'ordered paragraph' => ["1. q\nr\n+\nb\n", "<ol>\n  <li>q\nr\n    b\n  </li>\n</ol>\n"];
        yield 'attached heading' => ["- q\nr\n+\n# h\n", "<ul>\n  <li>q\nr\n    <h1 id=\"h\">h</h1>\n  </li>\n</ul>\n"];
    }

    #[DataProvider('cases')]
    public function testMarkerAttachesAfterALazyParagraph(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected), CarveConverter::create()->convert($source));
    }
}
