<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\HeadingNumbersExtension;
use MarkupCarve\Carve\Extension\IndexExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A TAB LABEL DERIVED FROM A HEADING READS THE ONE LEAF READER.
 *
 * `TabsExtension::getTextContent()` was a SECOND walk over the same inline
 * nodes, with arms for `Text` and `SmartPunctuation` and a recursion into
 * everything else. A `Code`, `Math` or `LiteralInline` node has no children -
 * its content lives on the node - so the walk contributed NOTHING for it and
 * the text was simply gone. `` ### `code()` and *bold* `` produced the label
 * ` and bold`, code span deleted and its leading space stranded, and because
 * the heading is CONSUMED by the label nothing else in the output carried the
 * text (markup-carve/carve-php#1075).
 *
 * `HeadingIdTracker::inlineTextLeaf()` already answers every one of these and
 * its docblock says the leaf rules live there and nowhere else, precisely so a
 * second walk cannot answer them differently. This is that second walk, routed
 * through the first.
 *
 * TEXT LOSS, NOT MARKUP LOSS. A label is plain text by construction, so
 * dropping the emphasis MARKUP from `*bold*` is correct and dropping the
 * CONTENT of `` `code()` `` is not. The rows below distinguish the two.
 *
 * Oracle: carve-js `62e0e5a`, measured row by row. carve-rs implements no tabs
 * extension at all - it carries `tab_normalize` and an id-registry test naming
 * `tabset-1`, and nothing that renders a tab label - so there is no third
 * answer to compare against here.
 */
class ATabsLabelReadsTheOneLeafReaderTest extends TestCase
{
    protected function label(string $heading, array $extensions = [], array $symbols = []): string
    {
        $converter = new CarveConverter(symbols: $symbols);
        $converter->addExtension(new TabsExtension());
        foreach ($extensions as $extension) {
            $converter->addExtension($extension);
        }
        $html = $converter->convert(
            "::::: tabs\n::: tab\n### " . $heading . "\n\nOne.\n:::\n::::\n",
        );

        return preg_match('/<label[^>]*class="tabs-label"[^>]*>(.*?)<\/label>/s', $html, $m)
            ? $m[1]
            : '(no label)';
    }

    /**
     * Every leaf whose content lives on the NODE rather than in children, plus
     * the ones that were already right.
     *
     * The ticket names a code span and lists five more candidates. Measured,
     * FIVE rows were wrong: the code span, math, the inline literal, escaped
     * text, and an `:index[]` marker - the last of which the ticket does not
     * name at all. A symbol and a raw inline were already byte-identical to the
     * leaf reader's answer, which is why they are here rather than assumed.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function leafRows(): array
    {
        return [
            'plain text' => ['plain', 'plain'],
            'code span' => ['`code()` and *bold*', 'code() and bold'],
            'math' => ['$`E = mc^2` and x', 'E = mc^2 and x'],
            'inline literal' => ['!`Cat` and x', 'Cat and x'],
            'escaped text' => ['a \* b', 'a * b'],
            'smart punctuation' => ["Don't stop", "Don\u{2019}t stop"],
            'raw inline' => ['`<b>r</b>`{=html} and x', 'and x'],
            'emphasis is markup, not text' => ['/em/ and x', 'em and x'],
            'a link keeps its text' => ['[text](/u) and x', 'text and x'],
            'strikethrough' => ['~del~ and x', 'del and x'],
            'superscript' => ['{^sup^} and x', 'sup and x'],
        ];
    }

    #[DataProvider('leafRows')]
    public function testTheLabelCarriesEveryLeafTheReaderNames(string $heading, string $expected): void
    {
        $this->assertSame($expected, $this->label($heading));
    }

    public function testAnIndexMarkerContributesNothingToTheLabel(): void
    {
        // PART 9 §8.1: an `:index[term]` marker is INVISIBLE, so its term is not
        // label text. The second walk recursed into the marker and emitted the
        // term, giving `termvisible`. carve-js emits `visible`.
        $this->assertSame('visible', $this->label(':index[term]visible', [new IndexExtension()]));
    }

    public function testASymbolIsSpelledTheWayTheLeafReaderSpellsIt(): void
    {
        // THE ROW SPLIT RATHER THAN MOVED. It used to be the one place this
        // engine and carve-js disagreed on what a leaf contributes: the leaf
        // reader wrote a symbol back as `:name:` while carve-js contributed
        // nothing. markup-carve/carve#1011 settled the ID side against the leaf
        // reader - syntax.md section 4.1 step 1 excludes a symbol from a
        // heading's derived TEXT - and carve-php#1101 then gave the DISPLAY
        // side its own reader, because a tab name is what the reader sees and a
        // symbol is visible content there.
        //
        // So the two derivations part on exactly this node, and a tab name
        // takes the display one. This assertion is what stops the id rule from
        // creeping back over the display path: it did creep once, in the window
        // between those two merges, and no other row noticed.
        $this->assertSame(
            ':smile: and x',
            $this->label(':smile: and x', [], [':smile:' => '<img alt="smile">']),
        );
    }

    public function testANumberedHeadingDropsTheNumberAndTheSeparator(): void
    {
        // MEASURED CONSEQUENCE, recorded rather than hidden. The leaf reader
        // contributes '' for a `section-number` span, on a rationale that argues
        // only about heading IDS ("the number would pollute the auto id"), and
        // it is applied to every caller. So a numbered heading's label loses the
        // number - where the second walk kept it, and where carve-js keeps it
        // (`1 Title`).
        //
        // Trimming here is this extension's own business and is what keeps the
        // stranded separator out of a panel NAME. Whether a derived label should
        // carry the number at all belongs to the leaf rules and is open.
        $this->assertSame('Title', $this->label('Title', [new HeadingNumbersExtension()]));
    }

    public function testAnExplicitLabelStillWins(): void
    {
        // CONTROL, and the reason the corpus could not catch any of this: the
        // one pinned tabs document in tests/spec/tests/corpus-optional
        // (28-tabs-panel-title) uses an explicit `[First]` opener label, which
        // is priority 1 and never reaches the heading walk at all. No mutation
        // of the heading arm moves this row.
        $converter = new CarveConverter();
        $converter->addExtension(new TabsExtension());
        $html = $converter->convert(
            ":::: tabs\n::: tab \"Inner *Title*\" [First]\nContent one.\n:::\n::::\n",
        );

        $this->assertStringContainsString('class="tabs-label">First</label>', $html);
    }

    public function testTheGenericFallbackStillApplies(): void
    {
        // The other CONTROL: a tab with no heading and no explicit label keeps
        // its counted name, so routing the heading arm cannot be mistaken for
        // having removed the arm.
        $converter = new CarveConverter();
        $converter->addExtension(new TabsExtension());
        $html = $converter->convert(":::: tabs\n::: tab\nContent one.\n:::\n::::\n");

        $this->assertStringContainsString('class="tabs-label">Tab 1</label>', $html);
    }
}
