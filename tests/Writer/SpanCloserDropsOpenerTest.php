<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088: where the bare form reads back, no backslash is placed. A braced opener in the text of a span that its
 * closer drops, the mark a bare brace takes, and the opener of a span written braced pair with nothing (C2-I-051,
 * C2-I-055, C2-I-060), and `{//}` holds nothing (C2-I-043); a brace glued before its span's closer takes that closer
 * (C2-I-057, C2-W-125 case one); a strong whose content ends with its mark is braced before the rounds (C2-W-069,
 * carve#6469) (carve-php#5776).
 */
final class SpanCloserDropsOpenerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCanonical(): array
    {
        return [
            'reproduction 1 of #5776' => ['\\``c`* \\*/}{*{//}{/ **}', '\\``c`* */}{*{//}{/ **}'],
            'strong braced for its last star behind a star' => ['x \\*/}{*. **}', 'x */}{*. **}'],
            'strong braced for its last star holding braces' => ['x \\*/}{*{//}{/ **}', 'x */}{*{//}{/ **}'],
            'slash behind the brace mark in a strong' => ['*{//*//} c*', '*{//*//} c*'],
            'brace before a star in an emphasis' => ['}/{*/*}/', '}/{*/*}/'],
            'brace before a slash in a strong' => ['*a{/*©a/} c*', '*a{/*©a/} c*'],
            'star before a strong behind a letter' => ['.*a{* *} c*x*z', '.*a{* *} c*x*z'],
            'star before a strong holding a slash' => ['}*a{*\\/x y*}**z', '}*a{*/x y*}**z'],
            'slash before an emphasis behind a letter' => ['/a{/©a/} c/x /z', '/a{/©a/} c/x /z'],
            'strong ending in a brace and its mark' => ['*{**©*}x*z*z', '*{**©*}x*z*z'],
            'emphasis ending in a brace and its mark' => ['./{//*©/}/./z', './{//*©/}/./z'],
            'braced strong opening with a brace and its mark' => ['.*{*b*\\**}x*z', '.{*\\{*b*}**}x*z'],
            'empty braced strong inside a strong' => ['*}/{**}{**{* // /z', '*}/{**}{**{* // /z'],
            'empty braced emphasis inside a strong before a slash' => ['\\``c`* {*{/{//}{/ b/c.**}}', '\\``c`* {*{/{//}{/ b/c.**}}'],
            'text brace before a strong holding an empty braced emphasis' => ['\\``c`* {/*{//} {/  **^*|', '\\``c`* {/*{//} {/  **^*|'],
            'brace glued before the closer of a subscript' => ['{, \\^\\{\\{,,} ', '{, ^{\\{,,}'],
            'brace glued before the closer of a highlight' => ['{=a\\{=}', '{=a\\{=}'],
        ];
    }

    #[DataProvider('provideCanonical')]
    public function testFormatPlacesOnlyTheProcedureBackslashes(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame($expected . "\n", $result->source);
        self::assertSame([], $result->diagnostics);
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected . "\n", Carve::write($tree));
        self::assertSame(Carve::toHtml($source), Carve::toHtml($expected));
    }
}
