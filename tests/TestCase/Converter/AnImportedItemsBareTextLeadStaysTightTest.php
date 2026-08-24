<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An item's bare-text lead keeps its blocks tight (carve-php#1708).
 *
 * The writer put a blank line between every pair of parts inside an item,
 * whatever tightness the list spelled. So an item whose lead is a BARE TEXT
 * node came back with a gap the source never had - `<li>a<h1>H</h1></li>` was
 * written as `- a`, a blank line, then ` # H`, where carve-js and carve-rs
 * both write it tight.
 *
 * THE RULE IS THE ONE markup-carve/carve-js#1110 SETTLED: only a DIRECT `<p>`
 * votes for looseness, and the vote is counted per LIST rather than per item.
 * A heading, a block quote, a code block, a table or a sublist is structure and
 * not a paragraph wrapper, so none of them loosens anything on its own. The
 * writer already computed that vote for the blank line BETWEEN items; what was
 * missing is that the separator INSIDE an item takes the same one.
 *
 * SCOPE: THE SOURCE SPELLING ONLY. A blank line loosens an item only when a
 * PARAGRAPH follows it, and nothing that reaches this separator is one - so
 * both spellings render identical bytes, and no anchor and no rendered
 * character moves. That is also why every assertion below reads the SOURCE:
 * asserting the rendered HTML would pass against either spelling and could not
 * fail, which is no check at all. {@see self::testBothSpellingsRenderTheSame}
 * pins that premise so the claim is measured rather than asserted in prose.
 */
