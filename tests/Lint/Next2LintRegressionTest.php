<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2LintRegressionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<array{string, int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'attribute ends before a comment at end of document' => [
                "{.c}\n%% x\n", 'unattached-block-attribute',
                [['unattached-block-attribute', 0, 4]],
            ],
            'attribute ends before a region comment' => [
                "{.c}\n%% #endregion\n", 'unattached-block-attribute',
                [['unattached-block-attribute', 0, 4]],
            ],
            'attribute reaches a paragraph after a comment' => [
                "{.c}\n%% x\npara\n", 'unattached-block-attribute', [],
            ],
            'attribute at final line feed uses end message' => [
                "{.c}\n", 'unattached-block-attribute',
                [['unattached-block-attribute', 0, 4]],
            ],
            'quoted item term below a tab is not overindented' => [
                "> i) :::\n\t>   :: term\n", 'list-item-block-overindented', [],
            ],
            'caption on a term continuation line' => [
                " :: term\n: | A | B |\n  | x | y |",
                'caption-looking-paragraph',
                [],
            ],
            'caption on a term continuation line below a tab' => [
                "\t:: t\n: ## H #\n | 1 | 2 |",
                'caption-looking-paragraph',
                [],
            ],
            'caption not on a nested term marker line' => [
                ":: term\n: :: term\n |",
                'caption-looking-paragraph',
                [],
            ],
            'attribute after paragraph text colon' => [
                ': {.c}',
                'unattached-block-attribute',
                [],
            ],
            'attribute after paragraph text colon in a quote' => [
                '> : {.c}',
                'unattached-block-attribute',
                [],
            ],
            'attribute after a lazy quote mark' => [
                "- [x] done\n> {#id}",
                'unattached-block-attribute',
                [],
            ],
            'attribute on a footnote marker line' => [
                '[^f]: {#id}',
                'unattached-block-attribute',
                [
                    ['unattached-block-attribute', 6, 11],
                ],
            ],
            'attribute at the end of a quote before a div opener' => [
                "> {.c}\n:::",
                'unattached-block-attribute',
                [
                    ['unattached-block-attribute', 2, 6],
                ],
            ],
            'alert behind a list marker glued to a quote mark' => ["> >- [!NOTE]\n", 'github-alert-marker', []],
            'alert after other text on a quote line' => ["> <a <b>[[!NOTE]\n", 'github-alert-marker', []],
            'alert range is the mark alone' => ["> >\t[!NOTE]*\n", 'github-alert-marker', [['github-alert-marker', 4, 11]]],
            'alert behind a list marker and a quote mark' => ["> - > [!TIP]\n", 'github-alert-marker', [['github-alert-marker', 6, 12]]],
            'alert behind a reference definition and a quote mark' => [
                "> [x]: > [!NOTE]\n", 'github-alert-marker',
                [['github-alert-marker', 9, 16]],
            ],
            'plus after a term mark is not a folded marker' => [":: + x\n", 'definition-term-block-folded', []],
            'folded ordered marker range' => [":: 12. a\n", 'definition-term-block-folded', [['definition-term-block-folded', 3, 6]]],
            'ten-digit number after a term mark is not a marker' => [":: 1234567890. x\n", 'definition-term-block-folded', []],
            'tag shape stops at another less-than sign' => ["a <a <b> c\n", 'bare-html-tag', [['bare-html-tag', 5, 8]]],
            'closing slash without a name is not a tag' => ["a </> b\n", 'bare-html-tag', []],
            'caption on a later quoted paragraph line below an image' => [
                "> a ![i](p)\n> : x\n", 'caption-looking-paragraph',
                [['caption-looking-paragraph', 14, 15]],
            ],
            'caption on a later quoted paragraph line above a table' => [
                "> a\n> : x\n> | a |\n", 'caption-looking-paragraph',
                [['caption-looking-paragraph', 6, 7]],
            ],
            'image mark inside a line comment above a caption' => ["%% ![i](p)\n: x\n", 'caption-looking-paragraph', []],
            'image mark inside a block comment above a caption' => [
                "%%%\n![i](p)\n%%%\n: x\n", 'caption-looking-paragraph',
                [['caption-looking-paragraph', 16, 17]],
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $rule
     * @param array<array{string, int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testRule(string $source, string $rule, array $expected): void
    {
        $actual = [];
        foreach (Carve::lint($source) as $diagnostic) {
            if ($diagnostic->code === $rule) {
                $actual[] = [$diagnostic->code, $diagnostic->startOffset, $diagnostic->endOffset];
            }
        }
        self::assertSame($expected, $actual);
    }

    public function testFinalLineFeedDoesNotSelectBlankLineAdvice(): void
    {
        foreach (["{.c}\n", "para\n\n{.c}\n", "{.c}\n%% x\n"] as $source) {
            $messages = array_values(array_map(
                static fn ($diagnostic): string => $diagnostic->message,
                array_filter(
                    Carve::lint($source),
                    static fn ($diagnostic): bool => $diagnostic->code === 'unattached-block-attribute',
                ),
            ));
            self::assertCount(1, $messages);
            self::assertStringStartsWith('This attribute line reaches no block:', $messages[0]);
        }
    }
}
