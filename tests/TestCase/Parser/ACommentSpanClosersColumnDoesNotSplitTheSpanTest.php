<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment span's closer belongs to its span at every column.
 *
 * PART 9 §28 pairs the two `%%%` delimiters and indentation is part of neither
 * (markup-carve/carve#2471), so a closer written BELOW its host's content column
 * is still the closer. Three hosts ended the container there instead. The
 * container's own parse then read an opener with no closer, §28 made that one
 * `%%` line comment, and the PAYLOAD reached the page while both delimiters did
 * not (markup-carve/carve#2488, ruled in markup-carve/carve#2503 and
 * markup-carve/carve#2505, reported as carve-php#2650).
 *
 * No reading of a comment makes the body visible and the markers invisible, and
 * the closer's column is not a parameter: the same span closed at the opener's
 * own base hides the payload, and so does the same pair at document level. Both
 * are controls here.
 *
 * THE BAND MATTERS, not one column. Measured against the oracle
 * (`scripts/spec/layout.mjs` with `scripts/spec/html.mjs`) at
 * markup-carve/carve 8323c14a, the description body leaked at closer columns 0,
 * 1 and 2 and hid the payload from 3 up; the note body leaked at 0 and 1; the
 * list item leaked at 0 alone, and only where the item held a second span or a
 * nested list. A host that looks correct may be correct at some columns only, so
 * every row below spells its closer column.
 *
 * BOTH DIRECTIONS LEAK, which is why the predicate reads a code fence's payload
 * as opaque and walks a list marker off only at a block start: an invented
 * opaque body hides a real opener, and a missed one invents a span that claims a
 * real delimiter as its closer. The last three providers are those two guards.
 */
class ACommentSpanClosersColumnDoesNotSplitTheSpanTest extends TestCase
{
    private function html(string $source): string
    {
        return rtrim((new CarveConverter())->convert($source), "\n");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function descriptionBodies(): array
    {
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>head</dd>\n</dl>";
        $rows = [];
        foreach (range(0, 6) as $column) {
            $rows['closer at column ' . $column] = [
                ":: t\n:  head\n\n     %%%\n     a\n" . str_repeat(' ', $column) . "%%%\n",
                $expected,
            ];
        }

        return $rows;
    }

    #[DataProvider('descriptionBodies')]
    public function testADescriptionBodyKeepsThePayloadHidden(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function noteBodies(): array
    {
        $expected = '<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n"
            . '<section role="doc-endnotes" aria-label="Footnotes">' . "\n"
            . "  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
            . '      <p>head<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>' . "\n"
            . "    </li>\n  </ol>\n</section>";
        $rows = [];
        foreach (range(0, 3) as $column) {
            $rows['closer at column ' . $column] = [
                "see[^f]\n\n[^f]: head\n\n  %%%\n  a\n" . str_repeat(' ', $column) . "%%%\n",
                $expected,
            ];
        }

        return $rows;
    }

    #[DataProvider('noteBodies')]
    public function testANoteBodyKeepsThePayloadHidden(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * Two spans in one item. The single-span shape already answered correctly at
     * every column, so it is a control rather than a row: what the break cost
     * was the SECOND span, whose opener the item never reached.
     *
     * @return array<string, array{string, string}>
     */
    public static function itemsHoldingTwoSpans(): array
    {
        $expected = "<ul>\n  <li><p>head</p>\n    <p>tail</p>\n  </li>\n</ul>";
        $rows = [];
        foreach (range(0, 5) as $column) {
            $rows['first closer at column ' . $column] = [
                "- head\n\n    %%%\n    a\n" . str_repeat(' ', $column)
                    . "%%%\n    %%%\n    b\n    %%%\n\n  tail\n",
                $expected,
            ];
        }

        return $rows;
    }

    #[DataProvider('itemsHoldingTwoSpans')]
    public function testAnItemHoldingTwoSpansKeepsBothPayloadsHidden(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nestedItems(): array
    {
        $expected = "<ul>\n  <li>o\n    <ul>\n      <li>head</li>\n    </ul>\n  </li>\n</ul>";
        $rows = [];
        foreach (range(0, 5) as $column) {
            $rows['first closer at column ' . $column] = [
                "- o\n  - head\n\n    %%%\n    a\n" . str_repeat(' ', $column) . "%%%\n%%%\n    b\n    %%%\n",
                $expected,
            ];
        }

        return $rows;
    }

    #[DataProvider('nestedItems')]
    public function testANestedItemKeepsThePayloadHidden(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * CONTROL - a single span in an item already answered correctly, at every
     * column. A fix that changed this row reached further than the defect.
     *
     * @return array<string, array{string}>
     */
    public static function itemsHoldingOneSpan(): array
    {
        $rows = [];
        foreach (range(0, 5) as $column) {
            $rows['closer at column ' . $column] = [
                "- head\n\n    %%%\n    a\n" . str_repeat(' ', $column) . "%%%\n\n  tail\n",
            ];
        }

        return $rows;
    }

    #[DataProvider('itemsHoldingOneSpan')]
    public function testAControlAnItemHoldingOneSpanIsUnchanged(string $source): void
    {
        $this->assertSame("<ul>\n  <li><p>head</p>\n    <p>tail</p>\n  </li>\n</ul>", $this->html($source));
    }

    /**
     * CONTROL - a quote and a document-level pair both hid the payload already,
     * which is what makes the rows above defects rather than a choice: one
     * document cannot depend on which container the span sits in.
     *
     * @return array<string, array{string, string}>
     */
    public static function unaffectedHosts(): array
    {
        return [
            // `>%%%` supplies no space after the marker, so it is lazy text of
            // the paragraph above rather than a delimiter at all. Both readers
            // answer it that way, which is why it belongs here.
            'a quote, a marker-abutting run is text' => [
                "> head\n>\n>   %%%\n>   a\n>%%%\n",
                "<blockquote>\n  <p>head</p>\n  <p>a\n&gt;%%%</p>\n</blockquote>",
            ],
            'a quote, closer at its opener base' => [
                "> head\n>\n>   %%%\n>   a\n>   %%%\n",
                '<blockquote><p>head</p></blockquote>',
            ],
            'a quote, closer one column in' => [
                "> head\n>\n>   %%%\n>   a\n> %%%\n",
                '<blockquote><p>head</p></blockquote>',
            ],
            'document level, closer at column 0' => [
                "head\n\n  %%%\n  a\n%%%\n\ntail\n",
                "<p>head</p>\n<p>tail</p>",
            ],
        ];
    }

    #[DataProvider('unaffectedHosts')]
    public function testAControlTheOtherHostsAreUnchanged(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * CONTROL - A CODE FENCE'S PAYLOAD IS OPAQUE, so a `%%%` written inside one
     * opens no span. Reading it as an opener would leave a span open that nobody
     * wrote, and the `%%%` below would then be claimed as its closer.
     *
     * @return array<string, array{string, string}>
     */
    public static function openersInsideACodeFence(): array
    {
        $rows = [];
        foreach (range(0, 5) as $column) {
            $rows['delimiter at column ' . $column] = [
                "- head\n\n    ```\n    %%%\n    ```\n" . str_repeat(' ', $column) . "%%%\ntail\n",
                "<ul>\n  <li>head\n    <pre><code>%%%\n</code></pre>\n  </li>\n</ul>\n<p>tail</p>",
            ];
        }

        return $rows;
    }

    #[DataProvider('openersInsideACodeFence')]
    public function testAControlAnOpenerInsideACodeFenceOpensNothing(string $source, string $html): void
    {
        $this->assertSame($html, $this->html($source));
    }

    /**
     * CONTROL - THE OPPOSITE DIRECTION. An opener with no closer ahead opens no
     * span (§28), so its payload is ordinary content and the lines below it are
     * the host's to fold. A predicate that reported a span open here would hide
     * text nobody commented out.
     */
    public function testAControlAnOpenerWithNoCloserHidesNothing(): void
    {
        $html = $this->html("- head\n\n    %%%\n    a\nb\ntail\n");

        $this->assertStringContainsString('a', $html);
        $this->assertStringContainsString('b', $html);
        $this->assertStringContainsString('tail', $html);
    }

    /**
     * CONTROL - the payload of an open span is still hidden when the host ends
     * without the closer ever arriving, because §28 then gives the opener no
     * block at all and it is one `%%` line comment. The description body is the
     * host whose band reached furthest, so it carries this row.
     */
    public function testAControlADescriptionBodyWithNoCloserKeepsItsText(): void
    {
        $html = $this->html(":: t\n:  head\n\n     %%%\n     a\n");

        $this->assertStringContainsString('head', $html);
        $this->assertStringContainsString('a', $html);
    }
}
