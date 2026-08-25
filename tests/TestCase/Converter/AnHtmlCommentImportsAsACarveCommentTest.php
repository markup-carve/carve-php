<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AN HTML COMMENT IMPORTS AS A CARVE COMMENT (`markup-carve/carve#1709`).
 *
 * It was dropped in every mode with nothing reported. The usual reason this
 * importer drops something is that Carve has no spelling for the shape - and
 * that reason never applied here, because CARVE HAS COMMENTS. So the drop was a
 * choice to lose bytes the format can hold, in the mode whose whole job is
 * fidelity, and it was a choice nobody had made: no clause anywhere named it.
 *
 * THE POSITION DECIDES THE SPELLING AND THE COMMENT IS NOT RELOCATED. Among
 * blocks it is a block comment, whose fence widens the way a code fence does,
 * so no payload can close it early. Inside an inline run it is the delimited
 * form, and two payloads close THAT early: text holding the closer, and text
 * holding a blank line, which ends the paragraph the run is in. Those are
 * dropped with one row saying so, rather than truncated or escaped into the
 * form - a comment that came back shorter, or carrying characters the author
 * did not write, is a silent content change.
 */
class AnHtmlCommentImportsAsACarveCommentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function keptProvider(): array
    {
        return [
            'a comment between two blocks is a block comment' => [
                '<p>a</p><!--note--><p>b</p>',
                "a\n\n%%%\nnote\n%%%\n\nb\n",
            ],
            'a comment inside a run is the delimited inline comment' => [
                '<p>a<!--note-->b</p>',
                "a{% note %}b\n",
            ],
            // THE TWO POSITIONS TOLD APART. A run that also carries text is a
            // real inline run: emitting the comment as a block here would put
            // the words either side of it into two paragraphs, which is the
            // document saying something it never said.
            'the run a comment sits in is not split' => [
                '<div>text <!--n--> more</div>',
                "text {% n %} more\n",
            ],
            // Otherwise the answer would depend on whether the author indented
            // their HTML: the same comment would be a block one in a minified
            // document and an inline one in a formatted one.
            'pretty-printer whitespace around a comment is layout' => [
                "<p>a</p>\n<!--n-->\n<p>b</p>",
                "a\n\n%%%\nn\n%%%\n\nb\n",
            ],
            'a comment that is the whole document is kept' => [
                '<!--note-->',
                "%%%\nnote\n%%%\n",
            ],
            'a multi-line comment is kept whole' => [
                "<!--multi\nline\ncomment-->",
                "%%%\nmulti\nline\ncomment\n%%%\n",
            ],
            // The reason the BLOCK form has no unspellable case: the fence
            // widens, so no payload can close it early.
            'the block fence widens past a payload that is itself a fence line' => [
                '<!--%%%%-->',
                "%%%%%\n%%%%\n%%%%%\n",
            ],
            // NOT one of the two unspellable payloads, and worth pinning apart
            // from them: a single newline inside the run is a soft wrap, so the
            // comment re-reads intact and refusing it would be a loss with no
            // cause.
            'an inline comment carrying one newline is kept' => [
                "<p>a<!--x\ny-->b</p>",
                "a{% x\ny %}b\n",
            ],
        ];
    }

    #[DataProvider('keptProvider')]
    public function testTheCommentIsKept(string $html, string $expected): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
            $this->assertSame($expected, $result->value, $mode);
            // Nothing was lost, so nothing is said.
            $this->assertSame([], $result->diagnostics, $mode);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unspellableProvider(): array
    {
        return [
            // Written into the delimited form it would end where the closer
            // appears, so the rest of the payload comes back as prose and the
            // document says something the author never wrote.
            'text holding the comment closer' => [
                '<p>a<!--has %} in-->b</p>',
                'holds the comment closer',
            ],
            // A blank line ends the paragraph the run is in, so both halves
            // come back as prose and the comment is gone.
            'text holding a blank line' => [
                "<p>a<!--x\n\ny-->b</p>",
                'holds a blank line',
            ],
        ];
    }

    #[DataProvider('unspellableProvider')]
    public function testAnUnspellableInlineCommentIsDroppedAndSaysSo(string $html, string $why): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
            $this->assertSame("ab\n", $result->value, $mode);
            $this->assertCount(1, $result->diagnostics, $mode);
            $row = $result->diagnostics[0]->toArray();
            $this->assertSame('element-dropped', $row['code'], $mode);
            $this->assertSame('warning', $row['severity'], $mode);
            $this->assertSame('/p[1]/comment()[2]', $row['path'], $mode);
            $this->assertStringContainsString($why, $row['message'], $mode);
        }
    }

    /**
     * Moving it would put text somewhere the author did not write it, and
     * `roundtrip` reading its own output would then find the document had moved.
     *
     * THE DOCUMENT CARRIES A SPELLABLE BLOCK COMMENT TOO, and that is what makes
     * this an assertion rather than a formality: asserting the absence of a
     * block fence around the unspellable comment alone passes for an engine that
     * never wrote a block comment in its life. Here the block form IS reached
     * and written, so the only way the inline one could appear beside it is a
     * relocation.
     */
    public function testAnUnspellableInlineCommentIsNotRelocatedToTheBlockForm(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<!--block--><p>a<!--has %} in-->b</p>');
        $this->assertSame("%%%\nblock\n%%%\n\nab\n", $result->value);
        $this->assertStringNotContainsString('has', $result->value);
        $this->assertCount(1, $result->diagnostics);
        $this->assertSame('element-dropped', $result->diagnostics[0]->toArray()['code']);
    }

    /**
     * It reaches the output with the element, so there is nothing to import and
     * nothing to report about it.
     *
     * This engine has no raw-preserve arm (`markup-carve/carve-php#1713`), so
     * the case is pinned on what it DOES do: the comment inside the unwrapped
     * element is imported like any other, rather than disappearing.
     */
    public function testACommentInsideAnUnwrappedElementIsStillImported(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport('<form><!--kept--></form>');
        $this->assertStringContainsString('kept', $result->value);
    }

    /**
     * A list holds items, so there is no Carve position BETWEEN two of them. The
     * comment is emitted ahead of the list, which is what every other stray
     * child of a list does here, and the move is declared rather than silent.
     *
     * `info`, where the text row beside it is `warning`: a comment renders
     * nothing in either language, so the move costs a reader of the OUTPUT
     * nothing and a reader of the SOURCE one position.
     */
    public function testACommentBetweenTwoListItemsIsKeptAndSaysThatItMoved(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<ul><li>a</li><!--n--><li>b</li></ul>');
        $this->assertSame("%%%\nn\n%%%\n\n- a\n- b\n", $result->value);
        $this->assertCount(1, $result->diagnostics);
        $row = $result->diagnostics[0]->toArray();
        $this->assertSame('element-unwrapped', $row['code']);
        $this->assertSame('info', $row['severity']);
        $this->assertSame('/ul[1]/comment()[2]', $row['path']);
    }
}
