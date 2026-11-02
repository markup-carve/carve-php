<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A paragraph that starts with a code span is not a folded fence: after the
 * nested item ends, a blank before the next outer paragraph loosens the list
 * (PART 9 §17 L1, carve#4734).
 */
final class ACodeSpanParagraphAfterAnEndedNestedItemLoosensTheListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideLeads(): array
    {
        return [
            'empty code span after a fence' => ["    ```\n  ``\n\n\n    tail\n", '<code></code>'],
            'code span after a fence' => ["    ```\n  `x`\n\n    tail\n", '<code>x</code>'],
            'code span after a div' => ["    :::\n  `x`\n\n    tail\n", '<code>x</code>'],
        ];
    }

    #[DataProvider('provideLeads')]
    public function testTheOuterItemTakesBothParagraphs(string $tail, string $code): void
    {
        $html = CarveConverter::create()->convert("- outer\n  - head\n" . $tail);

        self::assertStringStartsWith("<ul>\n  <li><p>outer</p>", $html);
        self::assertStringContainsString("    <p>$code</p>\n    <p>tail</p>\n  </li>\n</ul>", $html);
    }
}
