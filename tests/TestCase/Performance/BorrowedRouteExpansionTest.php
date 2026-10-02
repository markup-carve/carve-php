<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Performance;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Performance\BorrowedHtmlLayout;
use PHPUnit\Framework\TestCase;

final class BorrowedRouteExpansionTest extends TestCase
{
    public function testUnicodeLettersAndNumbersMatchTheAstAcrossBlockShapes(): void
    {
        foreach (['Café', '東京', 'हिन्दी', '한글', 'é', 'Δοκιμή', 'Привет', '۱۲۳'] as $word) {
            foreach (
                [
                    $word . "\n",
                    "# $word\n\n# $word\n",
                    "- $word\n- next\n",
                    "> $word\n",
                    "| A | B |\n|---|---|\n| $word | next |\n",
                ] as $source
            ) {
                $this->assertAcceptedParity($source);
            }
        }
    }

    public function testUnicodeInlineBoundariesAndSpecialSpacesStayOnTheAst(): void
    {
        foreach (
            [
                "😀\n",
                "a\u{00A0}b\n",
                "a\u{2003}b\n",
                "a\u{202E}b\n",
                "Café *bold*\n",
                "Café _emphasis_\n",
                "東京 [link](https://example.com)\n",
                "Café \"quote\"\n",
                "é'a\n",
                "invalid \xFF\n",
            ] as $source
        ) {
            self::assertNull((new BorrowedHtmlLayout())->render($source), bin2hex($source));
        }
    }

    public function testSimpleImagesPreserveBlockShapeAndEscapedAttributes(): void
    {
        foreach (
            [
                "![a picture](https://example.com/image.png)\n",
                "![a & b](a.png)\n",
                "![](./a.png)\n",
                "![a](../a.png)\n",
                "![a](/a?x=1&y=2)\n",
                "# Heading\n\n![a](a.png)\n",
                "before\n\n![a](a.png)\n\nafter\n",
            ] as $source
        ) {
            $this->assertAcceptedParity($source);
        }
    }

    public function testImagesWithInlineSemanticsTitlesOrAttributesStayOnTheAst(): void
    {
        foreach (
            [
                "![_a_](a.png)\n",
                "![a -- b](a.png)\n",
                "![a...b](a.png)\n",
                "![Café](a.png)\n",
                "![a](a.png \"title\")\n",
                "![a](a.png){.decorated}\n",
                "![a](javascript:bad)\n",
                "![a](a.png) and text\n",
            ] as $source
        ) {
            self::assertNull((new BorrowedHtmlLayout())->render($source), $source);
            self::assertSame(CarveConverter::create()->convert($source), (new CarveConverter())->convert($source));
        }
    }

    public function testFlatBulletListsMatchTheAst(): void
    {
        foreach (['-', '*'] as $marker) {
            $this->assertAcceptedParity("$marker first\n$marker second\n");
            $this->assertAcceptedParity("# Heading\n\n$marker first\n$marker second\n");
        }
    }

    public function testAmbiguousFlatBulletListsStayOnTheAst(): void
    {
        foreach (
            [
                "* first\n\n* second\n",
                "* first\n  * second\n",
                "* first\n\n  * second\n",
                "* first\n- second\n",
                "* first\ncontinuation\n",
            ] as $source
        ) {
            self::assertNull((new BorrowedHtmlLayout())->render($source), $source);
        }
    }

    public function testExpandedRoutesStreamExactUtf8OutputWithinTheChunkLimit(): void
    {
        foreach (["# Café 東京\n\n" . rtrim(str_repeat('Café ', 10000)) . "\n", "![a & b](a.png)\n", "* first\n* second\n"] as $source) {
            $chunks = [];
            $converter = new CarveConverter();
            self::assertSame('complete', $converter->tryRenderHtmlStreaming($source, static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            }));
            self::assertSame(CarveConverter::create()->convert($source), implode('', $chunks));
            foreach ($chunks as $chunk) {
                self::assertLessThanOrEqual(4096, strlen($chunk));
                self::assertTrue(mb_check_encoding($chunk, 'UTF-8'));
            }
        }
        $chunks = [];
        self::assertSame('needs-ast', (new CarveConverter())->tryRenderHtmlStreaming("![a](a.png){.decorated}\n", static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }));
        self::assertSame([], $chunks);
    }

    private function assertAcceptedParity(string $source): void
    {
        $attempt = (new BorrowedHtmlLayout())->render($source);
        self::assertNotNull($attempt, $source);
        self::assertSame(CarveConverter::create()->convert($source), $attempt['html'], $source);
        self::assertSame($attempt['html'], (new CarveConverter())->convert($source), $source);
    }
}
