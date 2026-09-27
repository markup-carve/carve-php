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

    public function testAuthoredFenceColumnsKeepInteriorBlanksOpaque(): void
    {
        foreach (['  ', '    ', '      ', "\t"] as $indent) {
            foreach (['```', '~~~'] as $fence) {
                $source = "- item\n\n" . $indent . $fence . "\n" . $indent . "a\n\n" . $indent . "b\n" . $indent . $fence . "\n";
                self::assertSame(
                    "<ul>\n  <li>item\n    <pre><code>a\n\nb\n</code></pre>\n  </li>\n</ul>\n",
                    $this->converter->convert($source),
                );
            }
        }
    }

    public function testParagraphAfterAnAuthoredFenceStillLoosens(): void
    {
        $source = "- item\n\n    ```\n    code\n    ```\n\n  paragraph\n";
        $html = $this->converter->convert($source);
        self::assertStringContainsString('<li><p>item</p>', $html);
        self::assertStringContainsString('<p>paragraph</p>', $html);
    }

    public function testUnclosedAuthoredFenceKeepsItsBlankAsCode(): void
    {
        $html = $this->converter->convert("- item\n\n    ```\n    code\n\n  paragraph\n");
        self::assertStringNotContainsString('<p>item</p>', $html);
        self::assertStringContainsString("<pre><code>code\n\nparagraph\n</code></pre>", $html);
    }

    public function testRawFenceInteriorBlankDoesNotLoosen(): void
    {
        $html = $this->converter->convert("- item\n\n    ```=html\n    <div>a\n\n    b</div>\n    ```\n");
        self::assertStringContainsString('<li>item', $html);
        self::assertStringNotContainsString('<p>item</p>', $html);
    }

    public function testFenceLookingParagraphContinuationsDoNotHideALooseningBlank(): void
    {
        foreach (
            [
                "- a\n  - s\n    t\n     ~~~\n\n  more\n",
                "- a\n  - sub\n     ```\n\n  more\n",
                "- a\n\n  > q\n    ```\n\n  more\n",
            ] as $source
        ) {
            $html = $this->converter->convert($source);
            self::assertStringContainsString('<li><p>a</p>', $html);
            self::assertStringContainsString('<p>more</p>', $html);
        }
    }
}