class AnImportedItemsBareTextLeadStaysTightTest extends TestCase
{
    private function import(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    /**
     * THE DEFECT, one row per block kind that follows the lead.
     *
     * None of these items holds a direct `<p>`, so the list is tight and the
     * blocks below the lead sit at the content column with no blank line.
     *
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('tightLeadProvider')]
    public function testABareTextLeadIsWrittenTight(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->import($html));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function tightLeadProvider(): array
    {
        return [
            'a heading' => [
                '<ul><li>a<h1>H</h1></li></ul>',
                "- a\n  # H\n",
            ],
            'a block quote' => [
                '<ul><li>a<blockquote><p>q</p></blockquote></li></ul>',
                "- a\n  > q\n",
            ],
            'a sublist' => [
                '<ul><li>a<ul><li>b</li></ul></li></ul>',
                "- a\n  - b\n",
            ],
            'a code block' => [
                '<ul><li>a<pre><code>c</code></pre></li></ul>',
                "- a\n  ```\n  c\n  ```\n",
            ],
            'a table' => [
                '<ul><li>a<table><tr><td>t</td></tr></table></li></ul>',
                "- a\n  | t |\n",
            ],
            'two headings' => [
                '<ul><li>a<h1>H</h1><h2>I</h2></li></ul>',
                "- a\n  # H\n  ## I\n",
            ],
            'an ordered marker, whose content column is three' => [
                '<ol><li>a<h1>H</h1></li></ol>',
                "1. a\n   # H\n",
            ],
            'a bare-text sibling after it' => [
                '<ul><li>a<h1>H</h1></li><li>b</li></ul>',
                "- a\n  # H\n- b\n",
            ],
        ];
    }

    /**
     * THE CONTROL: a direct `<p>` still loosens, and the blank line comes back.
     *
     * This is the half that must not move. Reading the vote off anything other
     * than a direct `<p>` - "the item holds more than one block", say - would
     * pass the rows above and silently drop the paragraph these items spelled.
     *
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('looseLeadProvider')]
    public function testADirectParagraphStillLoosens(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->import($html));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function looseLeadProvider(): array
    {
        return [
            'the lead itself is a paragraph' => [
                '<ul><li><p>a</p><h1>H</h1></li></ul>',
                "{loose}\n- a\n\n  # H\n",
            ],
            'a paragraph below the lead' => [
                '<ul><li>a<h1>H</h1><p>p</p></li></ul>',
                "- a\n\n  # H\n\n  p\n",
            ],
            'a SIBLING item holds the paragraph, so the whole list loosens' => [
                '<ul><li>one<h1>H</h1></li><li><p>two</p></li></ul>',
                "- one\n\n  # H\n\n- two\n",
            ],
            'the mixed list of carve-js#1110' => [
                '<ul><li>one</li><li><p>two</p></li></ul>',
                "- one\n\n- two\n",
            ],
            'an all-bare-text list stays tight' => [
                '<ul><li>one</li><li>two</li></ul>',
                "- one\n- two\n",
            ],
        ];
    }

    /**
     * THE LIMIT OF THE RULE: a part that does not OPEN a block keeps its blank
     * line, because written tight it would fold into the lead.
     *
     * These are the shapes that make the vote insufficient on its own. None of
     * them holds a direct `<p>`, so the list is tight and the rows above would
     * abut them - but each is written as a bare inline run at the item's
     * content column, where it is lazy continuation of the lead paragraph
     * (PART 9 §10 I2) rather than a block of its own. Abutting them costs a
     * BLOCK, not a spelling.
     *
     * `testNoShapeChangesWhatItRenders()` is what proves the list is complete;
     * these rows record the specific shapes it was built from.
     *
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('foldingPartProvider')]
    public function testAPartThatOpensNoBlockKeepsItsBlankLine(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->import($html));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function foldingPartProvider(): array
    {
        return [
            'a bare div, degraded to its text' => [
                '<ul><li>a<div>d</div></li></ul>',
                "- a\n\n  d\n",
            ],
            'a figure, written as an inline run plus a caption line' => [
                '<ul><li>a<figure><img src="i.png"><figcaption>c</figcaption></figure></li></ul>',
                "- a\n\n  ![](i.png)\n  ^ c\n",
            ],
            'a sublist with a stray block hoisted in front of it' => [
                '<ul><li>a<ul><p>x</p><li>b</li></ul></li></ul>',
                "- a\n\n  x\n\n  - b\n",
            ],
            // THE EMPTY LIST MUST NOT ANSWER FOR THE RUN. It writes nothing, so
            // the nested run still starts with the stray paragraph below it -
            // and an empty list that got the vote abutted `x` into the lead.
            'an empty sublist ahead of one with a stray block' => [
                '<ul><li>a<ul></ul><ul><p>x</p><li>b</li></ul></li></ul>',
                "- a\n\n  x\n\n  - b\n",
            ],
        ];
    }

    /**
     * THE INVARIANT THE ALLOWLIST EXISTS TO KEEP, swept over every block kind.
     *
     * For each shape: import it, then re-insert the blank line after the lead
     * and confirm the two spellings still render the same HTML. A block that
     * may be abutted renders the same either way; one that may not would come
     * back as part of the lead paragraph, and this fails on it.
     *
     * This is the check that would have caught the first cut of carve-php#1708,
     * which abutted every part and silently folded a div, a figure and a
     * hoisted stray block into the lead.
     *
     * @param string $html
     */
    #[DataProvider('everyBlockKindProvider')]
    public function testNoShapeChangesWhatItRenders(string $html): void
    {
        $converter = CarveConverter::create();
        $imported = $this->import($html);
        $lines = explode("\n", rtrim($imported, "\n"));
        $this->assertGreaterThan(1, count($lines), 'the shape must have a block below the lead');

        $withGap = $lines[0] . "\n\n" . implode("\n", array_slice($lines, 1)) . "\n";

        $this->assertSame(
            $converter->convert($withGap),
            $converter->convert($imported),
            'the written spelling must render what the blank-line spelling renders',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyBlockKindProvider(): array
    {
        $blocks = [
            'heading' => '<h1>H</h1>',
            'heading with an id' => '<h2 id="t">t</h2>',
            'block quote' => '<blockquote><p>q</p></blockquote>',
            'code block' => '<pre><code>c</code></pre>',
            'table' => '<table><tr><td>t</td></tr></table>',
            'sublist' => '<ul><li>b</li></ul>',
            'ordered sublist' => '<ol><li>b</li></ol>',
            'thematic break' => '<hr>',
            'definition list' => '<dl><dt>t</dt><dd>d</dd></dl>',
            'disclosure' => '<details><summary>s</summary><p>b</p></details>',
            'bare div' => '<div>d</div>',
            'attributed div' => '<div id="z">d</div>',
            'figure' => '<figure><img src="i.png"><figcaption>c</figcaption></figure>',
            'sublist behind a stray paragraph' => '<ul><p>x</p><li>b</li></ul>',
            'sublist behind a stray div' => '<ul><div>x</div><li>b</li></ul>',
            'adjacent sublists' => '<ul><li>a</li></ul><ul><li>b</li></ul>',
            'an empty sublist ahead of a stray-block sublist' => '<ul></ul><ul><p>x</p><li>b</li></ul>',
        ];

        $cases = [];
        foreach ($blocks as $name => $block) {
            $cases[$name . ', bullet'] = ['<ul><li>a' . $block . '</li></ul>'];
            $cases[$name . ', ordered'] = ['<ol><li>a' . $block . '</li></ol>'];
        }

        return $cases;
    }

    /**
     * THE PREMISE THIS TICKET RESTS ON, measured rather than asserted in prose.
     *
     * The blank line did NOT make the item come back loose: a blank line
     * loosens an item only when a PARAGRAPH follows it, and in every shape here
     * a heading, a quote, a code block or a sublist follows. So the two
     * spellings render the same bytes and the change is a source-spelling one.
     *
     * It is also the reason the rows above assert source: an assertion on the
     * rendered HTML would hold against the old spelling too.
     *
     * @param string $html
     * @param string $expected
     */
    #[DataProvider('tightLeadProvider')]
    public function testBothSpellingsRenderTheSame(string $html, string $expected): void
    {
        $converter = CarveConverter::create();
        $loosened = (string)preg_replace('/\R/', "\n", $expected);
        // The spelling this writer used to emit: a blank line after the lead.
        $lines = explode("\n", rtrim($loosened, "\n"));
        $withGap = $lines[0] . "\n\n" . implode("\n", array_slice($lines, 1)) . "\n";

        $this->assertNotSame($withGap, $loosened, 'the two spellings must differ, or this proves nothing');
        $this->assertSame($converter->convert($withGap), $converter->convert($loosened));
    }
}
