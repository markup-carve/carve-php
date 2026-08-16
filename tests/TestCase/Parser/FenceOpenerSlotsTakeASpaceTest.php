<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The code fence, frontmatter and raw-block openers take a space in every slot.
 *
 * `resources/grammar.ebnf` PART 7, MARKER SEPARATORS AND PADDING SLOTS, decides
 * the terminal by POSITION rather than by role: "A tab is syntax ONLY in a
 * line's LEADING INDENTATION RUN. From the first non-whitespace character of the
 * line onward a tab is not relevant to syntax at all: it satisfies no slot in
 * any production, and no construct is recognized by it."
 *
 * Every slot pinned here sits after the first non-whitespace character of its
 * line, so every one of them is spelled `space`:
 *
 * - `fenced_code_block = code_fence_open, [space], [code_fence_info], newline`
 * - `code_fence_info = ( language_info, [space+, quoted_title], [space+, label] )
 *   | ( quoted_title, [space+, label] ) | label`
 * - `frontmatter_open = "---", [space], [frontmatter_format], newline`
 * - `raw_block = code_fence_open, [space], "=", format_name, newline`
 *
 * The ROLES differ and the clause says so - the code fence's slots and the
 * frontmatter format slot are PADDING (the fence run, and the `---` pair, have
 * already decided the block), while the raw block's is a SEPARATOR (the `=`
 * after it selects a raw block over a code block) - but "identical shape,
 * different role, same terminal". What the role decides is the FAILURE, not the
 * terminal, and every failure below lands on prose.
 *
 * Each site got in through a different door, so each is measured on its own:
 * the code fence read its slot with `trim()`, whose default charlist
 * `" \t\n\r\0\x0B"` admits a tab AND a vertical tab; frontmatter spelled the
 * slot `[ \t]*`, so a tab only; the raw block used PCRE `\s`, i.e.
 * `[ \t\n\r\f\v]`, the widest of the three. That is why the vertical tab and the
 * form feed have their own rows rather than riding along with the tab: one
 * fixture must not stand for a divergence it never covered.
 */
class FenceOpenerSlotsTakeASpaceTest extends TestCase
{
    /**
     * Every whitespace character that is not U+0020, per slot.
     *
     * MIXED RUNS IN BOTH DIRECTIONS. The rule is about a RUN, so a check on the
     * FIRST whitespace character after a token is not a check on the rule: two
     * of the three sites carried exactly that shape, a first-character test
     * followed by a `trim()`/`ltrim()` whose default charlist then stripped the
     * tab the test was meant to reject. `<SP><TAB>` is the row that catches it;
     * `<TAB><SP>` is the row that catches a fix which only looked at the LAST
     * character instead.
     *
     * @var array<string, string>
     */
    private const NON_SPACE_RUNS = [
        'a tab' => "\t",
        'a vertical tab' => "\v",
        'a form feed' => "\f",
        'a space then a tab' => " \t",
        'a tab then a space' => "\t ",
    ];

    /**
     * The six slot shapes a code-fence opener has, one row per slot per run.
     *
     * ONE SLOT PER ROW. Each template leaves a SPACE at every slot it is not
     * testing, so narrowing one slot cannot make another slot's row pass. A
     * fixture with a tab at two slots at once discriminates nothing: either
     * narrowing alone already rejects it.
     *
     * `code_fence_info`'s `[label]` appears in two of the three alternatives and
     * is one slot with one role, so both spellings are pinned - otherwise
     * ```` ```js "T" [L] ```` and ```` ``` "T" [L] ```` could disagree about a
     * tab for no reason a reader could state.
     *
     * @return array<string, array{0: string}>
     */
    public static function codeFenceOpenerProvider(): array
    {
        $templates = [
            'info slot' => '```%sjs',
            'header slot after a language' => '``` js%s"T"',
            'label slot after a language and a header' => '``` js "T"%s[l]',
            'label slot with no language and no header' => '```%s[l]',
            'header slot with no language' => '```%s"T"',
            'label slot after a header with no language' => '``` "T"%s[l]',
        ];

        $rows = [];
        foreach ($templates as $slot => $template) {
            foreach (self::NON_SPACE_RUNS as $runName => $run) {
                $rows["{$slot}, {$runName}"] = [sprintf($template, $run)];
            }
        }

        return $rows;
    }

