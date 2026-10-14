<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotUnresolvedNulReferencesTest extends TestCase
{
    public function testUnresolvedNulReferencesDoNotSelectReplacementCharacterDestinations(): void
    {
        $controls = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-unresolved-nul-references.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($controls as $row) {
            if (isset($row['emptyHeading'])) {
                $messages = array_map(static fn ($diagnostic): string => $diagnostic->message, $converter->convertWithFidelityReport($row['source'])->diagnostics);
                $this->assertSame($row['emptyHeading'], array_filter($messages, static fn (string $message): bool => str_contains($message, 'empty heading')) !== [], $row['source']);

                continue;
            }
            if (!empty($row['noAlias'])) {
                $this->assertStringNotContainsString('carve-djot-reference-', $renderer->convert($converter->convertWithFidelityReport($row['source'])->value), $row['source']);
                if (empty($row['linked'])) {
                    continue;
                }
            }
            if (isset($row['heading']) || isset($row['linked'])) {
                $report = $converter->convertWithFidelityReport($row['source']);
                preg_match_all('/\b(?:href|src)="([^"]*)"/', str_replace("\0", '�', $row['html']), $expected);
                preg_match_all('/\b(?:href|src)="([^"]*)"/', $renderer->convert($report->value), $actual);
                $this->assertSame($expected[1], $actual[1], $row['source']);

                continue;
            }
            $pattern = '/<(?:a|img)\b[^>]*\b(?:href|src)=/';
            $this->assertDoesNotMatchRegularExpression($pattern, $row['html'], $row['source']);
            $report = $converter->convertWithFidelityReport($row['source']);
            $this->assertDoesNotMatchRegularExpression($pattern, $renderer->convert($report->value), $row['source']);
            if (isset($row['quoteOwn'])) {
                $actual = $renderer->convert($report->value);
                $this->assertSame(substr_count($row['html'], '<blockquote>'), substr_count($actual, '<blockquote>'), $row['source']);
                if ($row['quoteOwn'] > 0) {
                    $this->assertStringNotContainsString('&gt;', $actual, $row['source']);
                }
            }
            if (isset($row['own']) || isset($row['keyLiteral'])) {
                $this->assertDoesNotMatchRegularExpression('/<(?:span|em|strong|code)\b/', $renderer->convert($report->value), $row['source']);
            }
            $message = $row['image']
                ? 'An unresolved Djot image reference has no src; Carve cannot spell that image.'
                : 'An unresolved Djot reference renders a link without href; Carve has no spelling for it.';
            $messages = array_map(static fn ($diagnostic): string => $diagnostic->message, $report->diagnostics);
            $this->assertContains($message, $messages, $row['source']);
        }
    }
}
