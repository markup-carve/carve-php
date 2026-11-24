<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5863, C2-W-088: where the bare form reads back, `format()` and `write()` of the parsed tree place no
 * backslash. An empty pair `{//}` takes its own closer and forms nothing (C2-I-043, C2-I-057), the mark of `{/}`
 * belongs to its braced opener (C2-I-055), and a braced opener of emphasis or strong pairs with no bare closer
 * (C2-I-058), so none of them braces the span around it (C2-W-069). A backslash of C2-W-125 gives no kind.
 */
final class BracedOpenerInPairWriteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCanonical(): array
    {
        return [
            'reproduction 1 of #5863' => ['- \\/ /.{/}b*c{/.{//}^/|', '- / /.{/}b*c{/.{//}^/|'],
            'reproduction 2 of #5863' => ['- } /*}{/{//}//}}', '- } /*}{/{//}//}}'],
            'reproduction 3 of #5863' => ['> ./[{/{^a^}{//}/*z', '> ./[{/{^a^}{//}/*z'],
            'glued brace of a superscript gives no kind' => ['{{^{,\\{\\{^^}{\\^^', '{{^{,{\\{^^}{^^'],
            'empty braced strong behind a node in an emphasis' => ['x /a{^b^}{**} c/', 'x /a{^b^}{**} c/'],
            'braced opener mark before a brace in a strong' => ['*a{*}b*', '*a{*}b*'],
        ];
    }

    #[DataProvider('provideCanonical')]
    public function testFormatAndWritePlaceOnlyTheProcedureBackslashes(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame($expected . "\n", $result->source);
        self::assertSame([], $result->diagnostics);
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected . "\n", Carve::write($tree));
        self::assertSame(Carve::toHtml($source), Carve::toHtml($expected));
    }

    /**
     * Shapes `write()` misread with no diagnostic: the supplied tree took the local form without a read-back
     * (C2-W-091). The form is not pinned; it must read back.
     *
     * @return array<string, array{string}>
     */
    public static function provideReadBack(): array
    {
        return [
            'braced emphasis opener in the text of a strong' => ['- .{*/{/\\/*}}}'],
            'braced strong opener in an emphasis in a strong' => ['- } /*}{*{/{*}{,b,}/}*}}'],
            'braced emphasis opener in a strong in an emphasis' => ['> x {/{*}[*{/{*{/}*}/}/'],
            'braced strong openers in the text before an emphasis' => ['- .*{*{*/{*}//*}/ *|'],
            'text star before a strong in an emphasis' => ['} /*}{/{^^}{,,}.{*^^^^*}/}*z'],
        ];
    }

    #[DataProvider('provideReadBack')]
    public function testWritesAFormThatReadsBack(string $source): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame([], $result->diagnostics);
        self::assertSame(Carve::toHtml($source), Carve::toHtml($result->source));
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        $written = Carve::writeWithDiagnostics($tree);
        self::assertSame([], $written->diagnostics);
        self::assertSame(Carve::toHtml($source), Carve::toHtml($written->source));
    }
}
