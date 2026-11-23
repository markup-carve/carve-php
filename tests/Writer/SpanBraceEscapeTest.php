<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5537, C2-W-088: a `{` in the text of a span takes a backslash only where it would open a construct.
 */
final class SpanBraceEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'superscript' => ["{^\\{x^}\n", "{^{x^}\n"],
            'after an escaped caret' => ["{^\\^\\{x^}\n", "{^^{x^}\n"],
            'strike' => ["{~\\{x~}\n", "{~{x~}\n"],
            'strong' => ["*\\{x*\n", "*{x*\n"],
            'class-like text' => ["{^\\{.x^}\n", "{^{.x^}\n"],
            'brace before a mark' => ["{~\\{~}\n", "{~\\{~}\n"],
            'brace that would read as an attribute block' => ["{^\\{x=1^}\n", "{^\\{x=1^}\n"],
            'brace before a node' => ["{^a\\{^}\n", "{^a\\{^}\n"],
            'brace after a node' => ["{^*a*\\{.c}^}\n", "{^*a*\\{.c}^}\n"],
            'brace with a node behind it' => ["{-\\{ #i-}\n", "{-\\{ #i-}\n"],
            'underline keeps it' => ["{_\\{x_}\n", "{_\\{x_}\n"],
            'brace with a closer in the text' => ["{^a \\{.b} c^}\n", "{^a {.b} c^}\n"],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testBraceInSpan(string $source, string $expected): void
    {
        $written = Carve::format($source);
        $this->assertSame($expected, $written);
        $this->assertSame(self::tree($source), self::tree($written));
    }

    /**
     * @param string $source
     *
     * @return string
     */
    private static function tree(string $source): string
    {
        return (string)preg_replace('/"srcByteLength":[0-9]+,/', '', TreeEncoder::json(Carve::parse($source), null));
    }
}
