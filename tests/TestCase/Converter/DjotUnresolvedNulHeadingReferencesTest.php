<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotUnresolvedNulHeadingReferencesTest extends TestCase
{
    public function testUnresolvedReferencesDoNotSelectReplacementCharacterHeadings(): void
    {
        $controls = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-unresolved-nul-heading-references.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($controls as $row) {
            $source = $row['source'];
            $pattern = '/<(?:a|img)\b[^>]*\b(?:href|src)=/';
            $this->assertDoesNotMatchRegularExpression($pattern, $row['html'], $source);
            $report = $converter->convertWithFidelityReport($source);
            $html = $renderer->convert($report->value);
            $this->assertDoesNotMatchRegularExpression($pattern, $html, $source);
            $this->assertStringNotContainsString('carve-djot-reference-', $html, $source);
            $this->assertDoesNotMatchRegularExpression('/<(?:span|em|strong|code)\b/', $html, $source);
            $this->assertDoesNotMatchRegularExpression('/<[^>]*\b(?:class="c"|id="i")/', $html, $source);
            $this->assertStringNotContainsString('{%comment%}', $html, $source);
            if (isset($row['quoteOwn'])) {
                $this->assertSame($row['quoteOwn'], substr_count($row['html'], '<blockquote>'), $source);
                $this->assertSame($row['quoteOwn'], substr_count($html, '<blockquote>'), $source);
                $this->assertStringNotContainsString('&gt;', $html, $source);
            }
            $messages = array_map(static fn ($diagnostic): string => $diagnostic->message, $report->diagnostics);
            $this->assertNotEmpty(array_filter($messages, static fn (string $message): bool => str_contains($message, 'unresolved Djot')), $source);
        }
    }

    public function testResolvedReferencesSelectTheirOwnHeading(): void
    {
        foreach (
            [
                ["# a\0\n\n# a�\n\n[a�][]", 1],
                ["# a�\n\n# a\0\n\n[a\0][]", 1],
                ["# a\0\n\n# a�\n\n[a\0][]", 0],
            ] as [$source, $target]
        ) {
            $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));
            preg_match_all('/<section id="([^"]*)"/', $html, $ids);
            preg_match_all('/href="([^"]*)"/', $html, $links);
            $this->assertCount(2, $ids[1], $source);
            $this->assertSame(['#' . $ids[1][$target]], $links[1], $source);
        }
    }

    public function testExplicitDefinitionsTakePrecedence(): void
    {
        foreach (["a\0", 'a�'] as $key) {
            $source = "# a\0\n\n[x][" . $key . ']' . "\n\n[" . $key . ']: /real';
            $report = (new DjotToCarve())->convertWithFidelityReport($source);
            $this->assertStringContainsString('href="/real"', (new CarveConverter())->convert($report->value));
            $messages = array_map(static fn ($diagnostic): string => $diagnostic->message, $report->diagnostics);
            $this->assertSame([], array_filter($messages, static fn (string $message): bool => str_contains($message, 'unresolved Djot')));
        }
    }
}