    /**
     * A space still fills each of those six slots, and a RUN fills only some.
     *
     * The control per slot: narrowing must not close the door on the spelling
     * the grammar does admit. Cardinality is decided per PRODUCTION, and the
     * two answers sit one token apart on this very line. The fence's OPENER
     * slot is spelled `[space]` and takes exactly one (carve#912), so its run
     * row moved to {@see self::runFilledCodeFenceOpenerProvider()};
     * `code_fence_info`'s own `"header"` and `[label]` slots are spelled
     * `space+` and keep their runs, which is why they are still here.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spacedCodeFenceOpenerProvider(): array
    {
        return [
            'info slot, one space' => ['``` js', 'language-js'],
            'info slot, no space at all (canonical)' => ['```js', 'language-js'],
            'header slot after a language, one space' => ['``` js "T"', 'title="T"'],
            'header slot after a language, a run of spaces' => ['``` js   "T"', 'title="T"'],
            'label slot after a language and a header, one space' => ['``` js "T" [l]', 'title="T"'],
            'label slot after a language and a header, a run of spaces' => ['``` js "T"   [l]', 'title="T"'],
            'label slot with no language and no header, one space' => ['``` [l]', '<pre>'],
            'header slot with no language, one space' => ['``` "T"', 'title="T"'],
            'label slot after a header with no language, one space' => ['``` "T" [l]', 'title="T"'],
        ];
    }

    /**
     * The opener slot with an EMPTY info string, one row per run per fence
     * character.
     *
     * THE SHAPE EVERY TEMPLATE ABOVE MISSED. `codeFenceOpenerProvider()` puts a
     * non-empty token after the run in all six of its templates, so all thirty
     * of its rows were decided by the INFO-STRING parse rather than by the slot:
     * ```` ```<TAB>js ```` fell back because `js` was unreachable behind a tab,
     * not because the tab failed the slot. With nothing after the run the info
     * parse has nothing to refuse, and the slot check is the only thing left.
     *
     * WHAT IS LEFT HERE AFTER carve#1295, and why it is no longer the tab. The
     * ruling split this line by POSITION: a run before content is a SEPARATOR
     * and the slot rule governs, a run at end of line is TRAILING whitespace and
     * PART 2 drops it. So the tab rows moved out of this provider and into
     * {@see self::trailingRunOpenerProvider()} - with nothing after it, a tab
     * here is trailing and the fence OPENS.
     *
     * The vertical tab and the form feed stay, and they stay for a reason that
     * has nothing to do with the tab ruling: PART 1 spells the terminal
     * `whitespace = ' ' | '\t'`, so neither of them is whitespace in this
     * language at all. They are ordinary CONTENT sitting in the info-string
     * position, `language_info` cannot match them, and the opener falls back -
     * exactly as it would for any other unmatchable token. Nothing about
     * trailing-whitespace stripping reaches them, because there is no trailing
     * whitespace on those lines.
     *
     * That is why they are not merely redundant rows: if a future narrowing
     * widened PART 2's trailing-whitespace set past `space` and `tab`, these two
     * would flip and this provider is what notices.
     *
     * Both fence characters, because the slot is read once for either and a fix
     * that reached only the backtick path would be invisible here otherwise.
     *
     * @return array<string, array{0: string}>
     */
    public static function emptyInfoOpenerProvider(): array
    {
        $rows = [];
        foreach (['a vertical tab' => "\v", 'a form feed' => "\f"] as $runName => $run) {
            foreach (['a backtick fence' => '```', 'a tilde fence' => '~~~'] as $fenceName => $fence) {
                $rows["{$fenceName}, {$runName}"] = ["{$fence}{$run}"];
            }
        }

        return $rows;
    }

    #[DataProvider('emptyInfoOpenerProvider')]
    public function testANonSpaceRunAloneDoesNotOpenAFence(string $opener): void
    {
        $fence = substr($opener, 0, 3);

        $this->assertStringNotContainsString('<pre', $this->html("{$opener}\nx\n{$fence}\n"));
    }

