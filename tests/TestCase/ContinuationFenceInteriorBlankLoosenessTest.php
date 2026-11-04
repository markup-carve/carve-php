<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A blank inside a fenced code block is payload, so it cannot separate two
 * parsed item blocks. A blank after the closer does separate them and loosens
 * the list. Matches carve-rs / carve-js (carve#326 case C).
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
            preg_replace('/\n\z/', '', "<ul>\n  <li><p>text</p>\n    <pre><code>a\n\nb\n</code></pre>\n  </li>\n  <li><p>c</p></li>\n</ul>\n"),
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
        $this->assertStringContainsString('<li><p>c</p></li>', $html);
        $this->assertStringNotContainsString('<li>c</li>', $html);
    }

    public function testAuthoredFenceColumnsKeepInteriorBlanksOpaque(): void
    {
        foreach (['  ', '    ', '      ', "\t"] as $indent) {
            foreach (['```', '~~~'] as $fence) {
                $source = "- item\n\n" . $indent . $fence . "\n" . $indent . "a\n\n" . $indent . "b\n" . $indent . $fence . "\n";
                self::assertSame(
                    preg_replace('/\n\z/', '', "<ul>\n  <li><p>item</p>\n    <pre><code>a\n\nb\n</code></pre>\n  </li>\n</ul>\n"),
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
        self::assertStringContainsString('<li><p>item</p>', $html);
        self::assertStringContainsString("<pre><code>code\n\nparagraph\n</code></pre>", $html);
    }

    public function testRawFenceInteriorBlankDoesNotLoosen(): void
    {
        $html = $this->converter->convert("- item\n\n    ```=html\n    <div>a\n\n    b</div>\n    ```\n");
        self::assertStringContainsString('<li><p>item</p>', $html);
        self::assertStringNotContainsString("<li>item\n", $html);
    }

    public function testFenceLookingParagraphContinuationsDoNotHideALooseningBlank(): void
    {
        foreach (
            [
                "- a\n  - s\n    t\n     ~~~\n\n  more\n",
                "- a\n  - sub\n     ```\n\n  more\n",
            ] as $source
        ) {
            $html = $this->converter->convert($source);
            self::assertStringContainsString('<li><p>a</p>', $html);
            self::assertStringContainsString('<p>more</p>', $html);
        }

        // The opener interrupts the quote paragraph, so the fence takes the blank and `more`.
        $html = $this->converter->convert("- a\n\n  > q\n    ```\n\n  more\n");
        self::assertStringContainsString("<li><p>a</p>\n    <blockquote><p>q</p></blockquote>\n    <pre><code>\nmore\n</code></pre>", $html);
    }
}
