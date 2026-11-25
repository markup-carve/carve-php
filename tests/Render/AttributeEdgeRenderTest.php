<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The empty authored id, the merged `style` value and an empty caption, as carve-js writes them.
 */
final class AttributeEdgeRenderTest extends RenderTestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2?: array<string, mixed>}>
     */
    public static function emptyIdProvider(): array
    {
        return [
            'bare key on a paragraph' => ["{id}\na", '<p id="">a</p>'],
            'empty value on a paragraph' => ["{id=\"\"}\na", '<p id="">a</p>'],
            'bare key on a span' => ['[s]{id}', '<p><span id="">s</span></p>'],
            'heading in a section' => ["{id}\n# h", '<section id="">' . "\n" . '  <h1>h</h1>' . "\n" . '</section>'],
            'heading without sections' => ["{id}\n# h", '<h1 id="">h</h1>', ['sections' => false]],
        ];
    }

    /**
     * C2-A-032: a bare key has the empty string as its value, and the empty id is written.
     *
     * @param string $source
     * @param string $expected
     * @param array<string, mixed> $options
     */
    #[DataProvider('emptyIdProvider')]
    public function testEmptyAuthoredIdIsWritten(string $source, string $expected, array $options = []): void
    {
        self::assertSame($expected, Carve::toHtml($source, $options));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mergedStyleProvider(): array
    {
        return [
            'only spaces' => [' ', 'text-align: right;'],
            'a run of semicolons is kept' => ['a;;', 'a;; text-align: right;'],
            'only semicolons' => [';;', ';; text-align: right;'],
            'one semicolon and a space' => ['a; ', 'a; text-align: right;'],
            'a trailing vertical tab' => ["a\v", 'a; text-align: right;'],
            'only a vertical tab' => ["\v", 'text-align: right;'],
            'a plain declaration' => ['color: red ', 'color: red; text-align: right;'],
        ];
    }

    /**
     * C2-H-009, C2-H-015: the authored value without its trailing whitespace, then `;` where it does not end in one.
     */
    #[DataProvider('mergedStyleProvider')]
    public function testMergedStyleValue(string $style, string $expected): void
    {
        self::assertSame(
            '<p style="' . $expected . '">para</p>',
            Carve::toHtml('{style="' . $style . "\" align=right}\npara"),
        );
    }

    /**
     * C2-H-069: a caption that holds only a comment writes no empty line.
     */
    public function testCaptionOfOnlyACommentHoldsNoEmptyLine(): void
    {
        self::assertSame(
            "<table>\n  <caption>\n  </caption>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>",
            Carve::toHtml("| a |\n^ %% c %%"),
        );
    }
}
