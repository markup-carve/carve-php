<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANoteAttachedListEndsAtAnotherMarkerKindTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'different bullet' => ["[^a]: +\n* li\n- sib\n\nsee[^a]\n", "<ul>\n  <li>sib</li>\n</ul>\n<p>see"];
        yield 'ordered after child fence blank' => ["[^a]: +\n- li\n  ```\n\n1. sib\n\nsee[^a]\n", "<ol>\n  <li>sib</li>\n</ol>\n<p>see"];
    }

    #[DataProvider('documents')]
    public function testAnotherKindStartsOutsideTheNote(string $source, string $prefix): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertStringStartsWith($prefix, $html);
        self::assertSame(1, substr_count($html, '<li>sib</li>'));
    }
}
