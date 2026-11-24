<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5911, C2-B-224: a footnote label keeps its escaped pipe as two characters, in a table cell too.
 */
final class FootnoteKeyCellPipeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'footnote key with an escaped pipe' => ["|[^\\|]|\n", "| [^\\|] |\n"],
            'footnote key with text around the pipe' => ["|[^a\\|b] x|\n", "| [^a\\|b] x |\n"],
            'footnote key with an escaped pipe outside a cell' => ["[^\\|]\n", "[^\\|]\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testTheFormReadsBack(string $source, string $expected): void
    {
        $result = Writer::formatWithDiagnostics($source);
        $this->assertSame($expected, $result->source);
        $this->assertSame([], $result->codes());
        $this->assertSame(self::tree($source), self::tree($result->source));
        $this->assertSame($expected, Carve::format($expected));
    }

    /**
     * @return array<mixed>
     */
    private static function tree(string $source): array
    {
        $tree = json_decode(Carve::toJson($source), true);
        self::assertIsArray($tree);

        return self::strip($tree);
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function strip(array $node): array
    {
        unset($node['pos'], $node['srcByteLength']);
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::strip($value);
            }
        }

        return $node;
    }
}
