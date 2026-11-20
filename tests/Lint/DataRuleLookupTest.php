<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataRuleLookupTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<array{int, int, string}>}>
     */
    public static function cases(): array
    {
        return [
            'duplicate reference URL' => ["[a]: /u\n\n[b]: /u", 'duplicate-reference-url', [[9, 16, '/children/1']]],
            'heading level skip' => ["# One\n\n### Two", 'heading-level-skip', [[7, 14, '/children/1']]],
            'first level two heading' => ["## Two\n", 'heading-level-skip', []],
            'mixed heading markers' => ["= One\n\n# Two", 'heading-marker-mixed', [[0, 5, '/children/0']]],
            'unused footnote' => ['[^a]: Note.', 'unused-footnote-definition', [[0, 4, '/children/0']]],
            'used footnote' => ["[^a]: Note.\n\nSee [^a].", 'unused-footnote-definition', []],
            'unused reference' => ['[a]: /u', 'unused-link-reference-definition', [[0, 7, '/children/0']]],
            'used reference' => ["[a]: /u\n\n[a][]", 'unused-link-reference-definition', []],
        ];
    }

    /**
     * @param string $source
     * @param string $code
     * @param array<array{int, int, string}> $expected
     */
    #[DataProvider('cases')]
    public function testDocumentLookup(string $source, string $code, array $expected): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv('CARVE_LINT_RULES=data');
        try {
            $actual = [];
            foreach (Carve::lint($source, ['rules' => [$code => 'on']]) as $finding) {
                if ($finding->code === $code) {
                    $actual[] = [$finding->startOffset, $finding->endOffset, $finding->path];
                }
            }
            self::assertSame($expected, $actual);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
