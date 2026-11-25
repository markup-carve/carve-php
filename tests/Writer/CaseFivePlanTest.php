<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5946, C2-W-125 case five: the plan escapes a text `[` before an inline link whose text holds one escaped
 * `]`, as carve-js#5592 does. The repair rounds placed those escapes one link per round, so a paragraph of such links
 * was quadratic. Outputs are carve-js `next2`.
 */
final class CaseFivePlanTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function forms(): array
    {
        return [
            'one link' => ["\\[a [b\\] c](x)\n"],
            'a paragraph of links' => [str_repeat('\\[a [b\\] c](x) ', 5) . "\\[a [b\\] c](x)\n"],
            'two openers before a link' => ["\\[a \\[[b\\] c](x) and \\[d [e\\]](y)\n"],
            'in a quote and list' => ["> - \\[a [b\\] c](x) \\[a [b\\] c](x)\n"],
            'inside strong' => ["*\\[a [b\\] c](x) \\[a [b\\] c](x)*\n"],
            'an image before keeps its rounds' => ["!\\[a [b\\] c](x) \\[a [b\\] c](x)\n"],
            'a definition keeps the rounds' => ["\\[a [b\\] c](x) \\[a [b\\] c](x)\n\n[z]: /z\n"],
            'a key the link text spells' => ["\\[a [b\\] c](x) \\[a [b\\] c](x)\n\n[b]: /u\n"],
        ];
    }

    #[DataProvider('forms')]
    public function testWriteMatchesFormat(string $source): void
    {
        $result = Carve::writeWithDiagnostics(Carve::parse($source));
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->diagnostics);
        $this->assertSame($source, Carve::format($source));
    }

    public function testImportsWriteTheEscapes(): void
    {
        $expected = "\\[a [b\\] c](x) \\[a [b\\] c](x)\n";
        $this->assertSame($expected, Carve::importHtml('<p>[a <a href="x">b] c</a> [a <a href="x">b] c</a></p>')->source);
        $this->assertSame($expected, Carve::importDocument("[a [b] c](x) [a [b] c](x)\n", ['from' => 'djot'])->source);
    }

    public function testWriteIsLinear(): void
    {
        $small = Carve::parse(str_repeat('\\[a [b\\] c](x) ', 40) . "\n");
        $large = Carve::parse(str_repeat('\\[a [b\\] c](x) ', 320) . "\n");
        $excess = Linearity::excess(static fn () => Carve::write($small), static fn () => Carve::write($large), 8, 1);

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }
}
