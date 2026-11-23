<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Lint\Linter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5788: a table reads its keys in the letter case written (C2-B-227); only `id` and `class` fold (C2-I-042).
 */
final class TableKeyLetterCaseTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'Table keeps a tsv fence a code block' => [
                "{Table}\n```tsv\na\tb\n```\n",
                "<pre Table=\"\"><code class=\"language-tsv\">a\tb\n</code></pre>",
            ],
            'TABLE keeps a csv fence a code block' => [
                "{TABLE}\n```csv\na,b\n```\n",
                "<pre TABLE=\"\"><code class=\"language-csv\">a,b\n</code></pre>",
            ],
            'table still makes a delimited table' => [
                "{table}\n```csv\na,b\n```\n",
                "<table>\n  <thead>\n    <tr><th scope=\"col\">a</th><th scope=\"col\">b</th></tr>\n  </thead>\n</table>",
            ],
            'Aligns sets no alignment' => [
                "{Aligns=right}\n| a |\n",
                "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>",
            ],
            'aligns sets the alignment' => [
                "{aligns=right}\n| a |\n",
                "<table>\n  <tbody>\n    <tr><td style=\"text-align: right;\">a</td></tr>\n  </tbody>\n</table>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTableKeysMatchExactly(string $source, string $html): void
    {
        $this->assertSame($html, trim(Carve::renderWithDiagnostics($source)->html));
    }

    public function testNearMissInAnotherLetterCaseIsReported(): void
    {
        $found = [];
        foreach (Linter::lint("{Table}\n```tsv\na\tb\n```\n") as $diagnostic) {
            $found[] = [$diagnostic->code, $diagnostic->startOffset, $diagnostic->endOffset];
        }
        $this->assertSame([['consumed-key-near-miss', 8, 22]], $found);
    }
}
