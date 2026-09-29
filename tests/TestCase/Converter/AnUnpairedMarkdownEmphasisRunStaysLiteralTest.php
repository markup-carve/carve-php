<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Markdown emphasis delimiter run that never pairs is literal text, and a
 * delimiter run inside a resolved link's label never pairs with one outside it.
 *
 * CommonMark resolves the link before it processes the emphasis in its label,
 * with the bracket as the stack bottom, so the leftovers each side stay the
 * characters the reader wrote. The importer decided the rewrite from the run's
 * SHAPE alone, which cannot answer this, and turned an asterisk the reader keeps
 * into a Carve marker (carve-php#2740).
 *
 * The mirror case is prose brackets, which pair nothing and scope nothing. There
 * the emphasis DOES pair, and the `]` the importer writes has to be escaped or
 * the bracket run it closes hides the Carve closer behind it (carve-php#2741).
 *
 * Rows are CommonMark 0.31.2 examples. 534, 535 and 564 keep a source-spelling
 * difference from carve-rs, which inlines their reference definition; that half
 * is markup-carve/carve#2593 and only the rendered meaning is asserted here.
 */
class AnUnpairedMarkdownEmphasisRunStaysLiteralTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function unpairedRuns(): array
    {
        return [
            'row 473, a closer in an inline link label' => [
                "*[bar*](/url)\n",
                "<p>*<a href=\"/url\">bar*</a></p>\n",
            ],
            'row 474, an underscore run' => [
                "_foo [bar_](/url)\n",
                "<p>_foo <a href=\"/url\">bar_</a></p>\n",
            ],
            'row 521' => [
                "*[foo*](/uri)\n",
                "<p>*<a href=\"/uri\">foo*</a></p>\n",
            ],
            'row 534, a full reference' => [
                "*[foo*][ref]\n\n[ref]: /uri\n",
                "<p>*<a href=\"/uri\">foo*</a></p>\n",
            ],
            'row 535, the opener inside the label' => [
                "[foo *bar][ref]*\n\n[ref]: /uri\n",
                "<p><a href=\"/uri\">foo *bar</a>*</p>\n",
            ],
            'row 564, a shortcut reference' => [
                "*[foo*]\n\n[foo*]: /url\n",
                "<p>*<a href=\"/url\">foo*</a></p>\n",
            ],
        ];
    }

    /**
     * Row 523 and the shapes around it: prose brackets scope nothing, so the
     * emphasis pairs and the written `]` is what has to give way.
     *
     * @return array<string, array{string, string}>
     */
    public static function proseBrackets(): array
    {
        return [
            'row 523' => [
                "*foo [bar* baz]\n",
                "<p><em>foo [bar</em> baz]</p>\n",
            ],
            'the opener inside the prose run' => [
                "foo [bar *baz] qux*\n",
                "<p>foo [bar <em>baz] qux</em></p>\n",
            ],
            'a pair wholly inside the prose run' => [
                "foo [bar *baz* qux]\n",
                "<p>foo [bar <em>baz</em> qux]</p>\n",
            ],
            'a pair wholly outside it' => [
                "*foo* [bar baz]\n",
                "<p><em>foo</em> [bar baz]</p>\n",
            ],
        ];
    }

    /**
     * A run the reader never paired came through before this as well: the defect
     * was the pairing, not the character. These must not move.
     *
     * @return array<string, array{string, string}>
     */
    public static function controls(): array
    {
        return [
            'a pair that resolves' => ["*emph*\n", "/emph/\n"],
            'a lone asterisk between spaces' => ["a * b\n", "a * b\n"],
            'an opener with no closer' => ["a *b\n", "a *b\n"],
            'arithmetic' => ["5 * 3 * 2\n", "5 * 3 * 2\n"],
            'a pair inside a link label' => ["[a *b* c](/u)\n", "[a /b/ c](/u)\n"],
            'a pair around a link' => ["*a [b](/u) c*\n", "/a [b](/u) c/\n"],
            'prose brackets with no marker' => ["a [b] c\n", "a [b] c\n"],
        ];
    }

    #[DataProvider('unpairedRuns')]
    #[DataProvider('proseBrackets')]
    public function testTheImportedSourceRendersTheSuitesHtml(string $markdown, string $expected): void
    {
        $this->assertSame(
            $expected,
            (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown)),
        );
    }

    #[DataProvider('controls')]
    public function testTheImportedSourceIsUnchanged(string $markdown, string $expected): void
    {
        $this->assertSame($expected, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * The `]` escape is the whole difference on row 523, and it is written only
     * where a pair straddles the run - a prose run holding no straddling pair
     * keeps its bare bracket.
     */
    public function testOnlyAStraddledRunGetsTheEscape(): void
    {
        $convert = new MarkdownToCarve();

        $this->assertSame("/foo [bar/ baz\\]\n", $convert->convert("*foo [bar* baz]\n"));
        $this->assertSame("a [b] c\n", (new MarkdownToCarve())->convert("a [b] c\n"));
        $this->assertSame("foo [bar /baz/ qux]\n", (new MarkdownToCarve())->convert("foo [bar *baz* qux]\n"));
    }
}
