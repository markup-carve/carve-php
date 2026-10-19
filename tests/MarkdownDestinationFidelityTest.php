<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownDestinationFidelityTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function cases(): array
    {
        return [
            'links and image' => ['[a](/ü.png) and ![b](/ü.png) and [c](/%C3%BC)', '[a](/ü.png) and ![b](/ü.png) and [c](/%C3%BC)', ['markdown-paragraph', 'markdown-link', 'markdown-image', 'markdown-link']],
            'image' => ['![x](/ü.png)', '![x](/ü.png)', ['markdown-paragraph', 'markdown-image']],
            'pointy autolink' => ['<https://e.com/a[b]>', '<https://e.com/a[b]>', ['markdown-paragraph', 'markdown-autolink']],
            'bracket destination' => ['[a](/x[y])', '[a](/x[y])', ['markdown-paragraph', 'markdown-link']],
            'IDN host' => ['[b](https://bücher.de/ö?q=ä#ü)', '[b](https://bücher.de/ö?q=ä#ü)', ['markdown-paragraph', 'markdown-link']],
            'no break space' => ["[x](/ö\u{00A0}ü)", '[x](/ö%C2%A0ü)', ['markdown-paragraph', 'markdown-link']],
            'narrow no break space' => ["[x](/ö\u{202F}ü)", '[x](/ö%E2%80%AFü)', ['markdown-paragraph', 'markdown-link']],
            'C1 control' => ["[x](/ö\u{0085}ü)", '[x](/ö%C2%85ü)', ['markdown-paragraph', 'markdown-link']],
            'format character' => ["[x](/ö\u{200B}ü)", "[x](/ö\u{200B}ü)", ['markdown-paragraph', 'markdown-link']],
            'reference definition' => ["[r][id]\n\n[id]: /ü.png\n", "[r][id]\n\n[id]: /ü.png\n", ['markdown-paragraph', 'markdown-reference-link', 'markdown-reference-definition']],
        ];
    }

    /**
     * Verify an imported destination and its diagnostics.
     *
     * @param string $source
     * @param string $expected
     * @param list<string> $codes
     */
    #[DataProvider('cases')]
    public function testDestinationAndReport(string $source, string $expected, array $codes): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        self::assertSame($expected, $result->value);
        self::assertSame($codes, array_column($result->diagnostics, 'code'));
        foreach ($result->diagnostics as $row) {
            self::assertSame('info', $row->severity);
            self::assertSame(in_array($row->code, ['markdown-autolink', 'markdown-reference-link', 'markdown-reference-definition'], true) ? 'normalized' : 'preserved', $row->fidelity);
            self::assertSame('exact', $row->confidence);
            self::assertSame($row->code === 'markdown-reference-definition' ? 'line:3' : 'line:1', $row->path);
        }
    }

    public function testUnicodeFragmentRewrite(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("# Über Uns\n\nSee [a](#über-uns).\n");
        self::assertSame("# Über Uns\n\nSee [a](#Über-Uns).\n", $result->value);
        $rows = $result->diagnostics;
        self::assertNotEmpty($rows);
        $row = $rows[count($rows) - 1];
        self::assertSame('link-fragment-case-rewritten', $row->code);
        self::assertSame('link:#über-uns', $row->path);
        self::assertSame('Rewrote link fragment #über-uns to #Über-Uns, the one heading id it matches without regard to case.', $row->message);
    }
}
