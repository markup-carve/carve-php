<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence at an outer item's content column, below a nested list in a note opened on a list item's
 * marker line, stays in that outer item (carve-php#4243, closer-nested-closer-760).
 */
class AFenceUnderAnItemNoteNestedListStaysInItsItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'bullet sub-list' => [
            "- [^b]: inner\n    - c2\n      - c3\n      ```\n      code\n      ```\n\n[^b]\n",
            '<ul><li>c2<ul><li>c3</li></ul><pre><code>code</code></pre></li></ul>',
        ];

        yield 'ordered sub-list' => [
            "- [^b]: inner\n    - c2\n      1. c3\n      ~~~\n      code\n      ~~~\n\n[^b]\n",
            '<ul><li>c2<ol><li>c3</li></ol><pre><code>code</code></pre></li></ul>',
        ];

        yield 'three levels, fence at the outer item' => [
            "- [^b]: inner\n    - c2\n      - c3\n        - c4\n      ```\n      code\n      ```\n\n[^b]\n",
            '<ul><li>c2<ul><li>c3<ul><li>c4</li></ul></li></ul><pre><code>code</code></pre></li></ul>',
        ];

        yield 'fence at the note body column' => [
            "- [^b]: inner\n    - c2\n      - c3\n    ```\n    code\n    ```\n\n[^b]\n",
            '<ul><li>c2<ul><li>c3</li></ul></li></ul><pre><code>code</code></pre>',
        ];
    }

    #[DataProvider('shapes')]
    public function testTheFenceStaysWithTheItemItReaches(string $source, string $body): void
    {
        $html = (string)preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], (new CarveConverter())->convert($source));
        $this->assertStringContainsString('<li id="fn1"><p>inner</p>' . $body, $html);
    }
}
