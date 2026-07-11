<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\DetailsExtension;
use PHPUnit\Framework\TestCase;

class DetailsExtensionTest extends TestCase
{
    /**
     * Convert with the details extension registered, trimmed for exact compare.
     */
    protected function render(string $djot): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new DetailsExtension());

        return trim($converter->convert($djot));
    }

    public function testQuotedTitleBecomesSummary(): void
    {
        $html = $this->render("::: details \"More info\"\nHidden _here_.\n:::");

        $expected = implode("\n", [
            '<details>',
            '  <summary>More info</summary>',
            '  <p>Hidden <u>here</u>.</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testDefaultSummaryWhenNoTitle(): void
    {
        $html = $this->render("::: details\nBody.\n:::");

        $expected = implode("\n", [
            '<details>',
            '  <summary>Details</summary>',
            '  <p>Body.</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testEmptyBodyKeepsBlankLine(): void
    {
        // An empty body renders as a single blank line, matching a core empty
        // container and carve-js / carve-rs. Regression for the collapsed line.
        $html = $this->render("::: details\n:::");

        $expected = implode("\n", [
            '<details>',
            '  <summary>Details</summary>',
            '',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testEscapesHtmlSpecialCharactersInSummary(): void
    {
        $html = $this->render("::: details \"Tom & Jerry\"\nx\n:::");

        $expected = implode("\n", [
            '<details>',
            '  <summary>Tom &amp; Jerry</summary>',
            '  <p>x</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testTitleIsPlainText(): void
    {
        // carve-php stores the quoted title as a flat (plain-text) attribute, so
        // the summary is the literal title - matching the default
        // `<p class="admonition-title">` rendering of the same block.
        $html = $this->render("::: details \"see here\"\nx\n:::");

        $expected = implode("\n", [
            '<details>',
            '  <summary>see here</summary>',
            '  <p>x</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testKeepsMultipleBlockChildren(): void
    {
        $html = $this->render("::: details \"T\"\nOne.\n\nTwo.\n:::");

        $expected = implode("\n", [
            '<details>',
            '  <summary>T</summary>',
            '  <p>One.</p>',
            '  <p>Two.</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testPreservesParagraphWrappersInsideListItem(): void
    {
        $html = $this->render("- item\n\n  ::: details \"d\"\n  inner\n  :::");

        $expected = implode("\n", [
            '<ul>',
            '  <li>item',
            '    <details>',
            '      <summary>d</summary>',
            '      <p>inner</p>',
            '    </details>',
            '  </li>',
            '</ul>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testNestedDetailsBlocks(): void
    {
        $html = $this->render(":::: details \"Outer\"\n::: details \"Inner\"\ndeep\n:::\n::::");

        $expected = implode("\n", [
            '<details>',
            '  <summary>Outer</summary>',
            '  <details>',
            '    <summary>Inner</summary>',
            '    <p>deep</p>',
            '  </details>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testCarriesKeyValueAttributesOntoTag(): void
    {
        $html = $this->render("{#faq open}\n::: details \"FAQ\"\nA.\n:::");

        $expected = implode("\n", [
            '<details id="faq" open="">',
            '  <summary>FAQ</summary>',
            '  <p>A.</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testCarriesIdAndExtraClassOntoTag(): void
    {
        // The auto `details` class is dropped (the tag is the styling hook); a
        // sibling class and the id are preserved in carve-php source order
        // (class before id, matching the default div renderer).
        $html = $this->render("{#faq .open}\n::: details \"Q\"\na\n:::");

        $expected = implode("\n", [
            '<details class="open" id="faq">',
            '  <summary>Q</summary>',
            '  <p>a</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testPreservesExplicitEmptyId(): void
    {
        $html = $this->render("{id}\n::: details \"T\"\nx\n:::");

        $expected = implode("\n", [
            '<details id="">',
            '  <summary>T</summary>',
            '  <p>x</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testBlockOnlyDetailsInListItemStaysNested(): void
    {
        // A list item whose only block is the details widget must nest the
        // `<details>` as block content (matching the default `<div>` behavior),
        // not inline it on the `<li>` line.
        $html = $this->render("- ::: details \"d\"\n  inner\n  :::");

        $expected = implode("\n", [
            '<ul>',
            '  <li>',
            '    <details>',
            '      <summary>d</summary>',
            '      <p>inner</p>',
            '    </details>',
            '  </li>',
            '</ul>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testSafeModeStripsDangerousAttributes(): void
    {
        // The extension must apply the same safe-mode attribute filtering as the
        // core div renderer, so a dangerous attribute cannot survive onto the
        // <details> tag.
        $converter = new CarveConverter(safeMode: true);
        $converter->addExtension(new DetailsExtension());
        $html = trim($converter->convert("{onclick=\"alert(1)\" #x}\n::: details \"T\"\nx\n:::"));

        $expected = implode("\n", [
            '<details id="x">',
            '  <summary>T</summary>',
            '  <p>x</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testDefaultModeHardensDangerousAttributes(): void
    {
        $html = $this->render("{onclick=\"alert(1)\" style=\"background:url(javascript:alert(1))\"}\n::: details \"T\"\nx\n:::");

        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('background:url', $html);
        $this->assertStringContainsString('<details style="">', $html);
    }

    public function testLeavesCanonicalAdmonitionsUntouched(): void
    {
        $html = $this->render("::: note\nhi\n:::");

        $this->assertStringContainsString('<aside class="admonition note">', $html);
    }

    public function testLeavesOtherCustomAdmonitionTypesAsPlainDivs(): void
    {
        $html = $this->render("::: aside-note\nhi\n:::");

        $this->assertStringContainsString('<div class="aside-note">', $html);
    }

    public function testWithoutExtensionDetailsStaysPlainDiv(): void
    {
        $converter = new CarveConverter();
        $html = trim($converter->convert("::: details \"More\"\nHidden.\n:::"));

        $expected = implode("\n", [
            '<div class="details">',
            '  <p class="admonition-title">More</p>',
            '  <p>Hidden.</p>',
            '</div>',
        ]);
        $this->assertSame($expected, $html);
    }
}
