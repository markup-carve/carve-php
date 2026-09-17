<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A marker at the column a nested list opened at is that list's next item,
 * whatever a deeper list did in between (markup-carve/carve-php#2140). The
 * item collector used to hand the line back to the parent, which started a
 * second list beside the first.
 */
class AMarkerAtTheCollectedColumnContinuesItsListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a deeper list between two items' => [
                "- a\n  - b\n\n    - c\n\n  - d\n",
                '<ul><li>a<ul><li><p>b</p><ul><li>c</li></ul></li><li><p>d</p></li></ul></li></ul>',
            ],
            'a loose outer item too' => [
                "- a\n\n  - b\n\n    - c\n\n  - d\n",
                '<ul><li>a<ul><li><p>b</p><ul><li>c</li></ul></li><li><p>d</p></li></ul></li></ul>',
            ],
            'an ordered list at the inner level' => [
                "- a\n  1. b\n\n     - c\n\n  2. d\n",
                '<ul><li>a<ol><li><p>b</p><ul><li>c</li></ul></li><li><p>d</p></li></ol></li></ul>',
            ],
            'three levels' => [
                "- a\n  - b\n    - c\n\n      - d\n\n    - e\n",
                '<ul><li>a<ul><li>b<ul><li><p>c</p><ul><li>d</li></ul></li><li><p>e</p></li></ul></li></ul></li></ul>',
            ],
            // BOUND: with no deeper list the items already shared one list.
            'no deeper list' => [
                "- a\n  - b\n\n  - d\n",
                '<ul><li>a<ul><li><p>b</p></li><li><p>d</p></li></ul></li></ul>',
            ],
            // BOUND: prose back at the item's column is the item's own second
            // paragraph, not a list item, and still goes to the parent.
            'prose at the collected column' => [
                "- a\n  - b\n\n    - c\n\n  text\n",
                '<ul><li><p>a</p><ul><li>b<ul><li>c</li></ul></li></ul><p>text</p></li></ul>',
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheMarkerStaysInItsList(string $source, string $html): void
    {
        $rendered = (new CarveConverter())->convert($source);

        $this->assertSame($html, (string)preg_replace('/\n\s*/', '', $rendered));
    }
}
