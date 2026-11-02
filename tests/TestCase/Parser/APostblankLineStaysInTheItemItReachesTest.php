<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * After a blank in a description body, a line that misses a nested list item
 * but reaches an outer item or description stays there (PART 0 S2).
 */
class APostblankLineStaysInTheItemItReachesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'lead item' => [
            ":: t\n:  - outer\n     - head\n\n     tail\n",
            '<dl><dt>t</dt><dd><ul><li><p>outer</p><ul><li>head</li></ul><p>tail</p></li></ul></dd></dl>',
        ];

        yield 'lead item after lazy line' => [
            ":: t\n:  - outer\n     - head\n     p\n\n     tail\n",
            "<dl><dt>t</dt><dd><ul><li><p>outer</p><ul><li>head\np</li></ul><p>tail</p></li></ul></dd></dl>",
        ];

        yield 'ordered lead item' => [
            ":: t\n:  1. outer\n      - head\n\n      tail\n",
            '<dl><dt>t</dt><dd><ol><li><p>outer</p><ul><li>head</li></ul><p>tail</p></li></ol></dd></dl>',
        ];

        yield 'middle item of a lead' => [
            ":: t\n:  - outer\n     - head\n       - deep\n\n       tail\n",
            '<dl><dt>t</dt><dd><ul><li>outer<ul><li><p>head</p><ul><li>deep</li></ul><p>tail</p></li></ul></li></ul></dd></dl>',
        ];

        yield 'item after a paragraph' => [
            ":: t\n: d\n\n  - outer\n    - head\n\n    tail\n",
            '<dl><dt>t</dt><dd><p>d</p><ul><li><p>outer</p><ul><li>head</li></ul><p>tail</p></li></ul></dd></dl>',
        ];

        yield 'item in a nested description' => [
            ":: T\n: D\n  :: t\n  :  1. outer\n        - head\n\n        tail\n",
            '<dl><dt>T</dt><dd><p>D</p><dl><dt>t</dt><dd><ol><li><p>outer</p><ul><li>head</li></ul><p>tail</p></li></ol></dd></dl></dd></dl>',
        ];

        yield 'nested description body' => [
            ":: T\n: D\n  :: t\n  :  1. outer\n        - head\n\n     # h\n",
            '<dl><dt>T</dt><dd><p>D</p><dl><dt>t</dt><dd><ol><li>outer<ul><li>head</li></ul></li></ol><h1 id="h">h</h1></dd></dl></dd></dl>',
        ];

        yield 'missed item control' => [
            ":: t\n:  - outer\n     - head\n\n    tail\n",
            '<dl><dt>t</dt><dd><ul><li>outer<ul><li>head</li></ul></li></ul><p>tail</p></dd></dl>',
        ];
    }

    #[DataProvider('examples')]
    public function testRenderedHtml(string $source, string $expected): void
    {
        $html = trim(CarveConverter::create()->convert($source));
        $compact = preg_replace(['/>\s+</', '/\s+</'], ['><', '<'], $html);

        $this->assertSame($expected, $compact);
    }
}
