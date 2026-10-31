<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` line's cell past the row's last column is a new cell of the row
 * above, and lint reports it on the `+` line (PART 9 §5 T6, carve#4470).
 */
class AContinuationCellPastTheLastColumnIsANewCellTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'one appended cell' => [
                "| a | b |\n+ c | d | e |\n",
                "<table>\n  <tbody>\n    <tr><td>a c</td><td>b d</td><td>e</td></tr>\n  </tbody>\n</table>",
            ],
            'a skipped slot is an empty cell, a later line joins by column' => [
                "| a |\n+ b | | c |\n+ d | | e |\n",
                "<table>\n  <tbody>\n    <tr><td>a b d</td><td></td><td>c e</td></tr>\n  </tbody>\n</table>",
            ],
            'a trailing empty slot appends nothing' => [
                "| a |\n+ b | |\n",
                "<table>\n  <tbody>\n    <tr><td>a b</td></tr>\n  </tbody>\n</table>",
            ],
            'an appended caret is text' => [
                "| a | x |\n| b |\n+ c | ^ |\n",
                "<table>\n  <tbody>\n    <tr><td>a</td><td>x</td></tr>\n    <tr><td>b c</td><td>^</td></tr>\n  </tbody>\n</table>",
            ],
            'a header row appends a header cell' => [
                "|= a |\n+ b | c |\n| d | e |\n",
                "<table>\n  <thead>\n    <tr><th scope=\"col\">a b</th><th scope=\"col\">c</th></tr>\n  </thead>\n  <tbody>\n    <tr><td>d</td><td>e</td></tr>\n  </tbody>\n</table>",
            ],
        ];
    }

    #[DataProvider('htmlProvider')]
    public function testHtml(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testTheAppendedCellOpensWithACellWrapOnItsPipe(): void
    {
        $document = (new BlockParser(false, false, false, true))->parse("| a | b |\n+ c | d | e |\n");
        $cell = self::at((new AstCodec())->encode($document), 'children', 0, 'rows', 0, 'cells', 2);

        $this->assertArrayNotHasKey('pos', $cell);
        $this->assertSame('cell_wrap', self::at($cell, 'children', 0)['type']);
        $this->assertSame(1, self::at($cell, 'children', 0)['line']);
        $this->assertSame(['startLine' => 2, 'endLine' => 2, 'startColumn' => 9, 'endColumn' => 10, 'startOffset' => 18, 'endOffset' => 19], self::at($cell, 'children', 0, 'pos'));
        $this->assertSame(['type' => 'text', 'value' => 'e'], array_diff_key(self::at($cell, 'children', 1), ['pos' => true]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'one appended cell' => ["| a | b |\n+ c | d | e |\n"],
            'a skipped slot' => ["| a |\n+ b | | c |\n+ d | | e |\n"],
            'a header row' => ["|= a |\n+ b | c |\n| d | e |\n"],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testTheWriterKeepsTheCellOnItsContinuationLine(string $source): void
    {
        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    public function testLintReportsTheContinuationLine(): void
    {
        $warnings = (new DocumentLinter())->lint("| a | b |\n+ c | d | e |\n");
        $found = array_values(array_filter($warnings, static fn ($warning): bool => $warning->rule === 'table-row-cell-count'));

        $this->assertCount(1, $found);
        $this->assertSame(2, $found[0]->line);
        $this->assertSame(1, $found[0]->column);
    }

    /**
     * @param array<mixed> $tree
     * @param string|int ...$path
     *
     * @return array<mixed>
     */
    private static function at(array $tree, string|int ...$path): array
    {
        foreach ($path as $key) {
            $tree = $tree[$key] ?? null;
            self::assertIsArray($tree);
        }

        return $tree;
    }

    public function testAContinuationWithinTheRowIsNotReported(): void
    {
        $warnings = (new DocumentLinter())->lint("| a | b |\n+ c | d |\n");

        $this->assertSame([], array_filter($warnings, static fn ($warning): bool => $warning->rule === 'table-row-cell-count'));
    }
}
