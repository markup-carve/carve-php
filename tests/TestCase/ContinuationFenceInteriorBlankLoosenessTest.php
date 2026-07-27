<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A blank line INSIDE a fenced code block is verbatim content, not a
 * list-loosening separator. The compact-list looseness scan
 * (subContentHasLooseningBlank) walked the item's sub-content lines without
 * tracking fences, so an interior blank in a continuation fence wrongly
 * loosened the list. A blank AFTER the fence closes still loosens against a
 * following paragraph. Matches carve-rs / carve-js (carve#326 case C).
 */
class ContinuationFenceInteriorBlankLoosenessTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testInteriorFenceBlankDoesNotLoosen(): void
    {
        $html = $this->converter->convert("- text\n\n  ```\n  a\n\n  b\n  ```\n- c");
        $this->assertSame(
            "<ul>\n  <li>text\n    <pre><code>a\n\nb\n</code></pre>\n  </li>\n  <li>c</li>\n</ul>\n",
            $html,
        );
    }

    public function testBlankAfterFenceStillLoosens(): void
    {
        $html = $this->converter->convert("- text\n\n  ```\n  a\n  ```\n\n- c");
        $this->assertStringContainsString('<li><p>c</p></li>', $html);
    }

    public function testInteriorBlankNoInterFenceBlankStaysTight(): void
    {
        $html = $this->converter->convert("- text\n\n  ```\n  a\n  b\n  ```\n- c");
        $this->assertStringContainsString('<li>c</li>', $html);
        $this->assertStringNotContainsString('<li><p>c</p></li>', $html);
    }
}
