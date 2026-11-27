<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#6905: lint findings of spec 0.2 examples that differed across the engines.
 */
final class LintParity6905Test extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, mixed>, 3: array<array<int|string>>}>
     */
    public static function examples(): array
    {
        return [
            // C2-N-054: the child resolves [up][] against the parent, which makes the parent's [up] used.
            'includes-19' => [
                "Parent [p][ref].\n\n[ref]: https://parent.example\n[up]: https://up.example\n\n{{ child.crv }}",
                ['child.crv' => "Child [c][ref] and [up][].\n\n[ref]: https://child.example\n"],
                [],
                [],
            ],
            // C2-N-055: footnotes resolve the same way.
            'includes-20' => [
                "See [^n].\n\n{{ c.crv }}\n\n[^p]: Parent note.\n[^n]: Parent n.",
                ['c.crv' => "Child sees [^p] and [^n].\n\n[^n]: Child note.\n"],
                [],
                [],
            ],
            // C2-N-020: a region selection keeps the file's definitions.
            'includes-40' => [
                '{{ a.crv @region:k }}',
                ['a.crv' => "%% #region k\nSee [s][] and HTML.\n%% #endregion k\n\n[s]: /u\n*[HTML]: Hyper\n"],
                [],
                [],
            ],
            // C2-I-076, C2-I-077: the bracket is link text, not a reference label.
            'syntax-inline-18' => ['[a %% b](/u) c', [], [], []],
            'syntax-inline-128' => ['a ^[see b %% check *this*] c', [], [], []],
            // C2-B-122: the further words start at the title, behind the tab.
            'syntax-blocks-263' => ["```js\t\"T\"\nx\n```", [], [], [['fence-title-syntax', '', 6, 9]]],
            // C2-W-156: `^ two` was a paragraph in 0.1 already, so its reading did not change.
            'syntax-blocks-168' => ["| a |\n^ one\n^ two", [], ['upgrade' => true], [['unattached-caption', '', 12, 17]]],
        ];
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     * @param array<string, mixed> $options
     * @param array<array{0: string, 1: string, 2: int, 3: int}> $expected
     *
     * @return void
     */
    #[DataProvider('examples')]
    public function testExampleFindings(string $source, array $files, array $options, array $expected): void
    {
        self::assertSame($expected, $this->findings($source, $files, $options));
    }

    public function testChildReferenceNoFileDefinesStaysUnresolved(): void
    {
        $found = $this->findings("[up]: /u\n\n{{ c.crv }}\n", ['c.crv' => "See [zz][] and [up][].\n"]);

        self::assertSame([['unresolved-reference-link', 'c.crv', 4, 10]], $found);
    }

    public function testParentDefinitionNoFileUsesStaysUnused(): void
    {
        $found = $this->findings("[up]: /u\n\n{{ c.crv }}\n", ['c.crv' => "Plain text.\n"]);

        self::assertSame([['unused-link-reference-definition', '', 0, 8]], $found);
    }

    public function testRegionReferenceTheFileDoesNotDefineStaysUnresolved(): void
    {
        $found = $this->findings('{{ a.crv @region:k }}', ['a.crv' => "%% #region k\nSee [t][].\n%% #endregion k\n\n[s]: /u\n"]);

        self::assertSame([['unresolved-reference-link', 'a.crv', 17, 22]], $found);
    }

    /**
     * @return array<string, array{0: string, 1: array<array{0: string, 1: string, 2: int, 3: int}>}>
     */
    public static function labels(): array
    {
        return [
            'full reference label' => ["[x][a %% b]\n\n[x]: /u\n", [['comment-in-reference-label', '', 5, 11]]],
            'footnote reference label' => ["[^a %% b]\n\n[^a]: n\n", [['comment-in-reference-label', '', 3, 9]]],
            'shortcut text' => ["x [a %% b]\n\n[a]: /u\n", []],
            'inline link text' => ["[a %% b](/u)\n", []],
        ];
    }

    /**
     * @param string $source
     * @param array<array{0: string, 1: string, 2: int, 3: int}> $expected
     *
     * @return void
     */
    #[DataProvider('labels')]
    public function testCommentInReferenceLabelOnlyInALabel(string $source, array $expected): void
    {
        $found = array_values(array_filter(
            $this->findings($source),
            static fn (array $f): bool => $f[0] === 'comment-in-reference-label',
        ));

        self::assertSame($expected, $found);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function fenceTabs(): array
    {
        return [
            'tab glued to the run' => ["```\tfoo\nx\n```", 3, '``` foo'],
            'space then tab' => ["``` \tfoo\nx\n```", 5, '``` foo'],
            'tab after the language' => ["```js\t\"T\"\nx\n```", 6, '``` js "T"'],
        ];
    }

    #[DataProvider('fenceTabs')]
    public function testFenceTitleFindingAfterATab(string $source, int $start, string $respaced): void
    {
        $found = array_values(array_filter(Carve::lint($source), static fn (Diagnostic $d): bool => $d->code === 'fence-title-syntax'));

        self::assertCount(1, $found);
        self::assertSame($start, $found[0]->startOffset);
        self::assertStringContainsString('"' . $respaced . '"', $found[0]->message);
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     * @param array<string, mixed> $options
     *
     * @return array<array{0: string, 1: string, 2: int, 3: int}>
     */
    private function findings(string $source, array $files = [], array $options = []): array
    {
        if ($files !== []) {
            $options['includes'] = static fn (array $request): ?array => isset($files[$request['path']])
                ? ['text' => $files[$request['path']], 'id' => $request['path']]
                : null;
        }

        return array_map(
            static fn (Diagnostic $d): array => [$d->code, $d->file ?? '', $d->startOffset, $d->endOffset],
            Carve::lint($source, $options),
        );
    }
}
