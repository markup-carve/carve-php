<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition-body continuation indented PAST the body's column is LAZY TEXT.
 *
 * `definition_indent` REACHES the body's column and does not measure how far
 * past it a line went, because there is nothing past that column for
 * indentation to MEAN (markup-carve/carve#918). A line indented further
 * therefore continues the body's OPEN PARAGRAPH, and a paragraph continuation
 * carries inline content:
 *
 * A term line `:: t`, then a body line written `:` + two spaces + `body` (which
 * puts the body's column at 3), then a line indented FOUR columns holding
 * `> q`, gives `<dd>body` newline `&gt; q</dd>` - not a nested block quote. The
 * example is spelled out rather than shown because the formatter collapses a
 * literal double space in a doc block, and the double space after the `:` is
 * exactly what sets the column this rule is about.
 *
 * WHY IT IS NOT "EXTRA INDENTATION NESTS". That reading makes indentation depth
 * mean two different things one line apart: lazy continuation already governs
 * the line above, folding it into the same paragraph, and a stray four-space
 * indent would silently become a block quote.
 *
 * THE TWO COLUMNS ON EITHER SIDE DO NOT MOVE, and they are the controls. At the
 * body's own column the quote opens; flush left the body ends and the quote is
 * a sibling. A fix that reaches either of them has overshot.
 *
 * A LEGITIMATELY NESTED CONSTRUCT needs the blank-line-then-indented-block form
 * (FORM A), which is how a `dd` already holds more than one paragraph. That is
 * the whole of the distinction: the blank is what separates the two readings,
 * and once FORM A has opened a block the lines under it belong to that block.
 */
class ADefinitionContinuationPastItsColumnIsLazyTextTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $html));
    }

    /**
     * A block OPENER past the column, one row per construct.
     *
     * A paragraph line past the column is invisible here: it folded lazily
     * before this rule and folds lazily now, rendering the same text either way.
     * Only a block opener separates the two readings, which is why every row is
     * one.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockOpenerProvider(): array
    {
        return [
            'a block quote' => ['> q', '&gt; q'],
            'a bullet list' => ['- x', '- x'],
            'a heading' => ['# h', '# h'],
            'a thematic break' => ['---', '—'],
            'a table row' => ['| a |', '| a |'],
        ];
    }

    #[DataProvider('blockOpenerProvider')]
    public function testABlockOpenerPastTheColumnIsText(string $opener, string $rendered): void
    {
        $this->assertSame(
            "<dl> <dt>t</dt> <dd>body {$rendered}</dd> </dl>",
            $this->squash($this->html(":: t\n:  body\n    {$opener}\n")),
        );
    }

    public function testAtTheBodysOwnColumnTheQuoteStillOpens(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <p>body</p> <blockquote><p>q</p></blockquote> </dd> </dl>',
            $this->squash($this->html(":: t\n:  body\n   > q\n")),
        );
    }

    public function testFlushLeftTheQuoteIsASibling(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd>body</dd> </dl> <blockquote><p>q</p></blockquote>',
            $this->squash($this->html(":: t\n:  body\n> q\n")),
        );
    }

    /**
     * FORM A after a blank still opens a real block, and keeps its own lines.
     *
     * The second line of the nested construct is the discriminating one: it is
     * also past the column, so a fix that tested the indent alone folded it into
     * the first and turned a two-item list into one paragraph.
     */
    public function testFormAOpensARealBlockAndKeepsItsLines(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <p>a</p> <ul> <li>x</li> <li>y</li> </ul> </dd> </dl>',
            $this->squash($this->html(":: t\n:  a\n\n    - x\n    - y\n")),
        );
    }

    public function testFormAKeepsAFencedBlockWhole(): void
    {
        $this->assertStringContainsString(
            '<pre><code>c',
            $this->html(":: t\n:  a\n\n    ```\n    c\n    ```\n"),
        );
    }

    /**
     * Several lazy lines past the column all join the one paragraph.
     */
    public function testConsecutiveLinesPastTheColumnAllFoldIn(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd>body &gt; q &gt; r</dd> </dl>',
            $this->squash($this->html(":: t\n:  body\n    > q\n     > r\n")),
        );
    }

    /**
     * A body that itself OPENS A BLOCK has no open paragraph to continue.
     *
     * The rule is about a line following the body's own PARAGRAPH. When the body
     * starts with a list marker or a fence, the past-the-column line belongs to
     * that block's own reading, and folding it turned a nested list into literal
     * text. A list marker needs asking for separately, because
     * `startsNewBlock()` answers the INTERRUPTION question and PART 9 §10 says a
     * bullet never interrupts a paragraph - so it reports false for a line that
     * does open a block here.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bodyOpensABlockProvider(): array
    {
        return [
            'a list' => [":: t\n:  - x\n    - y\n", '<ul> <li>x</li> <li>y</li> </ul>'],
            'a fenced code block' => [":: t\n:  ```\n    c\n    ```\n", '<pre><code>c </code></pre>'],
        ];
    }

    #[DataProvider('bodyOpensABlockProvider')]
    public function testABodyThatOpensABlockKeepsItsBlock(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->squash($this->html($source)));
    }

    public function testEveryOpenerIsStillCovered(): void
    {
        $this->assertCount(5, self::blockOpenerProvider());
        $this->assertCount(2, self::bodyOpensABlockProvider());
    }
}
