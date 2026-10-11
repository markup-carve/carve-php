<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SoftBreakMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlConfiguredMarkerCollisionTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function guards(): array
    {
        $rows = [];
        for ($code = 0xE001; $code <= 0xE004; $code++) {
            $rows[dechex($code)] = [(string)mb_chr($code, 'UTF-8')];
        }

        return $rows;
    }

    #[DataProvider('guards')]
    public function testSymbolReplacementPreservesConfiguredCharacters(string $glyph): void
    {
        $renderer = new HtmlRenderer(symbols: ['rocket' => "<b>{$glyph}</b>"]);
        $converter = new CarveConverter(renderer: $renderer);
        $result = $converter->convertWithReport(':rocket:');

        $this->assertSame("<p><b>{$glyph}</b></p>\n", $result->value);
        $this->assertSame(0, $result->totalLosses);
        $this->assertSame($result->value, $converter->convert(':rocket:'));
    }

    #[DataProvider('guards')]
    public function testTranslatedFootnoteLabelsPreserveConfiguredCharacters(string $glyph): void
    {
        $renderer = new HtmlRenderer(labels: [
            'endnotes' => "Notes {$glyph}",
            'footnoteBacklink' => "Back {$glyph}",
        ]);
        $result = (new CarveConverter(renderer: $renderer))->convertWithReport("text [^n]\n\n[^n]: note\n");

        $this->assertStringContainsString('aria-label="Notes ' . $glyph . '"', $result->value);
        $this->assertStringContainsString('Back ' . $glyph, $result->value);
        $this->assertSame(0, $result->totalLosses);
    }

    #[DataProvider('guards')]
    public function testStandaloneFragmentsPreserveConfiguredSymbolCharacters(string $glyph): void
    {
        $renderer = new HtmlRenderer(symbols: ['rocket' => $glyph]);
        $document = (new BlockParser())->parse(':rocket:');
        $paragraph = $document->getChildren()[0];

        $this->assertSame("<p>{$glyph}</p>\n", $renderer->renderNodeFragment($paragraph));
        $this->assertSame($glyph, $renderer->renderInlineNodesFragment($paragraph->getChildren()));
    }

    public function testEveryConfiguredGuardRemainsLiteralBesideRealBreaks(): void
    {
        $glyphs = "\u{E001}\u{E002}\u{E003}\u{E004}";
        $renderer = new HtmlRenderer(symbols: ['rocket' => $glyphs]);
        $converter = new CarveConverter(renderer: $renderer);

        $this->assertSame("<p>{$glyphs}\nnext</p>\n", $converter->convert(":rocket:\nnext"));
        $renderer->setSoftBreakMode(SoftBreakMode::Break);
        $this->assertSame("<p>{$glyphs}<br>\nnext</p>\n", $converter->convert(":rocket:\nnext"));
    }

    public function testDocumentAndConfigurationBothExcludeGuardCandidates(): void
    {
        $glyphs = "\u{E001}\u{E002}\u{E003}\u{E004}";
        $renderer = new HtmlRenderer(symbols: ['rocket' => $glyphs]);

        $this->assertSame(
            "<p>{$glyphs} \u{E005}</p>\n",
            (new CarveConverter(renderer: $renderer))->convert(":rocket: \u{E005}"),
        );
    }
}
