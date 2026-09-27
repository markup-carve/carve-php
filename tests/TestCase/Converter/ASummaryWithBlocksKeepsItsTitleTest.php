<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class ASummaryWithBlocksKeepsItsTitleTest extends TestCase
{
    public function testTheTitleJoinsTheBlocksAndReportsTheirLostAttributes(): void
    {
        foreach ([['Baseline'], ['Baseline', 'Wide']] as $texts) {
            $blocks = '';
            $expected = [];
            foreach ($texts as $index => $text) {
                $blocks .= '<div class="t">' . $text . '</div>';
                $path = '/details[1]/summary[1]/div[' . ($index + 1) . ']';
                $expected[] = [
                    'code' => 'element-unwrapped',
                    'message' => 'Unwrapped unsupported <div> element',
                    'severity' => 'info',
                    'fidelity' => 'degraded',
                    'confidence' => 'exact',
                    'path' => $path,
                ];
                $expected[] = [
                    'code' => 'attribute-dropped',
                    'message' => 'Dropped class with the unwrapped <div>: there is no element left to carry it',
                    'severity' => 'info',
                    'fidelity' => 'dropped',
                    'confidence' => 'exact',
                    'path' => $path,
                ];
            }
            $html = '<details><summary>' . $blocks . '</summary><p>b</p></details>';
            $result = (new HtmlToCarve())->convertWithReport($html);
            $title = implode(' ', $texts);
            $this->assertSame('::: details "' . $title . '"' . "\nb\n:::\n", $result->value);
            $this->assertSame($expected, array_map(static fn ($row) => $row->toArray(), $result->diagnostics));
            $tree = (new HtmlAstBuilder())->build($html);
            $this->assertSame([['type' => 'text', 'value' => $title]], $tree['children'][0]['title']);
        }
    }
}
