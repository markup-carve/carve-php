<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block's span must grow when its content does.
 *
 * `stampBlockSpan` trims trailing blank lines, which is right for spacing after
 * a paragraph and wrong for a verbatim block that HOLDS the blank: a fence
 * ending with its container rather than with a closer. Before the fix,
 * "```\nx\n\n" and "```\nx\n" reported the same extent despite different
 * content, which no reading of PART 12 section 4 permits (carve-php#1183).
 *
 * The expected values are carve-js's and carve-rs's, which agreed with each
 * other and were what the three-way conformance panel compared against.
 */
class VerbatimBlockSpanCoversItsTrailingBlankTest extends TestCase
{
    /**
     * @return array<string, array{string, int, int}>
     */
    public static function spans(): array
    {
        return [
            'a fence ending at the document, with a blank' => ["```\nx\n\n", 3, 6],
            'the same fence without the blank' => ["```\nx\n", 2, 5],
            'two blanks' => ["```\nx\n\n\n", 4, 7],
            'a fence ending with a list item' => ["- ```\n  x\n\n", 3, 10],
            // CONTROLS: the trim is correct here and must keep working. Neither
            // moves under the change - they bound it rather than prove it.
            'a paragraph followed by a blank' => ["abc\n\n", 1, 3],
            'a terminated fence' => ["```\nx\n```\n", 3, 9],
        ];
    }

    #[DataProvider('spans')]
    public function testTheSpanCoversWhatTheNodeHolds(string $source, int $endLine, int $endOffset): void
    {
        $pos = $this->firstPos($source);

        $this->assertSame($endLine, $pos['endLine'], 'endLine for ' . json_encode($source));
        $this->assertSame($endOffset, $pos['endOffset'], 'endOffset for ' . json_encode($source));
    }

    /**
     * Two documents whose content differs may not report the same extent. This
     * is the defect in its most reduced form, and it needs no ruling about
     * where an unterminated block ends.
     */
    public function testDifferentContentGetsADifferentExtent(): void
    {
        $withBlank = $this->firstPos("```\nx\n\n");
        $without = $this->firstPos("```\nx\n");

        $this->assertNotSame($without['endOffset'], $withBlank['endOffset']);
    }

    /**
     * @return array{endLine: int, endOffset: int}
     */
    private function firstPos(string $source): array
    {
        $json = json_decode(
            (string)shell_exec(
                'printf %s ' . escapeshellarg($source) . ' | ' . escapeshellarg(__DIR__ . '/../bin/carve') . ' --json',
            ),
            true,
        );
        assert(is_array($json));

        /** @var array{children: array<int, array{pos: array{endLine: int, endOffset: int}}>} $json */
        return $json['children'][0]['pos'];
    }
}
