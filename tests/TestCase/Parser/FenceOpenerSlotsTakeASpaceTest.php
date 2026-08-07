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
        $this->assertCount(5, self::NON_SPACE_RUNS);
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
