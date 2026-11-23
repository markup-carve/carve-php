<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Test\Support\Linearity;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-W-124: the backtick kind escapes every potential opener of the leaf (carve-php#5758, #5759), and a
 * local write that reads as another tree goes to the read-back rounds (carve-php#5759, #5760).
 */
final class BacktickKindReadBackTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function canonicalForms(): array
    {
        return [
            '#5758 ticks in two emphasis spans' => ["/\\`/ /\\`/\n", "/\\`/ /\\`/\n"],
            '#5758 glued emphasis spans behind text' => ["q /\\`/{/\\`/}\n", "q /\\`/{/\\`/}\n"],
            '#5758 a span with a tick before a lone tick' => ["/`c`\\`/ \\`/\n", "/`c`\\`/ \\`/\n"],
            '#5759 a tick glued behind a code span' => ["\\`\\`a\\`\n\\`  \\```b`z`\\`\n", "\\`\\`a\\`\n\\`  \\```b`z`\\`\n"],
            '#5759 a glued tick and a tick on the next line' => ["`z`\\`\n\\`\n", "`z`\\`\n\\`\n"],
            '#5759 a glued tick before a lone tick' => ["a`z`\\`x```x`\n", "a`z`\\`x\\```x\\`\n"],
        ];
    }

    #[DataProvider('canonicalForms')]
    public function testCanonicalForm(string $source, string $expected): void
    {
        $result = Writer::formatWithDiagnostics($source);
        self::assertSame($expected, $result->source);
        self::assertSame([], $result->diagnostics);
        self::assertSame($expected, Carve::format($expected));
    }

    public function testTheTreeOf5759ReadsBack(): void
    {
        $tree = [

            'astVersion' => '2.0',
            'type' => 'document',
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => '``a`'],
                        ['type' => 'soft_break'],
                        ['type' => 'text', 'value' => '`  ```b'],
                        ['type' => 'code', 'value' => 'z'],
                        ['type' => 'text', 'value' => '`'],
                    ],
                ],
            ],
        ];
        $result = Writer::writeWithDiagnostics($tree);
        self::assertSame("\\`\\`a\\`\n\\`  \\```b`z`\\`\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function misreads(): array
    {
        return [
            '#5760 nested superscripts with two slashes' => [
                (string)file_get_contents(dirname(__DIR__) . '/fixtures/writer/nested-superscript-slashes.txt'),
            ],
            'a slash behind an escaped brace in a superscript' => ["{^{//}{x/^}\n"],
            'a slash pair behind an escaped brace' => ["{^{//}{x/^}^*}^}\n"],
            'brackets past the depth cap in link text' => [
                "[x]: /u\n\n[" . str_repeat('\\[', 200) . 'a' . str_repeat('\\]', 200) . "](u)\n",
            ],
        ];
    }

    #[DataProvider('misreads')]
    public function testTheFormReadsBack(string $source): void
    {
        $result = Writer::formatWithDiagnostics($source);
        self::assertSame([], $result->diagnostics);
        self::assertTrue(TreeEquality::equal(self::tree($source), self::tree($result->source)), $result->source);
        self::assertSame($result->source, Carve::format($result->source));
    }

    /**
     * A text that escapes one bracket kind many times is scanned once per kind, not once per escape.
     */
    public function testManyEscapesOfOneKindAreLinear(): void
    {
        $build = static fn (int $n): string => "[x]: /u\n\n[" . str_repeat('\\[', $n) . 'a' . str_repeat('\\]', $n) . "](u)\n";
        $small = $build(2_000);
        $large = $build(32_000);
        $excess = Linearity::excess(static fn () => Carve::format($small), static fn () => Carve::format($large), 16, 1);

        self::assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    /**
     * @return array<mixed>
     */
    private static function tree(string $source): array
    {
        return TreeEncoder::decodedForWriter(Carve::parse($source));
    }
}
