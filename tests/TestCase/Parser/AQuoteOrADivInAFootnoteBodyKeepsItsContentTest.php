<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition written inside a QUOTE or a DIV in a footnote body belongs to
 * that container, not to the body: it renders as the container's text and
 * resolves nothing.
 *
 * `resources/spec/01-layout.ebnf:330` is NORMATIVE - A QUOTE IS REACHED BY ITS
 * MARKER, AND A COLUMN NEVER REACHES INTO ONE - so a line writing no `>` is in
 * no quote and its indentation is not a base one container out. PART 9 §12
 * makes a div's content the div's own the same way.
 *
 * THE TOP-LEVEL TWIN IS THE ARBITER, not the other engines. Every row below is
 * paired with the same document written outside a note, where this engine and
 * carve-js `4627270e` already agree; the note-body answer now matches it.
 * carve-js keeps the line as text inside a note body but ALSO resolves the
 * reference, which is neither answer - a line is text or a definition, never
 * both - so it is not an oracle for these rows (carve-php#1885, #1886).
 *
 * `testTheInvariantHoldsForEveryShape` states that as a property rather than a
 * golden, because it is the thing that was broken: this engine used to drop the
 * text AND resolve.
 */
class AQuoteOrADivInAFootnoteBodyKeepsItsContentTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * Each row is a note-body document and the top-level document it must
     * answer like, once the note's two columns are taken off.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function containerProvider(): array
    {
        return [
            'a div holding an indented definition' => [
                "[^f]: b\n\n  ::: note\n   [r]: /url\n  :::\n\nSee [r][] and [^f].\n",
                "::: note\n [r]: /url\n:::\n\nSee [r][].\n",
            ],
            'a quote whose lazy line is a definition' => [
                "[^f]: b\n\n  > q\n   [r]: /url\n\nSee [r][] and [^f].\n",
                "> q\n [r]: /url\n\nSee [r][].\n",
            ],
            'prose then a definition, both the quote\'s lazy run' => [
                "[^f]: b\n\n  > q\n  p\n   [r]: /url\n\nSee [r][] and [^f].\n",
                "> q\np\n [r]: /url\n\nSee [r][].\n",
            ],
            'a div holding a visible block before the definition' => [
                "[^f]: b\n\n  ::: note\n  # h\n   [r]: /url\n  :::\n\nSee [r][] and [^f].\n",
                "::: note\n# h\n [r]: /url\n:::\n\nSee [r][].\n",
            ],
            'a closed inner div, then a definition in the outer' => [
                "[^f]: b\n\n  ::: outer\n  ::: inner\n  :::\n   [r]: /url\n  :::\n\nSee [r][] and [^f].\n",
                "::: outer\n::: inner\n:::\n [r]: /url\n:::\n\nSee [r][].\n",
            ],
        ];
    }

    /**
     * THE INVARIANT: a line is text or a definition, never both. Rendering the
     * line AND resolving a reference against it is the defect this fixes, and
     * it is what carve-js still does here.
     */
    #[DataProvider('containerProvider')]
    public function testTheInvariantHoldsForEveryShape(string $inNote, string $atTopLevel): void
    {
        foreach ([$inNote, $atTopLevel] as $source) {
            $html = $this->converter->convert($source);
            $this->assertStringContainsString('[r]: /url', $html);
            $this->assertStringNotContainsString('href="/url"', $html);
        }
    }

    /**
     * AND THE HOST DOES NOT CHANGE IT. The note body and the top level are the
     * same document two columns apart, so the container they render is the
     * same one - compared as a normalized fragment, since the note nests it
     * two levels deeper and the quote row folds the line INTO the paragraph
     * rather than beside it.
     */
    #[DataProvider('containerProvider')]
    public function testTheNoteBodyAnswersLikeTheTopLevel(string $inNote, string $atTopLevel): void
    {
        $this->assertSame(
            $this->container($this->converter->convert($atTopLevel)),
            $this->container($this->converter->convert($inNote)),
        );
    }

    /**
     * The first quote/aside/div in a rendered document, whitespace-normalized.
     */
    protected function container(string $html): string
    {
        $matched = preg_match(
            '/<(blockquote|aside|div)\b.*?<\/\1>/s',
            $html,
            $match,
        );
        $this->assertSame(1, $matched, 'no container rendered');

        return trim((string)preg_replace('/\s+/', ' ', $match[0]));
    }

    /**
     * A BLANK LINE IS THE ONLY EXIT FROM THE LAZY RUN. Without it the run would
     * swallow the next block, so this is the bound the skip actually needs -
     * the flush-left line above does NOT end it, which is what the `prose then
     * a definition` row says.
     */
    public function testABlankLineEndsTheLazyRun(): void
    {
        $html = $this->converter->convert("[^f]: b\n\n  > q\n\n   [r]: /url\n\nSee [r][] and [^f].");

        $this->assertStringContainsString('href="/url"', $html);
    }

    /**
     * A DEFINITION AT THE CONTAINER'S OWN COLUMN IS STILL CONSUMED. Corpus 202
     * and 220 pin that for a bare body, and it holds inside these containers
     * too - the fix is about a line written PAST the column, not about every
     * definition. Without this row the change is satisfied by a rule that never
     * consumes anything.
     */
    public function testADefinitionAtTheContainersColumnIsStillConsumed(): void
    {
        $html = $this->converter->convert("[^f]: b\n\n  > [r]: /url\n\nSee [r][] and [^f].");

        $this->assertStringContainsString('href="/url"', $html);
        $this->assertStringNotContainsString('[r]: /url', $html);
    }

    /**
     * A NARROWER BARE RUN INSIDE A WIDER DIV IS NOT ITS CLOSER. A `:{3,}` scan
     * took the `:::` for the `::::` div's closer and handed the definition back
     * to the body; `colonFenceEnd()` matches the opener's EXACT width.
     *
     * Asserted as the invariant only, not against the top-level twin: these
     * fences nest rather than close cleanly, so at top level the trailing
     * paragraph lands INSIDE the div while in a note the body ends first. That
     * difference belongs to the document, not to the rule.
     */
    public function testANarrowerBareRunIsNotTheCloser(): void
    {
        $html = $this->converter->convert(
            "[^f]: b\n\n  :::: note\n  :::\n   [r]: /url\n  ::::\n\nSee [r][] and [^f].",
        );

        $this->assertStringContainsString('[r]: /url', $html);
        $this->assertStringNotContainsString('href="/url"', $html);
    }

    /**
     * THE DIV EXTENT IS READ BY `colonFenceEnd()`, the reader the parser
     * itself uses: a closer matches the opener's EXACT width, a nested pair
     * keeps its own, and a bare run inside a code fence is payload. The `a
     * wide div holding a narrower bare colon run` row is what a plain
     * `:{3,}` scan got wrong.
     *
     * A DIV'S EXTENT IS ITS FENCES, A QUOTE'S IS ITS LAZY RUN. That is why the
     * two are separate arms and not one loop: a div owns everything to its
     * closer INCLUDING visible blocks, while a quote's run stops at the first
     * visible opener so `testAVisibleOpenerBelowAQuoteKeepsItsBase` still
     * holds. Written as one loop, the div row above lost its definition.
     */
    public function testADivOwnsItsContentPastAVisibleBlock(): void
    {
        $html = $this->converter->convert(
            "[^f]: b\n\n  ::: note\n  # h\n   [r]: /url\n  :::\n\nSee [r][] and [^f].",
        );

        $this->assertStringContainsString('[r]: /url', $html);
        $this->assertStringNotContainsString('href="/url"', $html);
    }

    /**
     * A VISIBLE OPENER BELOW A QUOTE KEEPS ITS AUTHORED BASE. carve#1729 gives
     * a div fence written four columns in its own base even under a quote,
     * which is what lets the quote
     * linter see it as a sibling; only an INVISIBLE block is held by the
     * container. This is the row that failed when the guard was written without
     * that split.
     */
    public function testAVisibleOpenerBelowAQuoteKeepsItsBase(): void
    {
        $html = $this->converter->convert("[^a]: > q\n      ::: >\n      b\n      :::\n\nsee[^a]");

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringNotContainsString('::: &gt;', $html);
    }
}
