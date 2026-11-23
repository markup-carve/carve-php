<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodePointMarkerColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function markers(): array
    {
        return [
            'astral footnote label' => ["[^😀]: ```\n       x\n       ```\n", " x\n"],
            'ill-formed byte in a footnote label' => ["[^\xFF]: ```\n       x\n       ```\n", " x\n"],
            'astral glued attributes on a list marker' => ["-{title=\"😀\"} ```\n              x\n              ```\n", " x\n"],
            'astral glued attributes at the content column' => ["-{title=\"😀\"} ```\n             x\n             ```\n", "x\n"],
        ];
    }

    /**
     * C2-B-003, C2-B-027, C2-B-029: a marker span takes one column per code point.
     *
     * @param string $source
     * @param string $value
     *
     * @return void
     */
    #[DataProvider('markers')]
    public function testMarkerSpanCountsCodePoints(string $source, string $value): void
    {
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($tree);
        self::assertSame($value, self::codeValue($tree));
    }

    /**
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function codeValue(array $node): ?string
    {
        if (($node['type'] ?? null) === 'code_block') {
            return is_string($node['value'] ?? null) ? $node['value'] : null;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $value = is_array($child) ? self::codeValue($child) : null;
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
