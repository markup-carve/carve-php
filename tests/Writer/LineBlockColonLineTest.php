<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5912, C2-W-088, C2-B-123: a line of a line block that bare would close it takes a backslash on its first colon.
 */
final class LineBlockColonLineTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'line of three colons' => ["::: |\n\\:::\n:::\n", "::: |\n\\:::\n:::\n"],
            'line of escaped colons' => ["::: |\n\\::\\:\n:::\n", "::: |\n\\:::\n:::\n"],
            'line of four colons and a space' => ["::: |\n\\:::: \n:::\n", "::: |\n\\::::\n:::\n"],
            'colon line in a quote' => ["> ::: |\n> \\:::\n> :::\n", "> ::: |\n> \\:::\n> :::\n"],
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
