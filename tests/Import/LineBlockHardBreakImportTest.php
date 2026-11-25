<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-043, C2-H-195 row 18 (carve#6786, carve#6785): a line-block `<br>` with only whitespace after it is the hard
 * break of the stanza's last line, and a line that is empty or ends in a space or tab keeps its hard break.
 */
final class LineBlockHardBreakImportTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sources(): array
    {
        return [
            'CC-156' => ["::: |\na \\\nb\n:::\n", "::: |\na \\\nb\n:::\n"],
            'CC-157' => ["::: |\na\n\\\nb\n:::\n", "::: |\na\n\\\nb\n:::\n"],
            'CC-160' => ["::: |\na\\\n\nb\n:::\n", "::: |\na\\\n\nb\n:::\n"],
            'last line of the block' => ["::: |\na\\\n:::\n", "::: |\na\\\n:::\n"],
            'only a break, last line' => ["::: |\na\n\\\n:::\n", "::: |\na\n\\\n:::\n"],
            'only a break' => ["::: |\n\\\n:::\n", "::: |\n\\\n:::\n"],
            'tab before the break' => ["::: |\na\t\\\nb\n:::\n", "::: |\na   \\\nb\n:::\n"],
            'space after a code span' => ["::: |\na `x` \\\nb\n:::\n", "::: |\na `x` \\\nb\n:::\n"],
            'row 18: no whitespace' => ["::: |\na\\\nb\n:::\n", "::: |\na\nb\n:::\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testImportWritesTheSource(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->back($source));
        $this->assertRoundTrip($source);
    }

    /**
     * Generated stanzas: words, code spans, trailing spaces and tabs, lines of only a hard break, and empty lines.
     */
    public function testGeneratedLineBlocks(): void
    {
        mt_srand(6786);
        $words = ['a', 'bc', '`c`', '`c d`', '/em/', '*st*', '[l](u)', 'x&y'];
        $tails = ['', '', ' ', '  ', "\t", " \t"];
        for ($n = 0; $n < 1000; $n++) {
            $lines = [];
            $count = mt_rand(1, 6);
            for ($l = 0; $l < $count; $l++) {
                $kind = mt_rand(0, 5);
                if ($kind === 0 && $l > 0 && $l < $count - 1 && $lines[$l - 1] !== '') {
                    $lines[] = '';

                    continue;
                }
                if ($kind === 1) {
                    $lines[] = '\\';

                    continue;
                }
                $line = '';
                $parts = mt_rand(1, 3);
                for ($p = 0; $p < $parts; $p++) {
                    $line .= ($p > 0 ? ' ' : '') . $words[mt_rand(0, count($words) - 1)];
                }
                $tail = $tails[mt_rand(0, count($tails) - 1)];
                $lines[] = $line . ($tail !== '' || mt_rand(0, 2) === 0 ? $tail . '\\' : '');
            }
            $this->assertRoundTrip("::: |\n" . implode("\n", $lines) . "\n:::\n");
        }
    }

    private function assertRoundTrip(string $source): void
    {
        $html = Carve::toHtml($source, ['roundtrip' => true]);

        $this->assertSame($html, Carve::toHtml($this->back($source), ['roundtrip' => true]), $source);
    }

    private function back(string $source): string
    {
        return Carve::write(Carve::importHtml(Carve::toHtml($source, ['roundtrip' => true]))->tree);
    }
}
