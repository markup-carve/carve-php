<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class ImportAttributePolicyParityTest extends TestCase
{
    public function testSemanticMarkerCollisionsAreReported(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['abbr', 'kbd', 'time', 'samp', 'var', 'cite', 'dfn'] as $tag) {
                foreach (['', 'value', 'javascript:x()'] as $value) {
                    $result = (new HtmlToCarve(importMode: $mode))->convertWithReport("<{$tag} {$tag}=\"{$value}\">text</{$tag}>");
                    $rows = array_values(array_filter($result->report()['diagnostics'], fn (array $row): bool => $row['code'] === 'attribute-dropped'));
                    $this->assertCount(1, $rows);
                    $this->assertSame('warning', $rows[0]['severity']);
                    $this->assertSame('/' . $tag . '[1]', $rows[0]['path']);
                }
            }
        }
    }

    public function testCssOwnedAttributeInsideKeptBytesIsReported(): void
    {
        foreach (['align="right" style="text-align:left"', 'style="text-align:left" align="right"'] as $attrs) {
            $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport("<form><table><tr><td {$attrs}>text</td></tr></table></form>");
            $rows = array_values(array_filter($result->report()['diagnostics'], fn (array $row): bool => str_starts_with($row['message'], 'Preserved align on <td>')));
            $this->assertCount(1, $rows);
            $this->assertSame('attribute-preserved', $rows[0]['code']);
            $this->assertSame('info', $rows[0]['severity']);
        }
    }

    public function testIgnoredNestedSourceMarkersAreReported(): void
    {
        foreach (['safe', 'semantic'] as $mode) {
            foreach (['data-carve-src', 'data-djot-src'] as $name) {
                $result = (new HtmlToCarve(importMode: $mode))->convertWithReport("<form><p {$name}=\"stored\">text</p></form>");
                $rows = array_values(array_filter($result->report()['diagnostics'], fn (array $row): bool => $row['code'] === 'attribute-dropped'));
                $this->assertCount(1, $rows);
                $this->assertSame('info', $rows[0]['severity']);
                $this->assertSame('/form[1]/p[1]', $rows[0]['path']);
            }
        }
    }
}
