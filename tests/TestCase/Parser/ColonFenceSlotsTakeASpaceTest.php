<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every whitespace slot on a colon-fence opener is a literal space.
 *
 * `resources/grammar.ebnf` PART 7, MARKER SEPARATORS AND PADDING SLOTS, decides
 * the terminal by POSITION rather than by role: "A tab is syntax ONLY in a
 * line's LEADING INDENTATION RUN. From the first non-whitespace character of the
 * line onward a tab is not relevant to syntax at all." Every slot on this line
 * sits after the fence run, so every slot is `space`, and `admonition_open` is
 * spelled `colon_fence:open, space, admonition_type, [space+, quoted_title],
 * [space+, label]`.
 *
 * The two ROLES survive, but only to decide what a FAILED match means:
 *
 * - The slot immediately after the fence run is a MARKER SEPARATOR, because the
 *   token after it selects which of the four blocks the line opens. A failed
 *   match leaves the line unrecognized as that construct.
 * - The admonition opener's title and label slots are PADDING, because the type
 *   word has already decided the block. A failed match leaves a token
 *   unconsumed and the surrounding production then rejects the line.
 *
 * Both failures land on prose, so both halves are pinned here. carve#886 read
 * the padding slots as `whitespace`; carve#905 reverted that reading, because
 * the question is not what a slot recognizes but where it sits.
 */
class ColonFenceSlotsTakeASpaceTest extends TestCase
{
    /**
     * A tab anywhere in the run leaves the line as prose.
     *
     * ONE FIXTURE PAIR PER SLOT, and each row isolates its own slot: the title
     * rows carry no label at all, and the label-after-a-title rows put a SPACE
     * before the title so only the label slot is under test. A fixture with a
     * tab at BOTH slots cannot discriminate - narrowing either slot alone
     * already rejects that line, so it would pass while the other slot was
     * still wrong. That case is kept below as a shape check only, and it says
     * so.
     *
     * MIXED RUNS, per slot, in both directions. The rule is about a RUN
     * (`space+`), not about the first whitespace character after the token, so
     * a fix that only inspected that first character would still let
     * `::: note<SP><TAB>"T"` through.
     *
     * @return array<string, array{0: string}>
     */
    public static function tabbedOpenerProvider(): array
    {
        return [
            'separator, a tab' => [":::\tnote"],
            'separator, a space then a tab' => ["::: \tnote"],
            'separator, a tab then a space' => [":::\t note"],
            'title slot, a tab' => ["::: note\t\"T\""],
            'title slot, a space then a tab' => ["::: note \t\"T\""],
            'title slot, a tab then a space' => ["::: note\t \"T\""],
            'label slot with no title, a tab' => ["::: note\t[lbl]"],
            'label slot with no title, a space then a tab' => ["::: note \t[lbl]"],
            'label slot with no title, a tab then a space' => ["::: note\t [lbl]"],
            'label slot after a spaced title, a tab' => ["::: note \"T\"\t[lbl]"],
            'label slot after a spaced title, a space then a tab' => ["::: note \"T\" \t[lbl]"],
            'label slot after a spaced title, a tab then a space' => ["::: note \"T\"\t [lbl]"],
        ];
    }

    /**
     * A run of spaces still fills the slot.
     *
     * The control per row: narrowing the class must not close the door on the
     * spelling the grammar does admit, and `space+` is one or more.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spacedOpenerProvider(): array
    {
        return [
            'separator, one space' => ['::: note', 'admonition note'],
            'separator, a run of spaces' => [':::   note', 'admonition note'],
            'title slot, one space' => ['::: note "T"', 'admonition-title'],
            'title slot, a run of spaces' => ['::: note   "T"', 'admonition-title'],
            'label slot with no title' => ['::: note [lbl]', 'div-label'],
            'label slot with no title, a run of spaces' => ['::: note   [lbl]', 'div-label'],
            'label slot after a title' => ['::: note "T" [lbl]', 'div-label'],
            'label slot after a title, a run of spaces' => ['::: note "T"   [lbl]', 'div-label'],
        ];
    }

    /**
     * The other three colon-fence openers share the one separator slot.
     *
     * @return array<string, array{0: string}>
     */
    public static function otherOpenerTokenProvider(): array
    {
        return [
            'div label' => ['[lbl]'],
            'line block' => ['|'],
            'local hard break' => ['\\'],
        ];
    }

