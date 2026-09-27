<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The renderer moves a heading's id onto the `<section>` it opens
 * (CARVE-P9-019), so the import reads that id back onto the heading. Nothing
 * else on a section has a carrier.
 */
class ASectionWrapperGivesItsIdToItsHeadingTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function sections(): array
    {
        return [
            'authored id' => ['<section id="S1"><h2>Intro</h2><p>x</p></section>', "{#S1}\n## Intro\n\nx", []],
            'derived id' => ['<section id="Intro-2"><h2>Intro 2</h2></section>', '## Intro 2', []],
            // The renderer numbers a repeated slug, so a matching number is derived.
            'repeated slug' => [
                '<section id="A"><h2>A</h2></section><section id="A-2"><h2>A</h2></section>',
                "## A\n\n## A",
                [],
            ],
            // Keeping A-1 reserves it, which gives the third heading A-2 back.
            'kept id moves the numbering' => [
                '<section id="A"><h2>A</h2><p>x</p></section><section id="A-1"><h2>A</h2><p>y</p></section>'
                    . '<section id="A-2"><h2>A</h2><p>z</p></section>',
                "## A\n\nx\n\n{#A-1}\n## A\n\ny\n\n## A\n\nz",
                [],
            ],
            'explicit id elsewhere' => ['<h2 id="X">A</h2><section id="A-2"><h2>A</h2></section>', "{#X}\n## A\n\n{#A-2}\n## A", []],
            'id taken by another element' => ['<p id="A">p</p><section id="A"><h2>A</h2></section>', "{#A}\np\n\n{#A}\n## A", []],
            'class is not moved' => [
                '<section id="S1" class="ltx_section"><h2 class="t">Intro</h2></section>',
                "{#S1 .t}\n## Intro",
                ['Dropped unsupported attribute class on <section>'],
            ],
            'heading keeps its own id' => [
                '<section id="a"><h2 id="b">B</h2></section>',
                "{#b}\n## B",
                ['Dropped unsupported attribute id on <section>'],
            ],
            'heading not first' => [
                '<section id="c"><p>p</p><h2>C</h2></section>',
                "p\n\n## C",
                ['Dropped unsupported attribute id on <section>'],
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function renderedBack(): array
    {
        return [
            'authored' => ['<section id="S1"><h2>Intro</h2></section>'],
            'repeated slug' => ['<section id="A"><h2>A</h2></section><section id="A-2"><h2>A</h2></section>'],
            'kept id moves the numbering' => [
                '<section id="A"><h2>A</h2></section><section id="A-1"><h2>A</h2></section><section id="A-2"><h2>A</h2></section>',
            ],
            'explicit id elsewhere' => ['<h2 id="X">A</h2><section id="A-2"><h2>A</h2></section>'],
        ];
    }

    /**
     * An id is dropped only when rendering the written source gives it back.
     */
    #[DataProvider('renderedBack')]
    public function testRenderingTheSourceGivesTheSectionIdsBack(string $html): void
    {
        preg_match_all('/<section id="([^"]*)"/', $html, $expected);
        preg_match_all('/<section id="([^"]*)"/', (new CarveConverter())->convert((new HtmlToCarve())->convert($html)), $actual);
        $this->assertSame([], array_values(array_diff($expected[1], $actual[1])));
    }

    /**
     * @param string $html
     * @param string $carve
     * @param list<string> $dropped
     */
    #[DataProvider('sections')]
    public function testTheSectionId(string $html, string $carve, array $dropped): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        $this->assertSame($carve, trim($result->value));
        $rows = array_filter(
            $result->report()['diagnostics'],
            static fn (array $row): bool => $row['code'] === 'attribute-dropped',
        );
        $this->assertSame($dropped, array_values(array_column($rows, 'message')));
    }
}
