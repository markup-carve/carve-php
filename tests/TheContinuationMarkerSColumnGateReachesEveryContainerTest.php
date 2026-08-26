<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE COLUMN GATE IS ONE OPERATION IN EVERY CONTAINER (PART 9 section 17 L3,
 * markup-carve/carve#1814, markup-carve/carve-php#1804).
 *
 * `AND FLUSH-LEFT MEANS COLUMN 0` (section 17 L3, markup-carve/carve#1436) says
 * the `+` marker attaches a block that begins at DOCUMENT column 0 and nothing
 * else. A line at any other column is not attached at all: it falls through to
 * the ordinary column rules, which give it to whichever container its own
 * column names, "exactly as if the `+` line had been a comment".
 *
 * That question was asked in the LIST ITEM and nowhere else, so a footnote
 * body, a definition description and a block quote each reached out for a line
 * the clause leaves where the author wrote it.
 *
 * THE CLAUSE NAMES ITS OWN CONTROL, so the rule is a RELATION between two
 * documents and no single golden can express it: for every container, the
 * marker spelling and the comment spelling of the same document must render
 * the same thing. A change that fixes three containers and drifts the fourth
 * passes every golden it did not touch.
 *
 * The QUOTE row uses the blank-line control as well. A comment line at column 0
 * under an OPEN quoted paragraph is folded into it as lazy text rather than
 * being skipped - a defect of the quote's invisible-line handling and not of
 * the marker, deliberately left by markup-carve/carve#1817 - so the row closes
 * the quoted paragraph with a bare `>` first and asks about the column alone.
 */
class TheContinuationMarkerSColumnGateReachesEveryContainerTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * Whitespace before a closing tag is dropped as well as collapsed: a
     * comment line inside a list item leaves a trailing space in the item's
     * text where the marker leaves none. That is the comment's own layout
     * artifact and says nothing about which container the line after it
     * reached, which is the only thing these rows ask.
     */
    private function html(string $src): string
    {
        $html = $this->converter->convert($src);
        $html = (string)preg_replace('/\s+/', ' ', $html);
        $html = str_replace('> <', '><', $html);
        $html = (string)preg_replace('/ (<\/)/', '$1', $html);

        return trim($html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bandProvider(): array
    {
        return [
            'a footnote body, below its minimum column' => ["[^a]: intro\n@\n more\n\nsee[^a]\n"],
            'a footnote body, at its minimum column' => ["[^a]: intro\n@\n  more\n\nsee[^a]\n"],
            'a description, below its content column' => [":: term\n:  intro\n@\n  more\n"],
            'a description, one column further below' => [":: term\n:  intro\n@\n more\n"],
            'a block quote, with the quoted paragraph closed' => ["> intro\n>\n@\n  more\n"],
            'a list item, the container that always held the gate' => ["- intro\n@\n  more\n"],
        ];
    }

    #[DataProvider('bandProvider')]
    public function testTheMarkerReachesNoFurtherThanACommentDoes(string $src): void
    {
        $marker = str_replace('@', '+', $src);
        $comment = str_replace('@', '%% c', $src);

        $this->assertSame($this->html($comment), $this->html($marker));
    }

    public function testTheQuoteAgreesWithItsBlankLineControlToo(): void
    {
        $src = "> intro\n>\n@\n  more\n";

        $this->assertSame(
            $this->html(str_replace("@\n", '', $src)),
            $this->html(str_replace('@', '+', $src)),
        );
    }

    /**
     * The positive half. A gate that refused everything would satisfy every
     * assertion above, so each container is asked the SAME document one column
     * over, where the marker does attach.
     *
     * @return array<string, array{string, string}>
     */
    public static function attachesProvider(): array
    {
        return [
            'a footnote body' => [
                "[^a]: intro\n+\nmore\n\nsee[^a]\n",
                '<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>'
                    . '<section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1">'
                    . '<p>intro</p><p>more<a href="#fnref1" role="doc-backlink" '
                    . 'aria-label="Back to reference">↩</a></p></li></ol></section>',
            ],
            'a description' => [
                ":: term\n:  intro\n+\nmore\n",
                '<dl><dt>term</dt><dd><p>intro</p><p>more</p></dd></dl>',
            ],
            'a block quote' => [
                "> intro\n>\n+\nmore\n",
                '<blockquote><p>intro</p><p>more</p></blockquote>',
            ],
            'a list item' => [
                "- intro\n+\nmore\n",
                '<ul><li>intro more</li></ul>',
            ],
        ];
    }

    #[DataProvider('attachesProvider')]
    public function testAColumnZeroBlockStillAttaches(string $src, string $expected): void
    {
        $this->assertSame($expected, $this->html($src));
    }
}
