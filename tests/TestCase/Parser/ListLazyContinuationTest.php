<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Parser;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * List lazy continuation (matches canonical djot.js and carve-js): a non-indented
 * line with no blank line before it folds into the item's lead paragraph when it is
 * plain paragraph text; a blank line, or a line that starts a block, ends the list.
 */
class ListLazyContinuationTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testPlainLineFoldsIntoItem(): void
    {
        $djot = "- item\nlazy";
        $expected = "<ul>\n  <li>item\nlazy</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLazyLineFoldsIntoLastItem(): void
    {
        $djot = "- a\n- b\nlazy";
        $expected = "<ul>\n  <li>a</li>\n  <li>b\nlazy</li>\n</ul>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLazyFoldsInOrderedList(): void
    {
        $djot = "1. a\nlazy";
        $expected = "<ol>\n  <li>a\nlazy</li>\n</ol>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testBlankLineEndsList(): void
    {
        $djot = "- a\n\nlazy";
        $expected = "<ul>\n  <li>a</li>\n</ul>\n<p>lazy</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testHeadingLineEndsList(): void
    {
        $djot = "- a\n# H";
        $expected = "<ul>\n  <li>a</li>\n</ul>\n<section id=\"h\">\n  <h1>H</h1>\n</section>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testFencedCodeLineEndsList(): void
    {
        $djot = "- a\n```\nx";
        $expected = "<ul>\n  <li>a</li>\n</ul>\n<pre><code>x\n</code></pre>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }
}
