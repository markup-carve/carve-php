<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CommonMark loosens a list whose item has a blank line above its sublist, which
 * Carve reads as no separator, so the import spells that looseness (#2126).
 */
class ABlankAboveAMarkdownSublistKeepsTheListLooseTest extends TestCase
{
    /**
     * Markdown, the expected Carve, and the HTML markdown-it renders for the
     * Markdown in CommonMark mode.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a blank above the sublist of a single item' => [
                "- a\n\n  - b\n  - c",
                "{loose}\n- a\n\n  - b\n  - c",
                '<ul><li><p>a</p><ul><li>b</li><li>c</li></ul></li></ul>',
            ],
            'an ordered parent' => [
                "1. a\n\n   - b\n   - c",
                "{loose}\n1. a\n\n   - b\n   - c",
                '<ol><li><p>a</p><ul><li>b</li><li>c</li></ul></li></ol>',
            ],
            'a loose middle list two levels down' => [
                "- x\n  - a\n\n    - b\n    - c",
                "- x\n  {loose}\n  - a\n\n    - b\n    - c",
                '<ul><li>x<ul><li><p>a</p><ul><li>b</li><li>c</li></ul></li></ul></li></ul>',
            ],
            'a paragraph continued above the blank' => [
                "- a\n  more\n\n  - b",
                "{loose}\n- a\n  more\n\n  - b",
                "<ul><li><p>a\nmore</p><ul><li>b</li></ul></li></ul>",
            ],
            'a single item already loose by a blank between its paragraphs' => [
                "- a\n\n  para\n\n  - b",
                "- a\n\n  para\n\n  - b",
                '<ul><li><p>a</p><p>para</p><ul><li>b</li></ul></li></ul>',
            ],
            'a list line inside an item-line fence' => [
                "- ```\n  x\n\n  - y\n  ```",
                "- ```\n  x\n\n  - y\n  ```",
                "<ul><li><pre><code>x\n\n- y</code></pre></li></ul>",
            ],
            'two items with no blank between them' => [
                "- a\n\n  - b\n- d",
                "- a\n\n  - b\n\n- d",
                '<ul><li><p>a</p><ul><li>b</li></ul></li><li><p>d</p></li></ul>',
            ],
            'a list already loose by a blank between items' => [
                "- a\n\n  - b\n\n- d",
                "- a\n\n  - b\n\n- d",
                '<ul><li><p>a</p><ul><li>b</li></ul></li><li><p>d</p></li></ul>',
            ],
            'a blank between two nested items' => [
                "- a\n  - b\n\n  - c",
                "- a\n  - b\n\n  - c",
                '<ul><li>a<ul><li><p>b</p></li><li><p>c</p></li></ul></li></ul>',
            ],
            'a tight list' => [
                "- a\n  - b\n  - c",
                "- a\n  - b\n  - c",
                '<ul><li>a<ul><li>b</li><li>c</li></ul></li></ul>',
            ],
        ];
    }

    /**
     * Shapes whose import the writer respells elsewhere (the blank above a
     * fence, two adjacent lists, an indented top-level list), so they stay out
     * of the fixed-point assertion.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function otherwiseRespelledShapes(): array
    {
        return [
            'a list line inside a fence' => [
                "- a\n  ```\n  x\n\n  - y\n  ```",
                "- a\n  ```\n  x\n\n  - y\n  ```",
                "<ul><li>a<pre><code>x\n\n- y</code></pre></li></ul>",
            ],
            'a paragraph between an item and an indented list' => [
                "- x\n\ny\n\n  - b",
                "- x\n\ny\n\n- b",
                '<ul><li>x</li></ul><p>y</p><ul><li>b</li></ul>',
            ],
            'a list after an adjacent list of another marker' => [
                "- a\n* b\n\n  - c",
                "- a\n\n{loose}\n* b\n\n  - c",
                '<ul><li>a</li></ul><ul><li><p>b</p><ul><li>c</li></ul></li></ul>',
            ],
        ];
    }

    #[DataProvider('shapes')]
    #[DataProvider('otherwiseRespelledShapes')]
    public function testTheImportSpellsTheLooseness(string $markdown, string $carve, string $html): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }

    #[DataProvider('shapes')]
    #[DataProvider('otherwiseRespelledShapes')]
    public function testTheImportRendersWhatCommonMarkRenders(string $markdown, string $carve, string $html): void
    {
        $rendered = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        $this->assertSame($html, $this->withoutSpaceAroundTags($rendered));
    }

    #[DataProvider('shapes')]
    public function testTheImportIsAFormatFixedPoint(string $markdown, string $carve, string $html): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown . "\n");

        $this->assertSame($imported, (new CarveConverter())->toCarve($imported));
    }

    /**
     * CommonMark reads `- b` as a second item of the list `- x` opened, so the
     * blank above it separates two items of that list, not an item from its
     * sublist.
     */
    public function testAnItemOfADeeperListIsNoSublist(): void
    {
        $this->assertStringNotContainsString('{loose}', (new MarkdownToCarve())->convert("- a\n   - x\n\n  - b\n"));
    }

    protected function withoutSpaceAroundTags(string $html): string
    {
        return trim(preg_replace('/\s*(<[^>]+>)\s*/', '$1', $html) ?? $html);
    }
}
