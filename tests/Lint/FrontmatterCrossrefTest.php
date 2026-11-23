<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-153, C2-I-097 (carve#6390): `frontmatter-crossref` matches ids under NFC and skips ids that a cross-reference
 * target also carries, in the hand-written rules and the data rules alike.
 */
final class FrontmatterCrossrefTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<string>}>
     */
    public static function cases(): array
    {
        $decomposed = "e\u{0301}";
        $composed = "\u{00E9}";
        $cases = [
            'metadata key' => ["a\n\n{#m}\n---yaml\nk: v\n---\n\nSee </#m>.\n", ['frontmatter-crossref']],
            'decomposed id, composed reference' => [
                "Text\n\n{id=\"$decomposed\"}\n---yaml\na: 1\n---\n\nSee </#$composed>.\n",
                ['frontmatter-crossref'],
            ],
            'composed id, decomposed reference' => [
                "Text\n\n{id=\"$composed\"}\n---yaml\na: 1\n---\n\nSee </#$decomposed>.\n",
                ['frontmatter-crossref'],
            ],
            'a heading carries the id too' => ["Text\n\n{#same}\n---yaml\na: 1\n---\n\n{#same}\n# H\n\nSee </#same>.\n", []],
            'a heading carries it first' => ["{#same}\n# H\n\n{#same}\n---yaml\na: 1\n---\n\nSee </#same>.\n", []],
        ];
        $rows = [];
        foreach (['hand-written', 'data'] as $mode) {
            foreach ($cases as $name => [$source, $codes]) {
                $rows[$mode . ': ' . $name] = [$mode, $source, $codes];
            }
        }

        return $rows;
    }

    /**
     * @param string $mode
     * @param string $source
     * @param array<string> $expected
     */
    #[DataProvider('cases')]
    public function testCrossrefCodes(string $mode, string $source, array $expected): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv($mode === 'data' ? 'CARVE_LINT_RULES=data' : 'CARVE_LINT_RULES');
        try {
            $codes = [];
            foreach (Carve::lint($source) as $finding) {
                if ($finding->code === 'frontmatter-crossref' || $finding->code === 'broken-crossref') {
                    $codes[] = $finding->code;
                }
            }
            self::assertSame($expected, $codes);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
