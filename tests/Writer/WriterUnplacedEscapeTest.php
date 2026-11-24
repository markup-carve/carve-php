<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088: a backslash the procedure does not place is a defect, and so is a missing one (carve-php#5849).
 */
final class WriterUnplacedEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCanonical(): array
    {
        return [
            // C2-I-047, C2-I-053: a mark the span holds between word characters closes nothing.
            'slash text around a strong holding a slash' => ['/*a/b c*/', '/*a/b c*/'],
            'slash text around a strong, word behind' => ['/*a/b c*/x', '/*a/b c*/x'],
            'star text around an emphasis holding a star' => ['*/a*b c/*', '*/a*b c/*'],
            'star text beside a braced strong' => ['*{*a*b*}x*z', '*{*a*b*}x*z'],
            'star text beside a braced strong, spaced' => ['*{*a*b*} *z', '*{*a*b*} *z'],
            'emphasis whose last star closes a strong mark' => ['}*/a*b\*/ c*{*z', '}*/a*b*/ c*{*z'],
            'slash text before a strong that only opens one' => ['x* /©*/x*.*z', 'x* /©*/x*.*z'],
            // C2-W-069, C2-I-049: a mark glued before the closer of its span makes a run.
            'braced strong holding a starred word' => ['{**a**}', '{**a**}'],
            'braced strong holding a symbol' => ['/[x/*\*©\**', '/[x/{**©**}'],
            'braced emphasis holding a slashed word' => ['{/\/a\//}', '{//a//}'],
            'star text before a braced strong of a star' => ['*{***}*z *z', '*{***}*z *z'],
            // C2-W-069: an opener inside a nested span is dropped when that span closes.
            'strong around an emphasis opening a star' => ['*/*a/*', '*/*a/*'],
            // C2-W-088: each block opener behind the marker of another block takes its own escape.
            'star marker line' => ['\* * *', '\* \* *'],
            'hyphen marker line' => ['\- - -', '\- \- -'],
            'quote and hyphen marker' => ['\> - x', '\> \- x'],
            // C2-W-125 case five: only a lone bracket of a note takes a backslash; C2-I-078: `[^` is no label.
            'note with a caret pair' => ['x ^[^[b]\[^]', 'x ^[^[b]\[^]'],
            'note in an item' => ['- ^[[b]\[^]', '- ^[[b]\[^]'],
            'note with a caret label behind a pair' => ['- x ^[[b][^]]', '- x ^[[b][^]]'],
        ];
    }

    #[DataProvider('provideCanonical')]
    public function testFormatPlacesOnlyTheProcedureBackslashes(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame($expected . "\n", $result->source);
        self::assertSame([], $result->diagnostics);
        self::assertSame(Carve::toHtml($source), Carve::toHtml($result->source));
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected . "\n", Carve::write($tree));
    }
}
