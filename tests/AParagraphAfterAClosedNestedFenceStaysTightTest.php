<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence a dedent ended in a nested item is written closed, and the tight
 * item's next block follows it with no blank line (CARVE-P11-074).
 */
final class AParagraphAfterAClosedNestedFenceStaysTightTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'corpus 853' => ["- - - ```\n    x\n    y\nl\n", "- - - ```\n      ```\n    x\n    y\n    l\n"];
        yield 'corpus 864' => ["- - - ~~~\n    x\ntail\n", "- - - ```\n      ```\n    x\n    tail\n"];
        yield 'third level below a paragraph' => ["- a\n  - b\n    - ```\n    x\n", "- a\n  - b\n    - ```\n      ```\n    x\n"];
        yield 'quoted fence' => ["- - > ```\n    x\n", "- - > ```\n    > ```\n    x\n"];
        yield 'list in a quote' => ["- - > - ```\n    x\n", "- - > - ```\n    >   ```\n    x\n"];
    }

    #[DataProvider('documents')]
    public function testTheFormatterAddsNoBlank(string $source, string $expected): void
    {
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($expected, $formatted);
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function openParagraphs(): iterable
    {
        yield 'sub-list paragraph' => ["- - - a\n\n    x\n", "- - - a\n\n    x\n"];
        yield 'quoted paragraph' => ["- - > q\n\n    x\n", "- - > q\n\n    x\n"];
    }

    #[DataProvider('openParagraphs')]
    public function testAnOpenParagraphAboveKeepsTheBlank(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::toCarve($source));
    }
}
