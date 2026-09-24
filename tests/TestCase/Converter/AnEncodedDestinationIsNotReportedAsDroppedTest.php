<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A destination the writer percent-encodes is the same URL, not a dropped one.
 */
class AnEncodedDestinationIsNotReportedAsDroppedTest extends TestCase
{
    #[DataProvider('encodedDestinationProvider')]
    public function testAWhitespaceEncodedDestinationIsNotReported(string $html, string $carve): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertStringContainsString($carve, $result->value);
        $this->assertSame([], $result->diagnostics);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function encodedDestinationProvider(): array
    {
        return [
            'space in href' => ['<p><a href="https://x.test/a b">t</a></p>', '[t](https://x.test/a%20b)'],
            'space in src' => ['<p>x <img src="https://x.test/a b.png" alt="i"></p>', '![i](https://x.test/a%20b.png)'],
        ];
    }

    public function testATabIsStillReportedBecauseABrowserDeletesItInstead(): void
    {
        $result = (new HtmlToCarve())->convertWithReport("<p><a href=\"java\tscript:alert(1)\">t</a></p>");

        $this->assertSame(['attribute-dropped'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
    }

    public function testADeniedSchemeIsStillReportedBecauseTheRendererBlanksIt(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><a href="javascript:alert(1)">t</a></p>');

        $this->assertSame(['attribute-dropped'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
    }
}