    /**
     * `whitespace` is `' ' | '\t'`, exhaustively, and a padding slot is
     * narrower still. These were spelled with the regex whitespace class,
     * which in PCRE is `[ \t\n\r\f\v]`.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonWhitespacePaddingProvider(): array
    {
        return [
            'form feed' => ["\f"],
            'vertical tab' => ["\v"],
            'carriage return' => ["\r"],
        ];
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    #[DataProvider('tabbedOpenerProvider')]
    public function testATabInTheRunLeavesTheLineAsProse(string $opener): void
    {
        // Asserted as "the opener line survives as text" rather than "there is
        // a paragraph": a div and a line block both WRAP a paragraph, so a
        // paragraph check passes for a container that should not have opened.
        $out = $this->html("{$opener}\nx\n:::\n");

        $this->assertStringContainsString(':::', $out);
        $this->assertStringNotContainsString('<aside', $out);
        $this->assertStringNotContainsString('admonition-title', $out);
    }

    #[DataProvider('spacedOpenerProvider')]
    public function testARunOfSpacesStillFillsTheSlot(string $opener, string $marker): void
    {
        $out = $this->html("{$opener}\nx\n:::\n");

        $this->assertStringContainsString('<aside', $out);
        $this->assertStringContainsString($marker, $out);
    }

    #[DataProvider('otherOpenerTokenProvider')]
    public function testATabAfterTheFenceRunOpensNothing(string $token): void
    {
        $out = $this->html(":::\t{$token}\nx\n:::\n");

        $this->assertStringContainsString(':::', $out);
        $this->assertStringNotContainsString('<aside', $out);
        $this->assertStringNotContainsString('<div', $out);
    }

    #[DataProvider('otherOpenerTokenProvider')]
    public function testASpaceThereStillOpensIt(string $token): void
    {
        $this->assertNotSame(
            $this->html("::: {$token}\nx\n:::\n"),
            $this->html(":::\t{$token}\nx\n:::\n"),
        );
    }

    #[DataProvider('nonWhitespacePaddingProvider')]
    public function testNothingButASpaceFillsAMetadataSlot(string $ws): void
    {
        $this->assertStringNotContainsString('admonition-title', $this->html("::: note{$ws}\"Title\"\nx\n:::\n"));
    }

    public function testATabAtBothSlotsIsProseButProvesNothingOnItsOwn(): void
    {
        // Kept for the shape, NOT as evidence: narrowing either slot alone
        // already rejects this line, so it would pass with the other slot
        // still wrong. The per-slot rows are what discriminate; the count
        // guard below is what keeps one from being deleted unnoticed.
        $this->assertStringNotContainsString('<aside', $this->html("::: note\t\"T\"\t[lbl]\nx\n:::\n"));
    }

    public function testEveryTabbedRowIsCheckedAndNoneOfThemOpensAnAdmonition(): void
    {
        // A row silently dropped from a provider would take its slot's
        // coverage with it and nothing else would fail.
        $this->assertCount(12, self::tabbedOpenerProvider());
        $this->assertCount(8, self::spacedOpenerProvider());

        $stillOpening = [];
        foreach (self::tabbedOpenerProvider() as $name => [$opener]) {
            if (str_contains($this->html("{$opener}\nx\n:::\n"), '<aside')) {
                $stillOpening[] = $name;
            }
        }

        $this->assertSame([], $stillOpening);
    }
}
