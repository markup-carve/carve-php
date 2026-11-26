<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Json\TreeEncoder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * C2-A-217, carve-php#6071: object keys sort by UTF-16 code units, which puts a supplementary character (a surrogate
 * pair, D800-DBFF) before U+E000-U+FFFF, unlike UTF-8 byte order.
 */
final class CanonicalKeyOrderTest extends TestCase
{
    public function testKeysSortByUtf16CodeUnits(): void
    {
        $value = [
            "\u{FFFD}" => 1,
            'z' => 2,
            "\u{1F600}" => 3,
            "\u{E000}" => 4,
            'a' => 5,
            "\u{10000}" => 6,
            'é' => 7,
            '10' => 8,
            '9' => 9,
        ];

        $this->assertSame(
            "{\"10\":8,\"9\":9,\"a\":5,\"z\":2,\"é\":7,\"\u{10000}\":6,\"\u{1F600}\":3,\"\u{E000}\":4,\"\u{FFFD}\":1}",
            $this->canonical($value),
        );
    }

    public function testAsciiKeysSortByBytes(): void
    {
        $value = (object)['type' => 'x', 'pos' => ['startLine' => 1, 'endLine' => 1], 'B' => true, '_' => null];

        $this->assertSame('{"B":true,"_":null,"pos":{"endLine":1,"startLine":1},"type":"x"}', $this->canonical($value));
    }

    private function canonical(mixed $value): string
    {
        $out = '';
        $method = new ReflectionMethod(TreeEncoder::class, 'canonicalJson');
        $args = [$value, &$out];
        $method->invokeArgs(null, $args);

        return $out;
    }
}
