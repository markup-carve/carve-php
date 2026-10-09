<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class CodeBlockChildMarkupTest extends TestCase
{
    public function testCodePayloadAndLossReportsAgreeInEveryMode(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/code-block-child-markup.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach ($cases as $case) {
                $converter = new HtmlToCarve(importMode: $mode);
                $ast = $converter->convertToAstWithReport($case['html']);
                $source = $converter->convertWithReport($case['html']);
                $this->assertSame('code_block', $ast->value['children'][0]['type'], $case['name']);
                $this->assertSame($case['content'], $ast->value['children'][0]['content'], $case['name']);
                foreach ([$ast, $source] as $result) {
                    $this->assertSame($case['codes'], array_column($result->diagnostics, 'code'), $case['name']);
                }
                $html = (new CarveConverter())->convert($source->value);
                $this->assertStringContainsString('<p>after</p>', $html, $case['name']);
                $this->assertSame($source->value, CarveConverter::toCarve($source->value), $case['name']);
                $expected = $case['content'] !== '' && !str_ends_with($case['content'], "\n") ? $case['content'] . "\n" : $case['content'];
                $this->assertSame($expected, $converter->convertToAstWithReport($html)->value['children'][0]['content'], $case['name']);
            }
        }
    }
}
