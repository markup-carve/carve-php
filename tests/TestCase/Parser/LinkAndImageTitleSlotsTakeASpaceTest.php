<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The link and image title slots take a space.
 *
 * `resources/grammar.ebnf` spells the slot `link_title = space, ('"', ... )`,
 * and `image_title = link_title` inherits it. The slot sits after the first
 * non-whitespace character of the line, so PART 7's MARKER SEPARATORS AND
 * PADDING SLOTS clause applies: a tab is syntax ONLY in a line's leading
 * indentation run, and from there onward it satisfies no slot in any
 * production.
 *
 * THE FALLBACK IS NOT "A LINK WITHOUT A TITLE". Once the slot is not filled,
 * the quoted run is left unconsumed and lands inside the destination, which
 * admits no whitespace at all - so there is no link. The bracket run stays
 * literal text and the run the author typed survives in the output:
 *
 * `[t](/u<TAB>"T")` renders as `<p>[t](/u<TAB>&ldquo;T&rdquo;)</p>` - the tab is
 * written `<TAB>` here because a literal one is not allowed in a doc block; the
 * fixtures below carry the real character.
 *
 * ONE NARROWING SERVES BOTH TAILS. The same block reads the link tail and the
 * image tail, so the image needs no separate code change - which is exactly why
 * it gets its own fixtures. The day those paths split, nothing else here would
 * notice.
 *
 * THE MECHANISM WAS DIFFERENT FROM THE FENCE FAMILY'S. carve-php#955 narrowed
 * the fence, frontmatter and raw-block slots, and none of that patch was
 * reusable here: this site used PCRE `\s` with no `/u` modifier, so its class
 * was `[ \t\n\r\f\v]` - tab, form feed, vertical tab and a line break in, NBSP
 * and U+2000 out. That is why the vertical tab, the form feed and the line
 * break each get a row of their own rather than riding along with the tab.
 *
 * CARDINALITY IS NOW IN SCOPE, and the two-space rows that used to be controls
 * have changed sides. markup-carve/carve#912 ruled that this slot means exactly
 * ONE space and that all four artifacts accepting a run are lax; that ruling
 * covers four slots at once - this one, the reference-definition attributes
 * slot, and the code-fence and frontmatter openers - and lands corpus categories
 * 262 to 265. The run rows are asserted as WHOLE renderings in
 * {@see self::testARunDoesNotFillTheTitleSlot()}, so they pin the prose fallback
 * rather than merely the absence of a title.
 *
 * The reference definition's own title slot is the same production read through
 * a different expression in `src/Parser/ReferenceDefinitionExtractor.php`, and
 * it is narrowed alongside this one - see
 * {@see \MarkupCarve\Carve\Test\TestCase\Parser\ReferenceDefinitionMetadataSlotsTakeOneSpaceTest}.
 */
