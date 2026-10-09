<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §8c writes the §8c constructs with no Markdown delimiter spelling as
 * inline HTML. The importer read only a subset of those tags back, so a
 * construct survived the trip as bytes and was lost as a construct
 * (markup-carve/carve#2838). §10n's scaffolding header row came back as a
 * row of empty header cells, which this engine's own parser reads as a
 * paragraph rather than a header row (markup-carve/carve#2840).
 */
class MarkdownImportReadsBackTheAttributedSectionEightCTagsTest extends TestCase
{
    private function markdown(string $carve): string
    {
        return (new MarkdownRenderer())->render((new CarveConverter())->parse($carve));
    }

    private function carve(string $markdown): string
    {
        return (new MarkdownToCarve())->convert($markdown);
    }

    private function round(string $carve): string
    {
        // Carve -> Markdown -> Carve, the trip the defect is measured on.
        return $this->carve($this->markdown($carve));
    }

    private function html(string $carve): string
    {
        return (new CarveConverter())->convert($carve);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function constructs(): array
    {
        return [
            'underline' => ["underline _u_ here\n", "underline <u>u</u> here\n"],
            'an editorial comment' => ["comment {#note#} here\n", "comment <span class=\"critic-comment\">note</span> here\n"],
            'an abbreviation' => ["abbr [HTML]{abbr=\"Hyper Text\"} here\n", "abbr <abbr title=\"Hyper Text\">HTML</abbr> here\n"],
        ];
    }

    #[DataProvider('constructs')]
    public function testComesBackAsAConstructRatherThanARawSpan(string $carve, string $markdown): void
    {
        $this->assertSame($markdown, $this->markdown($carve));
        $this->assertSame($carve, $this->round($carve));
        $this->assertStringNotContainsString('{=html}', $this->carve($markdown));
    }

    public function testRendersTheElementFromAConstructRatherThanFromARawSpan(): void
    {
        $carve = "a _u_ {#note#} [HTML]{abbr=\"Hyper Text\"} b\n";
        $imported = $this->round($carve);
        $this->assertStringNotContainsString('{=html}', $imported);
        $this->assertSame($this->html($carve), $this->html($imported));
    }

    public function testAnUnderlineTheBareFormCannotSpellIsBraced(): void
    {
        $this->assertSame("a{_x_}b\n", $this->carve("a<u>x</u>b\n"));
        $this->assertSame("a {_ y _} b\n", $this->carve("a <u> y </u> b\n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unmigratableTags(): array
    {
        return [
            'an attribute beside the one §8c writes' => ["a <abbr title=\"T\" class=\"x\">HTML</abbr> b\n"],
            'a second attribute on the comment span' => ["a <span class=\"critic-comment\" id=\"k\">note</span> b\n"],
            'a body that would break out of the label' => ["a <abbr title=\"T\">x [y] z</abbr> b\n"],
            'a body that would break out of the comment' => ["a <span class=\"critic-comment\">x {y} z</span> b\n"],
            'a span of another class' => ["a <span class=\"other\">note</span> b\n"],
        ];
    }

    #[DataProvider('unmigratableTags')]
    public function testATagTheConstructCannotCarryStaysARawSpan(string $markdown): void
    {
        $this->assertStringContainsString('{=html}', $this->carve($markdown));
    }

    public function testAnEntityInTheTitleReachesTheAttribute(): void
    {
        $this->assertSame("a [X]{abbr=\"A & B\"} b\n", $this->carve("a <abbr title=\"A &amp; B\">X</abbr> b\n"));
    }

    /**
     * §8c spells a deletion `<del class="critic-delete">` and leaves a bare
     * `<del>` meaning `strike`, so the two stop colliding on one tag
     * (markup-carve/carve#2845).
     */
    public function testADeletionAndASubstitutionRoundTrip(): void
    {
        $this->assertSame("delete <del class=\"critic-delete\">del</del> here\n", $this->markdown("delete {-del-} here\n"));
        $this->assertSame("delete {-del-} here\n", $this->round("delete {-del-} here\n"));
        $this->assertSame(
            "substitute <del class=\"critic-delete\">old</del><ins>new</ins> here\n",
            $this->markdown("substitute {~old~>new~} here\n"),
        );
        $this->assertSame("substitute {~old~>new~} here\n", $this->round("substitute {~old~>new~} here\n"));
    }

    /**
     * The control the ruling turns on: §8c's own worked example is a `strike`
     * written as a bare `<del>`, and a bare `<del>` must keep reading back as
     * a `strike` rather than as the deletion (markup-carve/carve#2845).
     */
    public function testABareDelIsStillAStrike(): void
    {
        $this->assertSame("f <del> </del> g\n", $this->markdown("f {~ ~} g\n"));
        $this->assertSame("f ~ ~ g\n", $this->carve("f <del> </del> g\n"));
        $this->assertSame("a ~x~ b\n", $this->carve("a <del>x</del> b\n"));
        $this->assertStringContainsString('<s>x</s>', $this->html($this->carve("a <del>x</del> b\n")));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unchangedConstructs(): array
    {
        return [
            'highlight' => ["highlight =hi= here\n"],
            'subscript' => ["subscript {,s,} here\n"],
            'superscript' => ["superscript {^s^} here\n"],
            'a critic insert' => ["insert {+ins+} here\n"],
            'a deletion' => ["delete {-del-} here\n"],
            'a substitution' => ["substitute {~old~>new~} here\n"],
            'strike' => ["strike ~str~ here\n"],
        ];
    }

    #[DataProvider('unchangedConstructs')]
    public function testAConstructThatAlreadyRoundTrippedStillDoes(string $carve): void
    {
        $this->assertSame($carve, $this->round($carve));
    }

    public function testAGfmStrikethroughStillReadsBackAsStrike(): void
    {
        $this->assertSame("strike ~str~ here\n", $this->carve("strike ~~str~~ here\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function scaffoldingRows(): array
    {
        return [
            'a headerless table' => ["|  |  |\n| --- | --- |\n| 1 | 2 |\n| 3 | 4 |\n", "| 1 | 2 |\n| 3 | 4 |\n"],
            'three columns' => ["|  |  |  |\n| --- | --- | --- |\n| 1 | 2 | 3 |\n", "| 1 | 2 | 3 |\n"],
            'inside a block quote' => ["> |  |  |\n> | --- | --- |\n> | 1 | 2 |\n", "> | 1 | 2 |\n"],
            'inside a list item' => ["- |  |  |\n  | --- | --- |\n  | 1 | 2 |\n", "- | 1 | 2 |\n"],
            'a blank body row' => ["| a | b |\n| --- | --- |\n|  |  |\n| 3 | 4 |\n", "|= a |= b |\n| 3 | 4 |\n"],
        ];
    }

    #[DataProvider('scaffoldingRows')]
    public function testARowWithNoCarveSpellingIsDropped(string $markdown, string $carve): void
    {
        $this->assertSame($carve, $this->carve($markdown));
    }

    public function testTheImportedTableHoldsNoParagraphOfPipes(): void
    {
        $imported = $this->carve("|  |  |\n| --- | --- |\n| 1 | 2 |\n| 3 | 4 |\n");
        $this->assertStringNotContainsString('|=', $imported);
        $this->assertStringNotContainsString('<p>', $this->html($imported));
    }

    public function testAHeaderlessTableRoundTripsThroughMarkdown(): void
    {
        $carve = "| 1 | 2 |\n| 3 | 4 |\n";
        $this->assertSame("|  |  |\n| --- | --- |\n| 1 | 2 |\n| 3 | 4 |\n", $this->markdown($carve));
        $this->assertSame($carve, $this->round($carve));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spellableHeaderRows(): array
    {
        return [
            'a header row that carries only alignment' => ["|  |  |\n| :--- | ---: |\n| 1 | 2 |\n", "|=< |=> |\n| 1 | 2 |\n"],
            'a header row with one filled cell' => ["|  | b |\n| --- | --- |\n| 1 | 2 |\n", "|= |= b |\n| 1 | 2 |\n"],
        ];
    }

    #[DataProvider('spellableHeaderRows')]
    public function testAHeaderRowThatSpellsSomethingIsKept(string $markdown, string $carve): void
    {
        $this->assertSame($carve, $this->carve($markdown));
    }

    public function testATableWithHeadersStillRoundTrips(): void
    {
        $carve = "|= A |= B |\n| 1 | 2 |\n";
        $this->assertSame($carve, $this->round($carve));
    }

    public function testTheDroppedRowIsReported(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("|  |  |\n| --- | --- |\n| 1 | 2 |\n");
        $messages = array_map(
            static fn ($diagnostic): string => $diagnostic->message,
            array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'structure-unspellable'),
        );
        $this->assertSame(
            ['Dropped a table row of 2 blank cells; Carve spells no row whose every cell is blank'],
            array_values($messages),
        );
    }
}
