<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A recognized opener past a definition body's minimum column establishes an
 * authored local block base (markup-carve/carve#1729). Ordinary text may still
 * continue lazily; below-column lines still leave the body.
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
            'a block quote' => ['> q', '<blockquote>'],
            'a bullet list marker remains lazy without a blank' => ['- x', '- x</dd>'],
            'a heading' => ['# h', '<h1'],
            'a thematic break' => ['---', '<hr>'],
            'a table row' => ['| a |', '<table>'],
        ];
    }

    #[DataProvider('blockOpenerProvider')]
    public function testABlockOpenerPastTheColumnUsesItsAuthoredBase(string $opener, string $rendered): void
    {
        $this->assertStringContainsString(
            $rendered,
            $this->html(":: t\n:  body\n    {$opener}\n"),
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

    /**
     * A fence at or past the minimum is structural. The authored base is removed
     * from the delimiter run while payload indentation remains relative to it.
     */
    public function testFormAKeepsAFencedBlockWhole(): void
    {
        $this->assertStringContainsString(
            '<pre><code>c',
            $this->html(":: t\n:  a\n\n   ```\n   c\n   ```\n"),
        );
    }

    public function testAFenceOneColumnPastTheColumnUsesItsAuthoredBase(): void
    {
        $this->assertStringContainsString(
            '<pre><code>c',
            $this->html(":: t\n:  a\n\n    ```\n    c\n    ```\n"),
        );
    }

    /**
     * The minimum-column fence already owns its payload. A backtick run in a
     * tilde fence is code text, not a second authored-base opener.
     */
    public function testAnExactColumnTildeFenceKeepsPayloadIndentation(): void
    {
        $exact = ":: t\n:  a\n\n   ~~~~\n    ```\n   ~~~~\n";
        $over = ":: t\n:  a\n\n    ~~~~\n     ```\n    ~~~~\n";
        $expected = ":: t\n:  a\n\n   ````\n    ```\n   ````\n";

        $this->assertSame($expected, CarveConverter::toCarve($exact));
        $this->assertSame($expected, CarveConverter::toCarve($over));
        $this->assertSame($expected, CarveConverter::toCarve($expected));
    }

    /**
     * A following deeper line stays relative to the authored quote base.
     */
    public function testConsecutiveLinesPastTheColumnStayInTheAuthoredQuote(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <p>body</p> <blockquote><p>q &gt; r</p></blockquote> </dd> </dl>',
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
     * WHAT THE BLOCK MAKES OF THE LINE IS ITS OWN BUSINESS, and it reads the
     * columns the line wrote past the description's content column - which is
     * why these rows are written at the column and one past it. The
     * past-the-column pair used to expect the at-the-column answer; that needed
     * the body `ltrim`ed, which is what made a nested list in a `dd` come back
     * as two siblings. All four are byte-identical to carve-js `ba42673`
     * (markup-carve/carve-php#1650).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bodyOpensABlockProvider(): array
    {
        return [
            'a list, at the column' => [":: t\n:  - x\n   - y\n", '<ul> <li>x</li> <li>y</li> </ul>'],
            'a list, one past it' => [":: t\n:  - x\n    - y\n", '<ul> <li>x - y</li> </ul>'],
            'a fenced code block, at the column' => [":: t\n:  ```\n   c\n   ```\n", '<pre><code>c </code></pre>'],
            'a fenced code block, one past it' => [
                ":: t\n:  ```\n    c\n    ```\n",
                '<pre><code> c </code></pre>',
            ],
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
        $this->assertCount(4, self::bodyOpensABlockProvider());
    }
}
