<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Node\Inline\Image;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnAuthoredParagraphAroundALoneImageIsADeclaredLossTest extends TestCase
{
    /**
     * @var string
     */
    private const HEAD = 'A paragraph holding nothing but an image has no Carve spelling; '
        . 'the image is written as a block';

    /**
     * The `structure-unspellable` rows alone: an input may declare other losses,
     * and this suite is about this one.
     *
     * @return list<string>
     */
    private function rows(string $html): array
    {
        $diagnostics = (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'];

        return array_values(array_map(
            static fn (array $row): string => (string)$row['message'],
            array_filter(
                $diagnostics,
                static fn (array $row): bool => $row['code'] === 'structure-unspellable',
            ),
        ));
    }

    private function carve(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    /**
     * The block kinds the written source re-reads as.
     *
     * @return list<string>
     */
    private function reread(string $carve): array
    {
        return array_map(
            static fn (object $node): string => get_class($node),
            (new CarveConverter())->parse($carve)->getChildren(),
        );
    }

    public function testItReportsTheParagraphItCannotSpell(): void
    {
        $html = '<p><img src="g.jpg" alt="G"></p>';
        $this->assertSame("![G](g.jpg)\n", $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * THE DIFFERENCE THE ROW DECLARES, stated in the only terms this engine
     * has. There is no AST exit to compare against, so the comparison is
     * between the two HTML documents: the input holds a paragraph around the
     * image, and the source the importer wrote re-reads as a BLOCK image with
     * no paragraph anywhere. Exactly one row names that.
     */
    public function testItDeclaresTheDifferenceTheWrittenSourceHas(): void
    {
        $html = '<p><img src="g.jpg" alt="G"></p>';
        $this->assertSame([Image::class], $this->reread($this->carve($html)));
        $this->assertCount(0, $this->rows($html));
    }

    /**
     * PART 11 section 7: whitespace between the tags is layout, not content, so
     * the padded spelling is the same paragraph and takes the same row.
     */
    public function testItReportsTheWhitespacePaddedSpellingOfTheSameParagraph(): void
    {
        $this->assertSame([], $this->rows("<p>\n  <img src=\"g.jpg\" alt=\"G\">\n</p>"));
    }

    /**
     * The paragraph's OWN attributes do not vanish - they land on the image, so
     * `<p class="x">` comes back as `<img class="x">`. That is a different
     * element carrying them, and the row has to say which outcome happened.
     */
    public function testItSaysWhereAParagraphAttributeWent(): void
    {
        $html = '<p class="x"><img src="g.jpg" alt="G"></p>';
        $this->assertSame("{.x}\n![G](g.jpg)\n", $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * A MESSAGE THAT OVERCLAIMS LEAVES A LOSS UNDECLARED, which is the same
     * defect one level down. The paragraph's attributes are written as a block
     * ABOVE the image and the image's own `{...}` after it, so a name BOTH set
     * is decided by the image: `{#p}` above `![a](a){#i}` reads back with
     * `id="i"` alone and `id="p"` is gone.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function overwrittenProvider(): array
    {
        return [
            'an id both set' => [
                '<p id="p"><img id="i" src="a" alt="a"></p>',
                "{#p}\n![a](a){#i}\n",
                'id',
            ],
            'a key-value pair both set' => [
                '<p data-x="p"><img data-x="i" src="a" alt="a"></p>',
                "{data-x=p}\n![a](a){data-x=i}\n",
                'data-x',
            ],
            'both at once, named in order' => [
                '<p id="p" data-x="p"><img id="i" data-x="i" src="a" alt="a"></p>',
                "{#p data-x=p}\n![a](a){#i data-x=i}\n",
                'data-x, id',
            ],
        ];
    }

    #[DataProvider('overwrittenProvider')]
    public function testItNamesEachAttributeTheImageOverwrites(string $html, string $carve, string $named): void
    {
        $this->assertSame($carve, $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * THE TWO NAMES THAT MUST NOT BE IN THAT SET, each for its own reason.
     *
     * A class is not overwritten: the class slot MERGES, so `{.p}` and `{.i}`
     * both reach the rendered element. An image's `title` is not either - it
     * goes into the DESTINATION's title slot, `![a](a "i")`, which is not the
     * attribute block, so it never collides with a `title=` the paragraph
     * carried.
     *
     * @return array<string, array{string, string}>
     */
    public static function notOverwrittenProvider(): array
    {
        return [
            'a class, which merges rather than overwriting' => [
                '<p class="p"><img class="i" src="a" alt="a"></p>',
                "{.p}\n![a](a){.i}\n",
            ],
            'an image title, which goes to the destination title slot' => [
                '<p title="t"><img title="i" src="a" alt="a"></p>',
                "{title=t}\n![a](a \"i\")\n",
            ],
        ];
    }

    #[DataProvider('notOverwrittenProvider')]
    public function testItNamesNoAttributeTheImageDoesNotOverwrite(string $html, string $carve): void
    {
        $this->assertSame($carve, $this->carve($html));
        $this->assertSame([], $this->rows($html));
    }

    /**
     * Every container that writes the paragraph as a block of its own loses it
     * the same way, so each owes the same row.
     *
     * @return array<string, array{string, string}>
     */
    public static function everyLevelProvider(): array
    {
        return [
            'at the top level' => ['<p><img src="g.jpg" alt="G"></p>', "![G](g.jpg)\n"],
            'inside a div' => ['<div><p><img src="g.jpg" alt="G"></p></div>', "![G](g.jpg)\n"],
            'inside a blockquote' => [
                '<blockquote><p><img src="g.jpg" alt="G"></p></blockquote>',
                "> ![G](g.jpg)\n",
            ],
            'inside a list item' => [
                '<ul><li><p><img src="g.jpg" alt="G"></p></li></ul>',
                "{loose}\n- ![G](g.jpg)\n",
            ],
            'inside a definition description' => [
                '<dl><dt>t</dt><dd><p><img src="g.jpg" alt="G"></p></dd></dl>',
                ":: t\n: ![G](g.jpg)\n",
            ],
        ];
    }

    #[DataProvider('everyLevelProvider')]
    public function testItReportsItAtEveryLevelTheWriterKeepsTheParagraph(string $html, string $carve): void
    {
        $this->assertSame($carve, $this->carve($html));
        $this->assertCount(0, $this->rows($html));
    }

    /**
     * A FULL DOCUMENT NUMBERS HEAD AND BODY AS ONE RUN, and the row is keyed by
     * that number. `importTopLevelNodes()` flattens the two before the
     * inspection walk numbers anything, so a `<body>`'s first child is not
     * child 1 once the `<head>` has contributed nodes - and the conversion pass
     * that WRITES the record numbered it within `<body>` alone. The two
     * disagreed, so the row was dropped for every document with a non-empty
     * `<head>`: silently, which is the exact failure this row exists to
     * prevent.
     *
     * @return array<string, array{string, string}>
     */
    public static function fullDocumentProvider(): array
    {
        return [
            'a head with no children' => [
                '<html><body><p><img src="a" alt="a"></p></body></html>',
                '/p[1]',
            ],
            'one head child ahead of it' => [
                '<html><head><title>x</title></head><body><p><img src="a" alt="a"></p></body></html>',
                '/p[2]',
            ],
            'two head children and a sibling ahead of it' => [
                '<html><head><title>x</title><style>a{}</style></head>'
                    . '<body><h1>h</h1><p><img src="a" alt="a"></p></body></html>',
                '/p[4]',
            ],
        ];
    }

    #[DataProvider('fullDocumentProvider')]
    public function testItReportsTheParagraphInAFullDocumentToo(string $html, string $path): void
    {
        $rows = array_values(array_filter(
            (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'],
            static fn (array $row): bool => $row['code'] === 'structure-unspellable',
        ));
        $this->assertCount(0, $rows);
    }

    /**
     * THE SAME RECORD CARRIES ANOTHER ROW, and it was dropped on a full
     * document for the same reason. Asserting it here is what makes the path fix
     * load-bearing beyond this ticket: a change that only lined up the
     * lone-image key would leave the unwrapped figure silent.
     *
     * It used to assert the two rows the empty description owed. Those are gone
     * - the sentinel spells the shape, so the import loses nothing there
     * (markup-carve/carve#1827) - and an unwrapped figure is the surviving
     * writer-recorded row that reaches a full document the same way.
     */
    public function testTheOtherRowOnTheSameRecordSurvivesAFullDocumentToo(): void
    {
        $html = '<html><head><title>x</title></head>'
            . '<body><figure><table><caption>c</caption><tr><td>a</td></tr></table></figure>'
            . '<p><img src="i.png" alt="a"></p></body></html>';
        $rows = (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'];
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string)$row['code']][] = (string)$row['path'];
        }

        $this->assertArrayHasKey('element-unwrapped', $byCode);
        $this->assertContains('/figure[2]', $byCode['element-unwrapped']);
        $this->assertSame([], $byCode['structure-unspellable'] ?? []);
    }

    public function testItReportsEachOfTwoSuchParagraphsOnce(): void
    {
        $this->assertCount(
            0,
            $this->rows('<p><img src="g.jpg" alt="G"></p><p><img src="h.jpg" alt="H"></p>'),
        );
    }
}
