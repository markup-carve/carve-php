<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FootnoteLabelIsSingleLineTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'CR' => ["\r"];
    }

    #[DataProvider('lineEndings')]
    public function testAReferenceLabelDoesNotCrossALineEnding(string $ending): void
    {
        $source = "before[^two{$ending}words].\n";
        $converter = new CarveConverter(warnings: true);
        $document = $converter->parse($source);
        $ast = (new AstCodec())->encode($document);

        self::assertSame(['text', 'soft_break', 'text'], array_column($ast['children'][0]['children'], 'type'));
        self::assertStringNotContainsString('doc-noteref', $converter->convert($source));
        self::assertSame([], array_filter(
            $converter->getWarnings(),
            static fn ($warning): bool => str_contains($warning->getMessage(), 'Undefined footnote'),
        ));
    }

    public function testAMultilineDefinitionMarkerDoesNotRegister(): void
    {
        $source = "see[^two words].\n\n[^two\nwords]: note.\n";
        $converter = new CarveConverter();
        $ast = (new AstCodec())->encode($converter->parse($source));

        self::assertNotContains('footnote', array_column($ast['children'], 'type'));
        self::assertStringNotContainsString('doc-endnotes', $converter->convert($source));
        self::assertSame($source, CarveConverter::toCarve($source));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sameLineLabels(): iterable
    {
        yield 'space' => ['two words'];
        yield 'tab' => ["two\twords"];
    }

    #[DataProvider('sameLineLabels')]
    public function testSameLineWhitespaceStillResolvesExactly(string $label): void
    {
        $html = (new CarveConverter())->convert("see[^{$label}].\n\n[^{$label}]: note.\n");
        self::assertStringContainsString('doc-noteref', $html);
    }
}
