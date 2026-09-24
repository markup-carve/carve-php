<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A blank line inside a sub-list that FOLLOWS a sibling sub-list belongs to
 * that sub-list, judged by its own content column, not the first one's
 * (markup-carve/carve-php#2262, ported from markup-carve/carve-js#1954).
 */
class ABlankInsideASiblingSubListDoesNotLoosenTheOuterListTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function html(string $source): string
    {
        return $this->converter->convert($source);
    }

    public function testABlankInsideAFenceInTheSecondSubList(): void
    {
        $this->assertSame(
            "<ul>\n  <li>e\n    <ol>\n      <li>x</li>\n    </ol>\n    <ul>\n      <li>\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
            trim($this->html("- e\n  1. x\n  * ```\n\n    ```\n")),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tightOuterProvider(): array
    {
        return [
            'a tilde fence' => ["- e\n  1. x\n  * ~~~\n\n    ~~~\n"],
            'a fence with body lines' => ["- e\n  1. x\n  * ```\n    a\n\n    b\n    ```\n"],
            'an unclosed fence' => ["- e\n  1. x\n  * ```\n\n    code\n"],
            'a fence in a quote' => ["- e\n  1. x\n  * > ```\n    >\n    > c\n    > ```\n"],
            'a third sub-list' => ["- e\n  1. x\n  * y\n  1. ```\n\n     ```\n"],
            'a second paragraph of the sibling sub-item' => ["- e\n  1. x\n  * y\n\n    z\n"],
            'a folded marker below the sub-item column' => ["- e\n  1. x\n    * y\n\n     z\n"],
        ];
    }

    #[DataProvider('tightOuterProvider')]
    public function testTheOuterItemStaysTight(string $source): void
    {
        $this->assertStringStartsWith("<ul>\n  <li>e\n", $this->html($source));
    }

    public function testThreeLevelsDeepKeepsTheMiddleItemTight(): void
    {
        $this->assertStringContainsString("<li>f\n", $this->html("- e\n  - f\n    1. x\n    * ```\n\n      ```\n"));
    }

    public function testASiblingAfterAMarkerLeadKeepsTheNextOuterItemTight(): void
    {
        $this->assertStringContainsString('<li>g</li>', $this->html("- 1. x\n  * ```\n\n    ```\n- g\n"));
    }

    public function testTheSiblingSubItemStillReadsItsOwnSecondParagraphLoose(): void
    {
        $this->assertStringContainsString("<li><p>y</p>\n        <p>z</p>", $this->html("- e\n  1. x\n  * y\n\n    z\n"));
    }

    public function testTheOuterItemsOwnSecondParagraphStillLoosensIt(): void
    {
        $this->assertStringStartsWith("<ul>\n  <li><p>e</p>\n", $this->html("- e\n  1. x\n  * y\n\n  text\n"));
    }

    public function testABlankBetweenTheOuterItemsStillLoosensTheList(): void
    {
        $this->assertStringStartsWith("<ul>\n  <li><p>e</p>\n", $this->html("- e\n  1. x\n  * y\n\n- f\n"));
    }
}
