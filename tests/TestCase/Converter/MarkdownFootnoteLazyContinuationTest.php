<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownFootnoteLazyContinuationTest extends TestCase
{
    public function testReferenceDefinitionContinuesTheFootnoteParagraph(): void
    {
        $source = "See [r] and[^a].\n\n[^a]: Two words\n[r]: /u\n";
        $expected = "See [r] and[^a].\n\n[^a]: Two words\n  \\[r]: /u\n";
        $imported = (new MarkdownToCarve())->convert($source);

        $this->assertSame($expected, $imported);
        $html = (new CarveConverter())->convert($imported);
        $this->assertStringNotContainsString('href="/u"', $html);
        $this->assertStringContainsString('[r]: /u', $html);
    }

    public function testLazyLineAndIndentedParagraphStayInTheFootnote(): void
    {
        $source = "See[^a].\n\n[^a]: one\nlazy two\n\n    indented para\n\nafter\n";
        $expected = "See[^a].\n\nafter\n\n[^a]: one\n  lazy two\n\n  indented para\n";

        $this->assertSame($expected, (new MarkdownToCarve())->convert($source));
    }

    #[DataProvider('blockOpeners')]
    public function testNewBlocksEndLazyContinuation(string $block): void
    {
        $imported = (new MarkdownToCarve())->convert("See[^a].\n\n[^a]: one\n" . $block . "\n");

        $this->assertStringEndsWith("\n\n[^a]: one\n", $imported);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockOpeners(): array
    {
        return [
            'heading' => ['# heading'],
            'fence' => ["```\ncode\n```"],
            'list' => ['- item'],
            'quote' => ['> quote'],
            'thematic break' => ['***'],
            'HTML block' => ['<div>block</div>'],
        ];
    }

    public function testAnotherFootnoteEndsLazyContinuation(): void
    {
        $source = "See[^a] and[^b].\n\n[^a]: one\n[^b]: two\n";

        $this->assertSame("See[^a] and[^b].\n\n[^a]: one\n\n[^b]: two\n", (new MarkdownToCarve())->convert($source));
    }

    public function testBlankLineEndsUnindentedContinuation(): void
    {
        $source = "See[^a].\n\n[^a]: one\n\nafter\n";

        $this->assertSame("See[^a].\n\nafter\n\n[^a]: one\n", (new MarkdownToCarve())->convert($source));
    }

    public function testOpeningHeadingDoesNotTakeALazyLine(): void
    {
        $source = "See[^a].\n\n[^a]: # heading\nafter\n";

        $this->assertSame("See[^a].\n\nafter\n\n[^a]: # heading\n", (new MarkdownToCarve())->convert($source));
    }
}
