<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A CAPTION WITH NOTHING TO CAPTION loses its text, and now says so.
 *
 * `<figcaption>` and `<caption>` are both MAPPED elements, and correctly so:
 * inside their own container they come through. So the element-outcome walk
 * carve-php#1377 added is never asked of them, and an orphan one - which the
 * writer has no slot for - took its text out of the document and exited clean.
 * A false negative, the direction this repo has consistently rated worse than a
 * false positive because silence cannot be reviewed (carve-php#1386).
 *
 * NOT A PREDICATE FOR WHICH CAPTION THE SERIALIZER CONSUMED. That question has
 * three routes through this importer and reading the input to answer it is what
 * withdrew carve-php#1347. This asks a different one: is the element anywhere a
 * route could take it? Outside `<figure>` and `<table>` none can.
 */
class AnOrphanCaptionSaysSoTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function orphanProvider(): array
    {
        return [
            'figcaption alone' => ['<figcaption>bcontent</figcaption>', 'figcaption'],
            'caption alone' => ['<caption>bcontent</caption>', 'caption'],
            // Beside a sibling that DOES come through, so the document is not
            // empty and the row is not an artifact of an empty conversion.
            'figcaption after a paragraph' => ['<p>kept</p><figcaption>bcontent</figcaption>', 'figcaption'],
            // A caption whose parent is an element other than `<table>`.
            'caption inside a div' => ['<div><caption>bcontent</caption></div>', 'caption'],
            'figcaption inside a div' => ['<div><section><figcaption>bcontent</figcaption></section></div>', 'figcaption'],
            // A DIRECT CHILD is what the content model asks for, and this is
            // the row that says so: there IS a figure above it, its text still
            // leaves the document, and an ancestor walk called it placed and
            // reported nothing.
            'figcaption nested inside a figure' => [
                '<figure><div><figcaption>bcontent</figcaption></div></figure>',
                'figcaption',
            ],
            // The same asymmetry for a table, where the writer reads the
            // caption off the table's own children.
            'caption nested inside a table' => [
                '<table><tr><td><caption>bcontent</caption></td></tr></table>',
                'caption',
            ],
        ];
    }

    #[DataProvider('orphanProvider')]
    public function testAnOrphanCaptionIsReportedAsDropped(string $html, string $tag): void
    {
        // The LOSS first: if the text ever starts coming through, this test has
        // to fail rather than go on asserting a row about a loss that stopped.
        $this->assertStringNotContainsString('bcontent', $this->carve($html));
        $this->assertContains(
            [
                'element-dropped',
                'warning',
                'Dropped <' . $tag . '>: a caption outside its own container has nothing to caption',
            ],
            $this->diagnostics($html),
        );
    }

    /**
     * IN ITS OWN CONTAINER it comes through, and there is nothing to report.
     *
     * The control the row above would be worthless without: a check that fires
     * on every caption would be a false positive on every real document, which
     * carve-php#1377 rates as a new false statement.
     *
     * @return array<string, array{string, string}>
     */
    public static function inPlaceProvider(): array
    {
        return [
            'figcaption in a figure' => ['<figure><figcaption>bcontent</figcaption></figure>', 'bcontent'],
            'figcaption under an image' => [
                '<figure><img src="a.png" alt="a"><figcaption>bcontent</figcaption></figure>',
                'bcontent',
            ],
            'caption in a table' => [
                '<table><caption>bcontent</caption><tr><td>c</td></tr></table>',
                '^ bcontent',
            ],
        ];
    }

    #[DataProvider('inPlaceProvider')]
    public function testACaptionInItsOwnContainerReportsNothing(string $html, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->carve($html));

        foreach ($this->diagnostics($html) as $row) {
            $this->assertStringNotContainsString(
                'has nothing to caption',
                $row[2],
                'a caption in its own container was reported as an orphan',
            );
        }
    }

    /**
     * AN EMPTY ORPHAN reports nothing, because nothing was lost.
     *
     * The row is about the author's TEXT leaving the document. A caption with
     * no letters or digits in it takes none with it, and a row there would be a
     * warning about an empty string.
     */
    public function testAnEmptyOrphanCaptionReportsNothing(): void
    {
        foreach (['<caption></caption>', '<figcaption>   </figcaption>', '<figcaption><br></figcaption>'] as $html) {
            $orphanRows = array_filter(
                $this->diagnostics($html),
                static fn (array $row): bool => str_contains($row[2], 'has nothing to caption'),
            );

            $this->assertSame([], array_values($orphanRows), 'for: ' . $html);
        }
    }

    /**
     * THE ROW IS ASKED OF THE OUTPUT, not of the input alone.
     *
     * Where the orphan's own words are in the emitted document the row is not
     * written, because a report that names text the reader can see is a false
     * statement. The cost is a false NEGATIVE on a document that repeats the
     * words elsewhere, which leaves the report where it already was - the
     * cheaper of the two errors, and stated here so it is a known limit rather
     * than a surprise.
     */
    public function testTheRowIsNotWrittenWhenTheWordsAreInTheOutput(): void
    {
        $html = '<p>bcontent</p><figcaption>bcontent</figcaption>';

        $this->assertStringContainsString('bcontent', $this->carve($html));

        foreach ($this->diagnostics($html) as $row) {
            $this->assertStringNotContainsString('has nothing to caption', $row[2]);
        }
    }

    /**
     * The drop STOPS the walk, as every other element-dropped row does.
     *
     * The element went and everything under it went with it, so a row per
     * descendant would name losses inside a loss already reported - which is
     * the noise carve-php#1377 removed from `<colgroup>` and `<math>`.
     */
    public function testTheDropStopsTheWalk(): void
    {
        $rows = $this->diagnostics('<figcaption><span data-x="1">bcontent</span></figcaption>');

        $orphan = array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_contains($row[2], 'has nothing to caption'),
        ));
        $this->assertCount(1, $orphan);

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('data-x', $row[2], 'the walk descended into a dropped subtree');
        }
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function diagnostics(string $html): array
    {
        return array_map(
            static function (object $diagnostic): array {
                $row = $diagnostic->toArray();

                return [$row['code'], $row['severity'], $row['message']];
            },
            (new HtmlToCarve())->convertWithReport($html)->diagnostics,
        );
    }

    private function carve(string $html): string
    {
        return trim((new HtmlToCarve())->convertWithReport($html)->value);
    }
}
