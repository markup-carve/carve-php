<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedStoredSourceReportTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function twins(): array
    {
        return [
            'raw first' => [
                "`<li onclick=\"x()\">t</li>`{=html}\n\n- t\n",
                '<li onclick="x()">t</li><ul><li onclick="x()">t</li></ul>',
            ],
            'raw second' => [
                "- t\n\n`<li onclick=\"x()\">t</li>`{=html}\n",
                '<ul><li onclick="x()">t</li></ul><li onclick="x()">t</li>',
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $body
     */
    #[DataProvider('twins')]
    public function testStoredSourceDoesNotGuessWhichTwinWasRaw(string $source, string $body): void
    {
        $attribute = str_replace("\n", '&#10;', htmlspecialchars($source, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $html = '<div data-djot-src="' . $attribute . '">' . $body . '</div>';
        $converter = new HtmlToCarve(importMode: 'roundtrip', trustedRoundTrip: true);

        $result = $converter->convertWithReport($html);
        $this->assertSame($source, $result->value);
        $this->assertSame([], $result->diagnostics);
        $this->assertSame([], $converter->convertToAstWithReport($html)->diagnostics);
    }

    public function testStoredSourceDoesNotSuppressLaterOrSiblingFindings(): void
    {
        $converter = new HtmlToCarve(importMode: 'roundtrip', trustedRoundTrip: true);
        $this->assertSame([], $converter->convertWithReport('<div data-djot-src="stored">x</div>')->diagnostics);

        $later = $converter->convertWithReport('<p onclick="x()">t</p>');
        $this->assertSame(['attribute-dropped'], array_column($later->report()['diagnostics'], 'code'));

        $sibling = $converter->convertWithReport(
            '<div data-djot-src="stored">x</div><p onclick="x()">t</p>',
        );
        $this->assertContains('attribute-dropped', array_column($sibling->report()['diagnostics'], 'code'));
    }
}
