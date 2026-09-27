<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A `<dl>` holding no entry and a list holding no item name the attributes
 * they would have written in their own rows; a refused attribute beside them
 * (an event handler, a style, a value spanning a line break) keeps its row.
 */
class AnEntrylessDefinitionListKeepsRefusedAttributeRowsTest extends TestCase
{
    public function testAnEntrylessDefinitionListReportsRefusedAttributes(): void
    {
        $this->assertSame([
            ['attribute-dropped', 'Dropped id on <dl>: a definition list holding no entry is not written'],
            ['attribute-dropped', 'Dropped event-handler attribute onclick on <dl>'],
            ['style-unmapped', 'CSS declarations may not have a Carve mapping'],
        ], $this->rows('<dl id="d" onclick="alert(1)" style="color:red"></dl><p>z</p>'));
    }

    public function testAnEmptyListReportsRefusedAttributes(): void
    {
        $this->assertSame([
            ['element-dropped', 'Dropped <ul> holding no item'],
            ['attribute-dropped', 'Dropped event-handler attribute onclick on <ul>'],
            ['attribute-dropped', 'Dropped title on <ul>: its value spans a line break, which a Carve attribute value cannot'],
        ], $this->rows("<ul class=\"c\" onclick=\"b\" title=\"a\nb\"></ul><p>z</p>"));
    }

    /**
     * @return array<array{string, string}>
     */
    protected function rows(string $html): array
    {
        return array_map(
            static fn (array $row): array => [$row['code'], $row['message']],
            (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'],
        );
    }
}
