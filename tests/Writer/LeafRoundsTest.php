<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-W-125: the read-back rounds run per leaf block, so equal paragraphs get one form (carve-php#5668), and
 * a rest of backticks that pairs with the opening run of a code span behind it is reported in a later round
 * (carve-php#5709).
 */
final class LeafRoundsTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return rtrim((string)file_get_contents(dirname(__DIR__) . '/fixtures/writer/' . $name), "\n");
    }

    public function testEqualParagraphsGetOneForm(): void
    {
        foreach ([2, 3] as $count) {
            $source = implode("\n\n", array_fill(0, $count, self::fixture('superscript-unit.txt'))) . "\n";
            $expected = implode("\n\n", array_fill(0, $count, self::fixture('superscript-unit-form.txt'))) . "\n";
            self::assertSame($expected, Carve::format($source));
        }
    }

    public function testEqualItemsGetOneForm(): void
    {
        $item = self::fixture('superscript-item-variant.txt');
        $out = Carve::format($item . "\n\n" . $item . "\n");
        [$first, $second] = explode("\n\n", rtrim($out, "\n"));
        self::assertSame($first, $second);
    }

    public function testRestOfTicksBeforeCodeSpanIsReported(): void
    {
        $result = Writer::writeWithDiagnostics(self::paragraph([
            ['type' => 'text', 'value' => 'a'],
            ['type' => 'soft_break'],
            ['type' => 'text', 'value' => '  ` ` '],
            ['type' => 'code', 'value' => 'z'],
        ]));
        self::assertSame("a\n  \\` \\` `z`\n", $result->source);
        self::assertSame(['field-unspellable'], array_map(static fn ($d): string => $d->code, $result->diagnostics));
    }

    public function testTicksPairingInsideCodeSpellingAreEscaped(): void
    {
        $result = Writer::writeWithDiagnostics(self::paragraph([
            ['type' => 'text', 'value' => 'a`b a```a b'],
            ['type' => 'code', 'value' => 'a`b'],
            ['type' => 'text', 'value' => 'b `bb'],
        ]));
        self::assertSame("a\\`b a\\`\\`\\`a b``a`b``b \\`bb\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * @param array<array<string, mixed>> $inlines
     *
     * @return array<string, mixed>
     */
    private static function paragraph(array $inlines): array
    {
        return ['astVersion' => '2.0', 'type' => 'document', 'children' => [['type' => 'paragraph', 'children' => $inlines]]];
    }
}
