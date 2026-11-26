<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Test\Support\Linearity;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5920: a write whose escapes are ones every form carries, as text quotes, takes the procedure form without
 * first reading back the form without escapes.
 */
final class OwnEscapeProcedureFormTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'text quotes' => ['a \\"b\\" c', 'a \\"b\\" c'],
            'quotes and a bracket' => ["it\\'s \\\"q\\\" and \\[a] here", "it\\'s \\\"q\\\" and [a] here"],
            'opener behind a marker' => ['- \\# a \\"q\\"', '- \\# a \\"q\\"'],
            'quotes in a quote' => ['> \\"q\\" *b* \\_e_', '> \\"q\\" *b* \\_e_'],
            'quotes and a defined label' => ["[a]: /u\n\n\\\"x\\\" \\[a]", "[a]: /u\n\n\\\"x\\\" \\[a]"],
            'quotes in a cell' => ['| \\"a\\" | b |', '| \\"a\\" | b |'],
        ];
    }

    #[DataProvider('cases')]
    public function testOwnEscapesKeepTheProcedureForm(string $source, string $expected): void
    {
        $written = Carve::formatWithDiagnostics($source . "\n");
        $this->assertSame($expected . "\n", $written->source);
        $this->assertSame([], $written->diagnostics);
        $this->assertTrue(TreeEquality::equal(
            TreeEncoder::decodedForWriter(Carve::parse($source)),
            TreeEncoder::decodedForWriter(Carve::parse($written->source)),
        ));
    }

    public function testQuotedParagraphsScaleLinearly(): void
    {
        $small = self::document(32);
        $large = self::document(256);
        $excess = Linearity::excess(
            static fn () => Writer::formatWithDiagnostics($small),
            static fn () => Writer::formatWithDiagnostics($large),
            8,
        );

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    private static function document(int $paragraphs): string
    {
        $source = '';
        for ($i = 0; $i < $paragraphs; $i++) {
            $source .= 'Paragraph ' . $i . ' names the section \\"Part ' . $i . "\\\" and *bold* text.\n\n";
        }

        return $source;
    }
}
