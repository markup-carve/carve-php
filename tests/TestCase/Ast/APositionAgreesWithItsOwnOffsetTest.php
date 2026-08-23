<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A node's line and column name the same position as its own offset.
 *
 * PART 12 §4 gives a span an offset and a line/column pair for one position, so
 * the two are a statement and a spelling of it rather than two measurements. A
 * node whose offset says one thing and whose line/column says another is wrong
 * on its own terms, and needs no second engine to say so.
 *
 * THE SHAPE THAT BROKE IT. A `SourceMap` segment carries the line it starts on,
 * and the resolver advanced the COLUMN by the delta while leaving that line
 * alone. That holds only while a segment stays on one line, and it does not:
 * the block layer joins continuation lines, and it REMOVES lines - a
 * comment-only line, a `+` continuation marker - before the inline parser sees
 * the string it built. Past a removal the column ran on into a line that had
 * already ended.
 *
 * The terminal-comment verse line is the reported case. Offset 8 is the first
 * codepoint of line 3, every engine published 8, and this one spelled it
 * `line 2, column 3` - for a line that is a backtick and a newline, so two
 * codepoints, and has no column 3.
 *
 * The fix asks the SOURCE how many lines the run crossed rather than naming the
 * removal paths, so this test asserts the PROPERTY over each document rather
 * than three expected numbers. A per-path fix would pass a test written the
 * other way and leave the next removal path to be rediscovered.
 */
class APositionAgreesWithItsOwnOffsetTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function documentProvider(): array
    {
        return [
            // The reported document, corpus
            // 380-a-terminal-comment-line-still-leaves-an-empty-verse-line.
            'a terminal comment line in a verse block' => ["::: |\n`\n%%\n:::\n"],
            // A comment-only line BETWEEN two verse lines rather than after
            // them, so the removal sits inside the run instead of ending it.
            'a comment line between two verse lines' => ["::: |\na\n%%\nb\n:::\n"],
            // More than one removed line in a row: the correction has to count
            // them, not notice that there was one.
            'two comment lines in a row' => ["::: |\na\n%%\n%%\nb\n:::\n"],
            // A comment line inside an ordinary paragraph's continuation, which
            // reaches the same resolver by a different route.
            'a comment line inside a paragraph' => ["a\n%%\nb\n"],
            // No removal at all. The correction must leave these exactly as
            // they were, which is what makes a regression in it visible here
            // rather than only in the shapes above.
            'a plain multi-line paragraph' => ["a\nb\nc\n"],
            'a plain verse block' => ["::: |\na\nb\n:::\n"],
        ];
    }

    #[DataProvider('documentProvider')]
    public function testEveryPositionAgreesWithItsOwnOffset(string $source): void
    {
        $parser = new BlockParser(false, false, false, true);
        $doc = (new AstCodec())->encode($parser->parse($source));

        [$lines, $columns] = self::spellingTable($source);

        $findings = [];
        $this->walk($doc, $lines, $columns, $findings);

        // COLLECTED, then asserted once. A tree in which every position past a
        // removed line is wrong has many findings, and asserting inside the
        // walk would report the first and hide the size of it.
        $this->assertSame([], $findings, implode("\n", $findings));
    }

    /**
     * The line and column each codepoint offset of `$source` actually names.
     *
     * Built from the source rather than from the parser, so it cannot inherit
     * the arithmetic under test.
     *
     * @return array{array<int, int>, array<int, int>}
     */
    private static function spellingTable(string $source): array
    {
        $lines = [0 => 1];
        $columns = [0 => 1];
        $line = 1;
        $column = 1;
        $length = mb_strlen($source, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            if (mb_substr($source, $i, 1, 'UTF-8') === "\n") {
                $line++;
                $column = 1;
            } else {
                $column++;
            }
            $lines[$i + 1] = $line;
            $columns[$i + 1] = $column;
        }

        return [$lines, $columns];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, int> $lines
     * @param array<int, int> $columns
     * @param list<string> $findings
     * @param string $path
     */
    private function walk(array $node, array $lines, array $columns, array &$findings, string $path = '$'): void
    {
        $type = $node['type'] ?? null;
        $pos = $node['pos'] ?? null;
        if (is_string($type) && is_array($pos)) {
            foreach ([['start', 'startOffset', 'startLine', 'startColumn'], ['end', 'endOffset', 'endLine', 'endColumn']] as [$which, $o, $l, $c]) {
                $offset = $pos[$o] ?? null;
                $line = $pos[$l] ?? null;
                $column = $pos[$c] ?? null;
                if (!is_int($offset) || !is_int($line) || !is_int($column) || !isset($lines[$offset])) {
                    continue;
                }
                if ($lines[$offset] === $line && $columns[$offset] === $column) {
                    continue;
                }
                $findings[] = sprintf(
                    '%s (%s) at %s: offset %d is line %d column %d, but the node says line %d column %d',
                    $type,
                    $which,
                    $path,
                    $offset,
                    $lines[$offset],
                    $columns[$offset],
                    $line,
                    $column,
                );
            }
        }

        foreach ($node as $key => $value) {
            if ($key === 'pos' || !is_array($value)) {
                continue;
            }
            if (isset($value['type'])) {
                $this->walk($value, $lines, $columns, $findings, $path . '.' . $key);

                continue;
            }
            foreach ($value as $i => $item) {
                if (is_array($item) && isset($item['type'])) {
                    $this->walk($item, $lines, $columns, $findings, $path . '.' . $key . "[$i]");
                }
            }
        }
    }
}
