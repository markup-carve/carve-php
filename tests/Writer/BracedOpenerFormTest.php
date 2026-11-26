<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-069: a text braced opener of a span's own mark is one token that pairs with no bare delimiter (C2-I-055,
 * C2-I-058), so by itself it does not brace the span (carve#6564). C2-W-125 case one: a brace glued before its own
 * closer takes the backslash before the mark behind it does (carve#6560).
 */
final class BracedOpenerFormTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCanonical(): array
    {
        return [
            'braced opener behind a code span in a strong' => ['*}^.`c`{*a/}*.{//}za\\`', '*}^.`c`{*a/}*.{//}za`'],
            'bare content slash that pairs in an emphasis' => ['1. \\}{/©*}\\/b/}{/', '1. }{/©*}/b/}{/'],
            'case-one brace before a superscript closer' => ['^}{,,}{^^\\{\\^^} {{,\\}{^', '^}{,,}{^^\\{^^} {{,}{^'],
            'case-one brace behind the same mark' => ['x{^^\\{^^}', 'x{^^\\{^^}'],
        ];
    }

    #[DataProvider('provideCanonical')]
    public function testFormatTakesTheCanonicalForm(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source . "\n");
        self::assertSame($expected . "\n", $result->source);
        self::assertSame([], $result->diagnostics);
        self::assertSame(Carve::toHtml($source . "\n"), Carve::toHtml($expected . "\n"));
    }

    /**
     * The span takes its bare form; backslashes on other braces and marks of these texts are carve-php#5869 and #5870.
     *
     * @return array<string, array{string, string}>
     */
    public static function provideBare(): array
    {
        return [
            'braced openers between code spans' => ['- ./`c`{`c`{/c/', '- ./`c`'],
            'braced opener before a code span and a strong' => ['> /{/`c`*{**z*/*', '> /{/`c`'],
            'braced opener and an empty pair after a code span' => ['- ©{//}©/\\{c`c`.a\\`\\{{/{//}/', '- ©{//}©/'],
        ];
    }

    #[DataProvider('provideBare')]
    public function testSpanStaysBare(string $source, string $prefix): void
    {
        $result = Carve::formatWithDiagnostics($source . "\n");
        self::assertTrue(str_starts_with($result->source, $prefix), $result->source);
        self::assertSame([], $result->diagnostics);
        self::assertSame(Carve::toHtml($source . "\n"), Carve::toHtml($result->source));
    }
}
