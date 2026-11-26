<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5964, C2-W-125 case five: the repair rounds read the brackets of link text bare in their first round only,
 * then every lone link bracket takes its backslash at once (carve-js `linkFallback`). A document with reference keys
 * keeps the plan rule of #5946 off, so its paragraphs of such links go to the rounds. Outputs are carve-js `next2`.
 */
final class CaseFiveRoundsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function forms(): array
    {
        return [
            'a definition' => ["\\[a [b\\] c](x) \\[a [b\\] c](x) \\[a [b\\] c](x)\n\n[z]: /z\n"],
            'a key the link text spells' => ["\\[a [b\\] c](x) \\[a [b\\] c](x)\n\n[b]: /u\n"],
            'in a quote and list' => ["> - \\[a [b\\] c](x) \\[a [b\\] c](x)\n\n[z]: /z\n"],
            'two openers before a link' => ["\\[a \\[[b\\] c](x) and \\[d [e\\]](y)\n\n[z]: /z\n"],
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

    /**
     * @return array<string, array{string, string}>
     */
    public static function trees(): array
    {
        return [
            'a link the bare text breaks' => ["[e\\]\\[f](x)[[`c` [g\\]{h}](x)\n", "[e\\]\\[f](x)[[`c` [g\\]{h}](x)\n"],
            'an opener inside strong' => ["*\\[a [b\\] c](x)*[b\\]](x)\n\n[r]: /u\n", "*\\[a [b\\] c](x)*[b\\]](x)\n\n[r]: /u\n"],
        ];
    }

    #[DataProvider('trees')]
    public function testWrite(string $source, string $expected): void
    {
        $this->assertSame($expected, Carve::write(Carve::parse($source)));
    }

    public function testWriteIsLinearWithADefinition(): void
    {
        $small = Carve::parse(str_repeat('\\[a [b\\] c](x) ', 40) . "\n\n[z]: /z\n");
        $large = Carve::parse(str_repeat('\\[a [b\\] c](x) ', 320) . "\n\n[z]: /z\n");
        $excess = Linearity::excess(static fn () => Carve::write($small), static fn () => Carve::write($large), 8, 1);

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }
}
