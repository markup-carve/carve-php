<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ADefinitionTermPastAnOpenDescriptionColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function foldedLines(): array
    {
        return [
            'bullet' => ["  - a\n    * b", "- a\n* b"],
            'ordered' => ['  1. a', '1. a'],
            'task' => ['  * [ ] a', '* [ ] a'],
            'footnote' => ['  [^n]: note', '[^n]: note'],
        ];
    }

    #[DataProvider('foldedLines')]
    public function testAPastColumnTermClosesTheParagraph(string $lines, string $folded): void
    {
        $source = ":: t\n:  d\n{$lines}\n      :: u\n      :  e";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d\n{$folded}</p>\n    <dl>\n      <dt>u</dt>\n      <dd>e</dd>\n    </dl>\n  </dd>\n</dl>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testATermBelowTheBodyColumnKeepsItsCurrentReading(): void
    {
        $source = ":: t\n:  d\n  :: u\n  :  e";
        self::assertSame("<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>:: u\n:  e</p>", trim((new CarveConverter())->convert($source)));
    }

    public function testATermOneColumnPastTheBodyOpensTheNestedList(): void
    {
        $source = ":: t\n:  d\n    :: u\n    :  e";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <dl>\n      <dt>u</dt>\n      <dd>e</dd>\n    </dl>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAnOpenQuoteParagraphStillFoldsAnUnmarkedTerm(): void
    {
        $source = "> q\n>   :: u\n>   :  e";
        self::assertSame("<blockquote><p>q\n:: u\n:  e</p></blockquote>", trim((new CarveConverter())->convert($source)));
    }

    public function testATermInsideANestedItemKeepsItsDescriptionInThatItem(): void
    {
        $source = ":: t\n:  - a\n     :: u\n     :  e";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n        <dl>\n          <dt>u</dt>\n          <dd>e</dd>\n        </dl>\n      </li>\n    </ul>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testATermInsideAFenceDoesNotMoveItsSeparator(): void
    {
        $source = ":: t\n:  d\n\n   ```\n      :: u\n      :  e\n   ```";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <pre><code>   :: u\n   :  e\n</code></pre>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAParagraphAfterABlankReturnsToTheDescription(): void
    {
        $source = ":: t\n: d\n  - a\n     :: u\n     :  e\n\n     x";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d\n- a</p>\n    <dl>\n      <dt>u</dt>\n      <dd>e</dd>\n    </dl>\n    <p>x</p>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testADeeperTermKeepsItsDescription(): void
    {
        $source = ":: t\n:  d\n  - a\n      :: u\n      :  e\n         :: v\n         :  f";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d\n- a</p>\n    <dl>\n      <dt>u</dt>\n      <dd>\n        <p>e</p>\n        <dl>\n          <dt>v</dt>\n          <dd>f</dd>\n        </dl>\n      </dd>\n    </dl>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testALaterQuoteKeepsItsOpenParagraph(): void
    {
        $source = ":: t\n:  d\n  x\n\n   > q\n      :: u\n      :  e";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d\nx</p>\n    <blockquote><p>q\n:: u\n:  e</p></blockquote>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testALaterFenceKeepsItsContent(): void
    {
        $source = ":: t\n:  d\n  x\n\n   ```\n      :: u\n      :  e\n   ```";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d\nx</p>\n    <pre><code>   :: u\n   :  e\n</code></pre>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAnOpenNestedTermKeepsItsContinuation(): void
    {
        $source = ":: t\n:  d\n  - a\n\n   :: x\n      :: u\n      :  e";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d\n- a</p>\n    <dl>\n      <dt>x\n   :: u\n   :  e</dt>\n    </dl>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testATermInAReachedNoteStaysOutOfTheDescription(): void
    {
        $source = ":: t\n: d\n  [^n]: note\n    - a\n     :: u\n     :  e";
        self::assertSame("<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>", trim((new CarveConverter())->convert($source)));
    }

    public function testTwoFoldedMarkersKeepTheNestedSeparatorAligned(): void
    {
        $source = ":: t\n: d\n  - a\n    * b\n     :: u\n     :  e";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d\n- a\n* b</p>\n    <dl>\n      <dt>u</dt>\n      <dd>e</dd>\n    </dl>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testALaterFenceKeepsASeparatorShapedCodeLine(): void
    {
        $source = ":: t\n:  d\n  - a\n      :: u\n      :  e\n\n   ```\n      :  x\n   ```";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d\n- a</p>\n    <dl>\n      <dt>u</dt>\n      <dd>e</dd>\n    </dl>\n    <pre><code>   :  x\n</code></pre>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAParagraphAfterTwoFoldedMarkersReturnsToTheDescription(): void
    {
        $source = ":: t\n: d\n  - a\n    * b\n     :: u\n     :  e\n\n     x";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d\n- a\n* b</p>\n    <dl>\n      <dt>u</dt>\n      <dd>e</dd>\n    </dl>\n    <p>x</p>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function termSpacing(): array
    {
        return ['adjacent' => [''], 'blank' => ["\n"]];
    }

    #[DataProvider('termSpacing')]
    public function testAnotherTermAtTheSameColumnStaysInTheNestedList(string $separator): void
    {
        $source = ":: t\n: d\n  - a\n    * b\n     :: u\n     :  e\n{$separator}     :: v\n     :  f";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d\n- a\n* b</p>\n    <dl>\n      <dt>u</dt>\n      <dd>e</dd>\n      <dt>v</dt>\n      <dd>f</dd>\n    </dl>\n  </dd>\n</dl>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAQuotedDescriptionUsesTheSameBodyColumn(): void
    {
        $source = "> :: t\n> :  d\n>   * b\n>     :: u\n>     :  e";
        $expected = "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>\n      <p>d\n* b</p>\n      <dl>\n        <dt>u</dt>\n        <dd>e</dd>\n      </dl>\n    </dd>\n  </dl>\n</blockquote>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