    /**
     * A whitespace run at END OF LINE is trailing, and the fence opens.
     *
     * THE OTHER HALF OF THE POSITION SPLIT (carve#1295). The rule that shipped
     * as "a tab after the opener disqualifies the fence" reached a position it
     * does not own. With nothing after it the tab is not filling a slot, because
     * there is no content for it to be a separator FROM - it is trailing
     * whitespace, PART 2 drops it, and what is left is a bare fence opener,
     * which opens.
     *
     * The asymmetry that makes the overshoot visible, and the reason this is not
     * a taste call: ```` ```<SP><SP><SP> ```` opened and ```` ```<TAB> ````
     * refused. Two whitespace-only tails, two answers, told apart by nothing but
     * which whitespace character the author typed. The grammar already refuses
     * that shape of rule at MARKER REQUIRES CONTENT - "The rule ignores trailing
     * whitespace, so `-` and `- ` behave identically (an editor stripping the
     * trailing space cannot change the meaning)" - and an editor stripping a
     * trailing TAB must not change this line's meaning either. Every row here is
     * a line some editor's save hook rewrites into the bare `` ``` `` on the
     * last row.
     *
     * `<TAB><SP>` and `<SP><TAB>` are both here, in both orders, because a fix
     * that inspected only the FIRST or only the LAST character of the run would
     * pass one and fail the other.
     *
     * @return array<string, array{0: string}>
     */
    public static function trailingRunOpenerProvider(): array
    {
        $runs = [
            'a tab' => "\t",
            'two tabs' => "\t\t",
            'a tab then a space' => "\t ",
            'a space then a tab' => " \t",
            'a run of spaces' => '   ',
            'nothing at all (the stripped form)' => '',
        ];

        $rows = [];
        foreach ($runs as $runName => $run) {
            foreach (['a backtick fence' => '```', 'a tilde fence' => '~~~'] as $fenceName => $fence) {
                $rows["{$fenceName}, {$runName}"] = ["{$fence}{$run}"];
            }
        }

        return $rows;
    }

    #[DataProvider('trailingRunOpenerProvider')]
    public function testATrailingRunAloneStillOpensTheFence(string $opener): void
    {
        $fence = substr($opener, 0, 3);

        // Asserted as "the block opened AND the run reached neither the info
        // string nor the content". A fence that opened and carried the tab into
        // a language class would satisfy a bare `<pre` check while still
        // treating the trailing run as a token.
        $out = $this->html("{$opener}\nx\n{$fence}\n");

        $this->assertStringContainsString('<pre><code>x', $out);
        $this->assertStringNotContainsString('class="language-', $out);
    }

    /**
     * The SEPARATOR half, at the same slot, one row per fence character.
     *
     * The other side of the split, kept adjacent on purpose: with content after
     * it the very same tab IS a separator, does not satisfy the `space`
     * terminal, and the fence does not open. Without this pair the narrowing
     * above could be taken all the way to "a tab after the opener is always
     * fine", which is what carve#1295 explicitly did not rule.
     *
     * THIS IS NOW THE ENFORCEMENT, not a second opinion on it. Narrowing the
     * opener's explicit slot test to "content follows" left a test that could
     * not fail - `language_info` already refuses a tab-led info string two
     * branches later - so it was removed rather than shipped as decoration.
     * What holds the separator half up is therefore this behavior test and the
     * language-token character class, and the two are one edit apart: widen
     * `[A-Za-z0-9_\-+#./]+` to admit whitespace and these rows go red, which is
     * exactly the coupling a dead guard would have hidden.
     *
     * @return array<string, array{0: string}>
     */
    public static function separatorRunOpenerProvider(): array
    {
        $rows = [];
        foreach (['a tab' => "\t", 'a tab then a space' => "\t ", 'two tabs' => "\t\t"] as $runName => $run) {
            foreach (['a backtick fence' => '```', 'a tilde fence' => '~~~'] as $fenceName => $fence) {
                $rows["{$fenceName}, {$runName} before content"] = ["{$fence}{$run}js"];
            }
        }

        return $rows;
    }

    #[DataProvider('separatorRunOpenerProvider')]
    public function testARunBeforeContentStillRefusesToOpen(string $opener): void
    {
        $fence = substr($opener, 0, 3);
        $out = $this->html("{$opener}\nx\n{$fence}\n");

        $this->assertStringNotContainsString('<pre', $out);
        $this->assertStringNotContainsString('language-js', $out);
    }

    /**
     * The CLOSER is not touched by this ruling, and that is a claim worth
     * pinning.
     *
     * A closer takes no content after its marker, so a tab there is ALWAYS
     * trailing and never a separator - the position split has only one side at
     * this end of the run. carve-php already accepts a tab-padded closer, which
     * carve#1295 confirms is correct, and carve-rs is the engine that changes.
     *
     * This test exists because the natural instinct when narrowing the opener is
     * to sweep both ends of the fence toward one answer. They are governed by
     * the same clause and land on OPPOSITE sides of it, so a change at the
     * closer would be a regression rather than consistency.
     */
    public function testATabPaddedCloserStillCloses(): void
    {
        $out = $this->html("```php\nx\n```\t\n");

        $this->assertStringContainsString('<pre><code class="language-php">x', $out);
        $this->assertStringNotContainsString('```', $out);
    }

