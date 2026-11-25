<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6697: the bare form reads a text brace behind a node's closer as that node's attribute block. The block opens
 * on text of the tree, so it reports the kind `{` (C2-W-088), and the escapes of that kind stay.
 */
final class AttributeReadOnKindTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'paragraph' => ['\\,^{^^}{^}^}\\{a\\}a\\{{^{', ',^\\{^^}{^}^}\\{a}a\\{{^\\{'],
            'quote paragraph' => ['> \\,^{^^}{^}^}\\{a\\}a\\{{^{', '> ,^\\{^^}{^}^}\\{a}a\\{{^\\{'],
            'list item (carve#6559 shape 2)' => ['1. \\`^/\\`c`c`\\{a}\\` \\{**}/', '1. \\`^/\\`c`c`\\{a}\\` \\{**}/'],
        ];
    }

    #[DataProvider('cases')]
    public function testTheKindEscapesStay(string $source, string $expected): void
    {
        $written = Carve::formatWithDiagnostics($source);
        self::assertSame($expected . "\n", $written->source);
        self::assertSame([], $written->diagnostics);
        self::assertTrue(TreeEquality::equal(
            TreeEncoder::decodedForWriter(Carve::parse($source)),
            TreeEncoder::decodedForWriter(Carve::parse($written->source)),
        ));
    }
}
