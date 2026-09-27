<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

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
            'derived repeat' => [
                '<section id="A"><h2>A</h2></section><section id="A-2"><h2>A</h2></section>',
                "## A\n\n## A",
                [],
            ],
            'repeat id on a first heading' => ['<section id="A-2"><h2>A</h2></section>', "{#A-2}\n## A", []],
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
