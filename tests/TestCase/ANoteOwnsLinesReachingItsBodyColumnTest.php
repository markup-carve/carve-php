<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ANoteOwnsLinesReachingItsBodyColumnTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'bullet marker at note body' => ["- a\n  [^n]: e\n    * [ ] task\n", "<ul>\n  <li>a</li>\n</ul>\n"],
            'ordered item' => ["1. a\n   [^n]: e\n     - task\n", "<ol>\n  <li>a</li>\n</ol>\n"],
            'nested definition' => ["- a\n  [^n]: e\n    [^n]: e\n    q\n", "<ul>\n  <li>a</li>\n</ul>\n"],
            'two item levels' => ["- a\n  - b\n    [^n]: e\n      * [ ] task\n", "<ul>\n  <li>a\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n</ul>\n"],
            'three item levels' => ["- a\n  - b\n    - c\n      [^n]: e\n        * [ ] task\n", "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>c</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n"],
            'one column short' => ["- a\n  [^n]: e\n   * [ ] task\n", "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"task\"> task</li>\n    </ul>\n  </li>\n</ul>\n"],
            'quote reaches its marker' => ["> > [^n]: e\n> >   k\ne\n", "<blockquote>\n  <blockquote>\n\n  </blockquote>\n</blockquote>"],
            'table below note after blank' => ["- a\n    [^n]: e\n\n     ||\n", "<ul>\n  <li>a\n    <table>\n      <tbody>\n        <tr><td></td></tr>\n      </tbody>\n    </table>\n  </li>\n</ul>\n"],
            'table below note marker run' => ["- a\n  [^n]: e\n    * [ ] task\n   q\n   | a |\n", "<ul>\n  <li>a\n    <table>\n      <tbody>\n        <tr><td>a</td></tr>\n      </tbody>\n    </table>\n  </li>\n</ul>\n"],
            'description releases line below quoted note' => [":: term\n:  detail\n  * [ ] task\n    [^n]: note\n      > quote\n q  \n :  detail\n", "<dl>\n  <dt>term</dt>\n  <dd>detail\n* [ ] task</dd>\n</dl>"],
            'below column marker stays literal' => [":: t\n:  d\n  [^f]: n\ntail\n\nSee[^f]\n", "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>See<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\ntail<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n"],
            'open quoted paragraph' => ["> p\n- a\n", "<blockquote><p>p\n- a</p></blockquote>\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testDefinitionExtent(string $source, string $expected): void
    {
        self::assertSame($expected, (new HtmlRenderer())->render((new BlockParser())->parse($source)));
    }
}
