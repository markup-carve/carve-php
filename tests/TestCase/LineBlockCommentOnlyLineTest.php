<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A comment-only body line in a line block leaves an EMPTY verse line, on
 * EVERY line and not only the first (PART 9 §23; carve-php#1393).
 *
 * §23 spells the rule out: a comment is not a block construct, so `%%` runs to
 * end of line wherever it appears, including inside verse, and "a comment-only
 * body line leaves an EMPTY verse line rather than disappearing - the line was
 * written, so the stanza keeps its shape".
 *
 * The defect was the inline layer's, not the block layer's. A stanza is parsed
 * as ONE inline run, so every body line but the first reaches the `%%` test
 * with a NEWLINE before it; the test admitted a space and a tab and nothing
 * else, so the comment fell through as ordinary text and the verse published
 * it. That is the same defect carve-js carried, under the same clause, and
 * fixed the same way, by widening the same class (markup-carve/carve-js#581) -
 * §23's own table still records it there.
 *
 * Two things have to hold together and are asserted apart below: the comment
 * TEXT is gone, and the LINE is still there. Dropping the row would keep a
 * secret out of the output and still be wrong, because a line block exists to
 * preserve a layout.
 */
class LineBlockCommentOnlyLineTest extends TestCase
{
    private function convert(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testACommentBetweenTwoVerseLinesLeavesAnEmptyLine(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        %% secret comment
        c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        <br>
        c</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testTheCommentTextNeverReachesTheOutput(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        %% secret comment
        c
        :::

        CARVE;

        $this->assertStringNotContainsString('secret', $this->convert($source));
        $this->assertStringNotContainsString('%%', $this->convert($source));
    }

    public function testACommentOnTheLastLineLeavesAnEmptyLine(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        %% c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        </p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testTheFirstLineStillDrops(): void
    {
        // The branch that always worked: at offset 0 of the stanza's inline run
        // the `%%` needs no preceding whitespace at all. Pinned so a fix to the
        // later lines cannot be bought by breaking this one.
        $source = <<<'CARVE'
        ::: |
        %% c
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p><br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testASoleCommentLineLeavesAnEmptyStanza(): void
    {
        $source = <<<'CARVE'
        ::: |
        %% c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p></p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testTwoCommentLinesLeaveTwoEmptyLines(): void
    {
        $source = <<<'CARVE'
        ::: |
        %% c1
        %% c2
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p><br>
        </p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testEachStanzaKeepsItsOwnShape(): void
    {
        // A blank line ends the stanza, so the comment below it is the FIRST
        // line of the second one. Both paths meet here.
        $source = <<<'CARVE'
        ::: |
        a
        %% one
        b

        %% two
        c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        <br>
        b</p>
          <p><br>
        c</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testACommentWithNoSeparatingSpaceIsStillAComment(): void
    {
        // §21 requires whitespace BEFORE `%%`, never after it, so `%%c` is a
        // comment with `c` as its body.
        $source = <<<'CARVE'
        ::: |
        a
        %%c
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        <br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testABareMarkerIsAnEmptyComment(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        %%
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        <br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testATrailingCommentAfterTextStillStripsOnALaterLine(): void
    {
        // The case that always worked, because a SPACE precedes the marker.
        // The text before it survives, so this line is not an empty one.
        $source = <<<'CARVE'
        ::: |
        a
        x %% secret
        c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        x<br>
        c</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testAnIndentedCommentLineStaysVerse(): void
    {
        // Leading whitespace in verse is CONTENT, not indentation - it is
        // preserved rather than stripped, so the `%%` does not start the line
        // and no comment is recognized. carve-js reads it the same way. This is
        // the boundary of the fix and is pinned so widening the whitespace class
        // any further would fail here.
        $source = <<<'CARVE'
        ::: |
        a
          %% c
        b
        :::

        CARVE;

        $html = $this->convert($source);

        $this->assertStringContainsString('%% c', $html);
    }

    public function testAnEscapedMarkerOnALaterLineStaysLiteral(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        \%% c
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        %% c<br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testAPercentRunThatIsNotAMarkerIsUntouched(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        50%% off
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        50%% off<br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testACodeSpanReachingTheLineStaysOpaque(): void
    {
        // §21: `%%` is NEVER recognized inside a code span. The span is unclosed
        // on its own line and reaches the end of the BLOCK, so it swallows the
        // marker along with the line ending.
        $source = <<<'CARVE'
        ::: |
        a `x
        %% c` y
        b
        :::

        CARVE;

        $html = $this->convert($source);

        $this->assertStringContainsString('%% c', $html);
        $this->assertStringContainsString('<code>', $html);
    }

    public function testTheRuleHoldsInsideAQuote(): void
    {
        $source = <<<'CARVE'
        > ::: |
        > a
        > %% c
        > b
        > :::

        CARVE;

        $html = $this->convert($source);

        $this->assertStringNotContainsString('%%', $html);
        $this->assertStringContainsString("a<br>\n<br>\nb", $html);
    }

    public function testTheRuleHoldsInsideAListItem(): void
    {
        $source = <<<'CARVE'
        - ::: |
          a
          %% c
          b
          :::

        CARVE;

        $html = $this->convert($source);

        $this->assertStringNotContainsString('%%', $html);
        $this->assertStringContainsString("a<br>\n<br>\nb", $html);
    }
}
