<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5844: a malformed byte in a lint message value reads as U+FFFD (C2-B-218) instead of throwing.
 */
final class MalformedVersionValueTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int, int}>
     */
    public static function cases(): array
    {
        return [
            'frontmatter carve key' => ["---\ncarve: \xc0\n---\n", "\u{FFFD}", 11, 12],
            'frontmatter carve-version key' => ["---\ncarve-version: a\xc0\n---\n", "a\u{FFFD}", 19, 21],
            'truncated sequence is one U+FFFD' => ["---\ncarve: a\xe2\x82\n---\n", "a\u{FFFD}", 11, 14],
            'stamp comment' => ["x\n\n%% carve-version: \xff\xfe\n", "\u{FFFD}\u{FFFD}", 21, 23],
        ];
    }

    #[DataProvider('cases')]
    public function testTheValueReadsAsReplacementCharacters(string $source, string $version, int $start, int $end): void
    {
        $found = [];
        foreach (Carve::lint($source) as $finding) {
            if ($finding->code === 'carve-version-unsupported') {
                $found[] = [$finding->startOffset, $finding->endOffset, $finding->message];
            }
        }

        self::assertSame([
            [
                $start,
                $end,
                'The declared Carve version "' . $version . '" is not a dotted numeric version. '
                . 'Write `0.2` as the value of this declaration, or remove it.',
            ],
        ], $found);
    }
}
