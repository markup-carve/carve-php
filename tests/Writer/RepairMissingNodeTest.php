<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5776: a node whose markup the parse holds as text, behind a text that reads on into it or behind a node
 * that formed, is a missing node of the repair rounds (C2-W-088, C2-W-125), and a braced opener of a span's own mark in
 * its text is read back on every write.
 */
final class RepairMissingNodeTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function sources(): array
    {
        return [
            'superscript behind a text' => ['^}{,,}{^^\\{\\^^} {{,\\}{^', '^}{,,}{^^{\\^^} {{,}{^'],
            'superscript behind a subscript' => ['^}{,,,}{^^\\{\\^^} {{,\\}{^', '^}{,,,}{^^{\\^^} {{,}{^'],
            'strong behind a code span' => ['\\``c`* \\*/}{*{//}{/ **}', null],
        ];
    }

    #[DataProvider('sources')]
    public function testWritesAFormThatReadsBack(string $source, ?string $expected): void
    {
        $tree = TreeEncoder::decodedForWriter(Carve::parse($source . "\n"));
        $formatted = Carve::formatWithDiagnostics($source . "\n");
        $json = json_decode(Carve::toJson($source . "\n"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);
        $written = Carve::writeWithDiagnostics($json);
        $this->assertSame([], $formatted->diagnostics);
        $this->assertTrue(TreeEquality::equal($tree, TreeEncoder::decodedForWriter(Carve::parse($formatted->source))));
        if ($expected !== null) {
            $this->assertSame($expected . "\n", $formatted->source);
            $this->assertSame($expected . "\n", $written->source);
        }
    }
}