    /**
     * The empty-info control: the slot's own spelling still opens the fence.
     *
     * Without this, narrowing the slot to reject everything would pass every
     * row above. Both the filled slot and the canonical no-separator form are
     * here, since they are two different paths through the same three lines.
     *
     * @return array<string, array{0: string}>
     */
    public static function emptyInfoSpacedOpenerProvider(): array
    {
        return [
            'a backtick fence, one space' => ['``` '],
            'a tilde fence, one space' => ['~~~ '],
            'a backtick fence, no separator at all' => ['```'],
            'a tilde fence, no separator at all' => ['~~~'],
        ];
    }

    #[DataProvider('emptyInfoSpacedOpenerProvider')]
    public function testAnEmptyInfoStringStillOpensOnASpace(string $opener): void
    {
        $fence = substr($opener, 0, 3);

        $this->assertStringContainsString('<pre', $this->html("{$opener}\nx\n{$fence}\n"));
    }

    /**
     * A run AFTER a slot the space already filled is trailing, and tolerated.
     *
     * The slot rule is about the character that fills the SLOT. Trailing
     * whitespace is not a slot in the grammar, so it stays as tolerant as it
     * was; narrowing it is a separate question the issue explicitly left open.
     * This row is what keeps a fix for the slot from being mistaken for one.
     */
    public function testATrailingRunAfterAFilledSlotStillOpens(): void
    {
        $this->assertStringContainsString('<pre', $this->html("``` \t\nx\n```\n"));
    }

    /**
     * The frontmatter format slot, one row per run.
     *
     * @return array<string, array{0: string}>
     */
    public static function frontmatterOpenerProvider(): array
    {
        $rows = [];
        foreach (self::NON_SPACE_RUNS as $runName => $run) {
            $rows["format slot, {$runName}"] = ["---{$run}yaml"];
        }

        return $rows;
    }

