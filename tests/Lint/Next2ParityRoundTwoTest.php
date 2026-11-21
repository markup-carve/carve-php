<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2ParityRoundTwoTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function cases(): array
    {
        return [
            'term before hyphens' => [":: term\n---", ['description-term-multi-line', 'thematic-break-spelling']],
            'quoted spaced hyphens after heading' => ["> # H\n> - - -", ['deprecated-spaced-thematic-break']],
            'first definition-shaped paragraph line' => [": definition\n---", ['setext-heading-underline']],
            'later definition-shaped paragraph line' => [
                "text\n: definition", ['block-opener-without-blank-line', 'markdown-definition-paragraph'],
            ],
            'tabbed heading continuation' => ["# H\n\t---", ['deprecated-indented-line-under-heading', 'thematic-break-spelling']],
            'tabbed block in item' => [
                "- item\n  # H\n\t***",
                ['block-opener-without-blank-line', 'list-item-block-overindented'],
            ],
            'line outside quote heading' => ["> # H\n ---", ['thematic-break-spelling']],
            'indented heading continuation' => ["# H\n  text", ['deprecated-indented-line-under-heading']],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $expected
     */
    #[DataProvider('cases')]
    public function testCoreLint(string $source, array $expected): void
    {
        self::assertSame($expected, array_map(static fn ($d): string => $d->code, Carve::lint($source)));
    }
}
