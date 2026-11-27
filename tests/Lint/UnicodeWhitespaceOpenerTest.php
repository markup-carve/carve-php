<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnicodeWhitespaceOpenerTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function spaces(): array
    {
        return [
            'narrow no-break space' => ["\u{202F}", 9],
            'no-break space' => ["\u{00A0}", 8],
            'em space' => ["\u{2003}", 9],
            'byte order mark' => ["\u{FEFF}", 9],
        ];
    }

    public function testNextLineIsNotEcmaWhitespace(): void
    {
        $source = "\\[a]: \u{0085}/u\n\n[a][]";
        $codes = array_map(static fn ($finding): string => $finding->code, Carve::lint($source));

        self::assertNotContains('unclosed-inline-emphasis', $codes);
    }

    #[DataProvider('spaces')]
    public function testUnclosedEmphasisAfterUnicodeWhitespace(string $space, int $offset): void
    {
        $source = "\\[a]: {$space}/u\n\n[a][]";
        $findings = array_values(array_filter(
            Carve::lint($source),
            static fn ($finding): bool => $finding->code === 'unclosed-inline-emphasis',
        ));

        self::assertCount(1, $findings);
        self::assertSame([$offset, $offset + 1], [$findings[0]->startOffset, $findings[0]->endOffset]);
    }
}
