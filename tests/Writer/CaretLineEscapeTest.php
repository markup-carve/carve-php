<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-146, C2-W-092 (carve#6585 A1): a caret line that stays text is written with its caret escaped, so canonical
 * output passes `unattached-caption`.
 */
final class CaretLineEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function clauseCases(): array
    {
        $ids = ['CC-2029', 'CC-2030', 'CC-2031', 'CC-2032', 'CC-2033', 'CC-2034', 'CC-2035', 'CC-2036', 'CC-2037'];

        return array_combine($ids, array_map(static fn (string $id): array => [$id], $ids));
    }

    #[DataProvider('clauseCases')]
    public function testPinnedClauseCase(string $id): void
    {
        $case = Corpus::clauseCases()[$id];
        $options = $case['options'];
        $expected = $case['expected'];
        $this->assertIsArray($options);
        $this->assertIsArray($expected);
        if ($case['kind'] === 'write') {
            $tree = $case['tree'];
            $this->assertIsArray($tree);
            $result = Carve::writeWithDiagnostics($tree, null, $options);
        } else {
            $input = $case['input'];
            $this->assertIsString($input);
            $result = Carve::formatWithDiagnostics($input, $options);
        }
        $this->assertSame($expected['carve'], $result->source);
        $this->assertSame([], $result->diagnostics);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'footnote marker line' => ["x[^1]\n\n[^1]: ^ a\n", "x[^1]\n\n[^1]: \\^ a\n"],
            'footnote before its reference' => ["[^1]: ^ a\n\nx[^1]\n", "[^1]: \\^ a\n\nx[^1]\n"],
            'item marker line' => ["- ^ a\n", "- \\^ a\n"],
            'later line of an item' => ["- a\n  ^ b\n", "- a\n  \\^ b\n"],
            'line block line' => ["::: |\na\n^ x\n:::\n", "::: |\na\n\\^ x\n:::\n"],
            'nested quote' => ["> > a\n> > ^ b\n", "> > a\n> > \\^ b\n"],
            'behind a hard break' => ["a\\\n^ b\n", "a\\\n\\^ b\n"],
            'inline content behind the gap' => ["a\n^ *b*\n", "a\n\\^ *b*\n"],
            'caret without a gap' => ["a\n^x\n", "a\n^x\n"],
            'caret alone' => ["a\n^\n", "a\n^\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testCaretLineShapes(string $source, string $expected): void
    {
        $written = Carve::format($source);

        $this->assertSame($expected, $written);
        $this->assertSame(Carve::toHtml($source), Carve::toHtml($written));
        $codes = array_map(static fn ($d): string => $d->code, Carve::lint($written));
        $this->assertNotContains('unattached-caption', $codes);
    }
}