class LinkAndImageTitleSlotsTakeASpaceTest extends TestCase
{
    /**
     * Every whitespace run in the old class that is not made of U+0020 alone.
     *
     * MIXED RUNS IN BOTH DIRECTIONS. The slot is one terminal followed by a
     * run, so a check on the FIRST whitespace character after the destination
     * is not a check on the rule: written as "the first character is a space,
     * then eat whitespace" it passes a tab-first fixture and admits
     * `<SP><TAB>`; written against the LAST character it admits `<TAB><SP>`.
     * Both spellings have been written for real in this org, in three
     * languages, on one day - and one of them was in this repository.
     *
     * The line break is left out of this list on purpose and gets its own test:
     * its literal fallback keeps the break as a soft line break inside the
     * paragraph, so its expected output is a different shape from these.
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
     * One row per tail per run, asserted as whole output.
     *
     * The expectation is the ENTIRE rendered paragraph rather than an absence.
     * Checking only for the absence of `title="T"` would pass for an engine
     * that built a titleless link, or dropped the quoted run, or ate the tab -
     * three different wrong answers. The whole-output form states which one is
     * right: no link, and the run still there where it was typed.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function titleSlotProvider(): array
    {
        $rows = [];
        foreach (self::NON_SPACE_RUNS as $runName => $run) {
            $rows["link title slot, {$runName}"] = [
                "[t](/u{$run}\"T\")",
                "<p>[t](/u{$run}\u{201C}T\u{201D})</p>\n",
            ];
            $rows["image title slot, {$runName}"] = [
                "![a](/p.png{$run}\"T\")",
                "<p>![a](/p.png{$run}\u{201C}T\u{201D})</p>\n",
            ];
        }

        // The slot is one production with three delimiter spellings, so all
        // three move together or the code and the grammar disagree about which
        // quote makes a title.
        $rows['single-quoted title slot, a tab'] = [
            "[t](/u\t'T')",
            "<p>[t](/u\t\u{2018}T\u{2019})</p>\n",
        ];
        $rows['parenthesized title slot, a tab'] = [
            "[t](/u\t(T))",
            "<p>[t](/u\t(T))</p>\n",
        ];

        return $rows;
    }

    /**
     * A space still fills the slot, at every tail and every delimiter.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spacedTitleSlotProvider(): array
    {
        return [
            'link, one space' => ['[t](/u "T")', 'title="T"'],
            'image, one space' => ['![a](/p.png "T")', 'title="T"'],
            'link, single quotes, one space' => ["[t](/u 'T')", 'title="T"'],
            'link, parentheses, one space' => ['[t](/u (T))', 'title="T"'],
            'link with attributes, one space' => ['[t](/u "T"){.x}', 'title="T"'],
        ];
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    #[DataProvider('titleSlotProvider')]
    public function testANonSpaceRunLeavesTheBracketRunLiteral(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    #[DataProvider('spacedTitleSlotProvider')]
    public function testASpaceStillFillsTheTitleSlot(string $source, string $marker): void
    {
        $this->assertStringContainsString($marker, $this->html($source));
    }

    /**
     * A RUN of spaces does not fill the slot either.
     *
     * `link_title = space, ('"' …)` is one of the four slots PART 7 holds to
     * exactly one space (carve#912), and this class previously pinned the run
     * as accepted on the reading that cardinality was a separate question left
     * alone. It is not left alone any more, and the four artifacts that agreed
     * with each other about the run were the ones that moved.
     *
     * All three delimiter spellings are here because they are ONE production:
     * a fix that narrowed the double-quoted alternative alone would leave the
     * code and the grammar disagreeing about which quote makes a title.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function runFilledTitleSlotProvider(): array
    {
        return [
            'link, two spaces' => ['[t](/u  "T")', "<p>[t](/u  \u{201C}T\u{201D})</p>\n"],
            'link, three spaces' => ['[t](/u   "T")', "<p>[t](/u   \u{201C}T\u{201D})</p>\n"],
            'image, two spaces' => ['![a](/p.png  "T")', "<p>![a](/p.png  \u{201C}T\u{201D})</p>\n"],
            'link, single quotes, two spaces' => ["[t](/u  'T')", "<p>[t](/u  \u{2018}T\u{2019})</p>\n"],
            'link, parentheses, two spaces' => ['[t](/u  (T))', "<p>[t](/u  (T))</p>\n"],
        ];
    }

    #[DataProvider('runFilledTitleSlotProvider')]
    public function testARunDoesNotFillTheTitleSlot(string $source, string $expected): void
    {
        // Asserted as the WHOLE rendering, not as "no title attribute". The
        // failure PART 7 asks for is the bracket run surviving as text; a link
        // that opened with its title silently dropped would satisfy a
        // `assertStringNotContainsString('title=')` and is a different outcome.
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * A line break does not fill the slot either.
     *
     * It was in the old class for the same reason the tab was - PCRE `\s` holds
     * it - and it is not U+0020, so it goes the same way. It is asserted on its
     * own because its literal fallback keeps the break as a soft line break in
     * the paragraph, which is a different expected shape from the others'
     * single-line one.
     */
    public function testALineBreakDoesNotFillTheTitleSlot(): void
    {
        $this->assertSame(
            "<p>[t](/u\n\u{201D}T\u{201D})</p>\n",
            $this->html("[t](/u\n\"T\")"),
        );
    }

    /**
     * The narrowing is about the SLOT, not about the title's contents.
     *
     * `link_title`'s body is `character - '"'`, which includes a tab. A fix
     * written as "no tab anywhere in the tail" would pass every row above and
     * still be wrong here, so this row is what keeps the two apart.
     */
    public function testATabInsideTheTitleIsContent(): void
    {
        $this->assertStringContainsString("title=\"a\tb\"", $this->html("[t](/u \"a\tb\")"));
    }

    public function testEveryTailAndEveryRunIsStillCovered(): void
    {
        // A row silently dropped from a provider would take its tail's or its
        // character's coverage with it and nothing else here would fail. The
        // old class was `[ \t\n\r\f\v]` and every member of it that is not a
        // space is represented, here or in the line-break test.
        $this->assertCount(5, self::NON_SPACE_RUNS);
        $this->assertCount(12, self::titleSlotProvider());
        $this->assertCount(5, self::spacedTitleSlotProvider());
        $this->assertCount(5, self::runFilledTitleSlotProvider());

        $this->assertSame(
            ["\t", "\v", "\f", " \t", "\t "],
            array_values(self::NON_SPACE_RUNS),
        );
    }
}
