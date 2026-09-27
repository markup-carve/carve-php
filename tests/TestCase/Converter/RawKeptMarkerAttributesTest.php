<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class RawKeptMarkerAttributesTest extends TestCase
{
    public function testRetainedMarkersHaveRowsWithoutReportingOrdinaryAttributes(): void
    {
        foreach (['data-carve-src', 'data-djot-src'] as $name) {
            $html = '<form ' . $name . '="x" data-user="y"><cite cite="u" data-user="v">text</cite></form>';
            $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);
            $rows = [];
            foreach ($result->diagnostics as $row) {
                if ($row->code === 'attribute-preserved') {
                    $rows[] = [$row->severity, $row->path, $row->message];
                }
            }
            $this->assertSame([
                ['info', '/form[1]', 'Preserved round-trip marker attribute ' . $name . ' on <form> in the raw HTML this element is kept as'],
                ['info', '/form[1]/cite[1]', "Preserved cite on <cite> inside the raw HTML <form> is kept as: the semantic span's marker owns that key"],
            ], $rows);
        }
    }
}