    /**
     * The raw block's format slot, one row per run.
     *
     * @return array<string, array{0: string}>
     */
    public static function rawBlockOpenerProvider(): array
    {
        $rows = [];
        foreach (self::NON_SPACE_RUNS as $runName => $run) {
            $rows["format slot, {$runName}"] = ["```{$run}=html"];
        }

        return $rows;
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    #[DataProvider('codeFenceOpenerProvider')]
    public function testANonSpaceRunLeavesTheCodeFenceLineAsProse(string $opener): void
    {
        // Asserted as "no code block was opened AND the metadata never
        // arrived". Checking only for the absence of `title="T"` would pass for
        // a fence that opened with the metadata silently dropped, which is a
        // different (and also wrong) outcome from the prose fallback the
        // grammar asks for.
        $out = $this->html("{$opener}\nx\n```\n");

        $this->assertStringNotContainsString('<pre', $out);
        $this->assertStringNotContainsString('title="T"', $out);
        $this->assertStringNotContainsString('language-js', $out);
    }

    #[DataProvider('spacedCodeFenceOpenerProvider')]
    public function testASpaceStillFillsEveryCodeFenceSlot(string $opener, string $marker): void
    {
        $out = $this->html("{$opener}\nx\n```\n");

        $this->assertStringContainsString('<pre', $out);
        $this->assertStringContainsString($marker, $out);
    }

    #[DataProvider('frontmatterOpenerProvider')]
    public function testANonSpaceRunLeavesTheFrontmatterOpenerAsProse(string $opener): void
    {
        // The body is the tell. A frontmatter block is stripped from the
        // rendered output by default, so "the metadata is still visible" is the
        // observable form of "no frontmatter opened".
        $out = $this->html("{$opener}\na: 1\n---\nx\n");

        $this->assertStringContainsString('a: 1', $out);
    }

    public function testASpaceStillFillsTheFrontmatterFormatSlot(): void
    {
        foreach (['--- yaml', '---yaml'] as $opener) {
            $out = $this->html("{$opener}\na: 1\n---\nx\n");

            $this->assertStringNotContainsString('a: 1', $out, $opener);
            $this->assertSame("<p>x</p>\n", $out, $opener);
        }
    }

    /**
     * A RUN of spaces does not fill the two slots spelled `[space]`.
     *
     * Cardinality is per PRODUCTION, and this class now pins both answers. The
     * code fence's OPENER slot and `frontmatter_open`'s are two of the four
     * PART 7 names as exactly one space (carve#912); `code_fence_info`'s own
     * `"header"` and `[label]` slots are spelled `space+` and keep their runs,
     * which {@see self::testASpaceStillFillsEveryCodeFenceSlot()} still asserts
     * one token away on the same line.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function runFilledOpenerSlotProvider(): array
    {
        return [
            'code fence opener slot, two spaces' => ['```  js', 'language-js'],
            'code fence opener slot, three spaces' => ['```   js', 'language-js'],
        ];
    }

    #[DataProvider('runFilledOpenerSlotProvider')]
    public function testARunDoesNotFillTheCodeFenceOpenerSlot(string $opener, string $marker): void
    {
        // The INVALID-FENCE FALLBACK, which is what PART 7 promises when a
        // padding slot leaves a token unconsumed: `language_info` cannot match a
        // space, the opener matches no shape, and the run becomes an inline
        // verbatim span in a paragraph. Asserting only "no `language-js`" would
        // also pass for a fence that opened and dropped its info string.
        $out = $this->html("{$opener}\nx\n```\n");

        $this->assertStringNotContainsString('<pre', $out);
        $this->assertStringNotContainsString($marker, $out);
        $this->assertStringContainsString('<p><code>', $out);
    }

    public function testARunDoesNotFillTheFrontmatterFormatSlot(): void
    {
        foreach (['---  yaml', '---   yaml'] as $opener) {
            // The body is the tell, as in the tab rows: frontmatter is stripped
            // from the rendered output, so metadata still being visible is the
            // observable form of "no frontmatter opened".
            $out = $this->html("{$opener}\na: 1\n---\nx\n");

            $this->assertStringContainsString('a: 1', $out, $opener);
        }
    }

    #[DataProvider('rawBlockOpenerProvider')]
    public function testANonSpaceRunLeavesTheRawBlockOpenerAsProse(string $opener): void
    {
        // A raw block emits its content UNESCAPED. So the tell is the escape:
        // if `<b>` came back as `&lt;b&gt;`, no raw block opened.
        $out = $this->html("{$opener}\n<b>x</b>\n```\n");

        $this->assertStringContainsString('&lt;b&gt;', $out);
        $this->assertStringNotContainsString('<b>x</b>', $out);
    }

    public function testASpaceStillFillsTheRawBlockFormatSlot(): void
    {
        foreach (['``` =html', '```   =html', '```=html'] as $opener) {
            $out = $this->html("{$opener}\n<b>x</b>\n```\n");

            $this->assertStringContainsString('<b>x</b>', $out, $opener);
            $this->assertStringNotContainsString('&lt;b&gt;', $out, $opener);
        }
    }

    public function testEverySlotAndEveryRunIsStillCovered(): void
    {
        // A row silently dropped from a provider would take its slot's or its
        // character's coverage with it and nothing else here would fail. The
        // three sites reached the three different widths through three
        // different mechanisms, so a shrinking run list is a real regression in
        // what this file proves, not a tidy-up.
        //
        // `emptyInfoOpenerProvider()` went from 8 rows to 4 at carve#1295, and
        // that is the one shrink here that is NOT a regression: the tab and
        // tab-space rows did not disappear, they MOVED to
        // `trailingRunOpenerProvider()` with their expectation inverted, because
        // the ruling put a run at end of line on the other side of the split.
        // The two counts below are asserted together so the move stays visible
        // as a move - deleting those rows outright now fails the second count.
        $this->assertCount(5, self::NON_SPACE_RUNS);
        $this->assertCount(4, self::emptyInfoOpenerProvider());
        $this->assertCount(12, self::trailingRunOpenerProvider());
        $this->assertCount(6, self::separatorRunOpenerProvider());
        $this->assertCount(4, self::emptyInfoSpacedOpenerProvider());
        $this->assertCount(30, self::codeFenceOpenerProvider());
        $this->assertCount(9, self::spacedCodeFenceOpenerProvider());
        $this->assertCount(2, self::runFilledOpenerSlotProvider());
        $this->assertCount(5, self::frontmatterOpenerProvider());
        $this->assertCount(5, self::rawBlockOpenerProvider());

        $this->assertSame(
            ["\t", "\v", "\f", " \t", "\t "],
            array_values(self::NON_SPACE_RUNS),
        );
    }
}
