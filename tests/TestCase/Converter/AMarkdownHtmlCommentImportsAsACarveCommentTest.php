<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The carve#4812 ruling (CARVE-P2-034): a Markdown HTML comment block imports
 * as a Carve comment, and lint reports a visible HTML comment in Carve.
 */
class AMarkdownHtmlCommentImportsAsACarveCommentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function imports(): array
    {
        return [
            'one line' => ["Before.\n\n<!-- TODO secret -->\n\nAfter.\n", "Before.\n\n%% TODO secret\n\nAfter.\n"],
            'empty body' => ["<!-- -->\n", "%%\n"],
            'across lines' => ["<!-- keep \"this\"\nand `that` -->\n\nafter\n", "%%%\nkeep \"this\"\nand `that`\n%%%\n\nafter\n"],
            'widened past a body fence' => ["<!--\nline\n%%%\nmore\n-->\n", "%%%%\nline\n%%%\nmore\n%%%%\n"],
            'widened past an indented body fence' => ["  <!--\n  line\n  %%%\n  more\n  -->\n", "%%%%\nline\n  %%%\n  more\n%%%%\n"],
            'widened past a body line opening with a fence run' => ["<!--\nsecret\n%%% tail\nvisible?\n-->\n", "%%%%\nsecret\n%%% tail\nvisible?\n%%%%\n"],
            'in a quote' => ["> a\n>\n> <!-- c -->\n", "> a\n>\n> %% c\n"],
            'in a list item' => ["- a\n\n  <!-- c -->\n", "{loose}\n- a\n\n  %% c\n"],
        ];
    }

    #[DataProvider('imports')]
    public function testTheCommentBlockImportsAsACarveComment(string $markdown, string $expected): void
    {
        self::assertSame($expected, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rawHtml(): array
    {
        return [
            'comment in a paragraph' => ["a <!-- x --> b\n", '{=html}'],
            'comment beside an element' => ["<!-- one --> <p>x</p>\n", '```=html'],
            'two comments' => ["<!-- a --><!-- b -->\n", '```=html'],
            'carve carrier' => ["<!-- carve:x -->\n", '```=html'],
        ];
    }

    #[DataProvider('rawHtml')]
    public function testOtherHtmlStaysRaw(string $markdown, string $expected): void
    {
        self::assertStringContainsString($expected, (new MarkdownToCarve())->convert($markdown));
    }

    public function testTheReportNamesTheCommentLine(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("- x\n\n  <!-- y -->\n");
        $rows = array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'markdown-html-comment'));

        self::assertCount(1, $rows);
        self::assertSame(['info', 'normalized', 'exact', 'line:3'], [$rows[0]->severity, $rows[0]->fidelity, $rows[0]->confidence, $rows[0]->path]);
        self::assertSame('<p>Before.</p>' . "\n" . '<p>After.</p>', trim(CarveConverter::create()->convert("Before.\n\n%% TODO secret\n\nAfter.\n")));
    }

    public function testSharedLintVectors(): void
    {
        $path = __DIR__ . '/../../spec/tests/lint-rule-vectors/html-comment-renders-as-text.json';
        $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['vectors'] as $vector) {
            $warnings = array_values(array_filter(
                (new DocumentLinter())->lint($vector['source']),
                static fn ($warning): bool => $warning->rule === 'html-comment-renders-as-text',
            ));
            $expected = $vector['expected']['findings'];
            self::assertSame(
                array_map(static fn (array $finding): array => [$finding['line'], $finding['column']], $expected),
                array_map(static fn ($warning): array => [$warning->line, $warning->column], $warnings),
                $vector['name'],
            );
            foreach ($expected as $index => $finding) {
                foreach ($finding['includes'] as $fragment) {
                    self::assertStringContainsString($fragment, $warnings[$index]->message, $vector['name']);
                }
            }
        }
    }

    /**
     * @return array<string, array{string, array<int, array{int, int}>}>
     */
    public static function lintShapes(): array
    {
        return [
            'a closer inside a link destination' => ["a <!-- x\n[link](foo-->) b\n", []],
            'a closer in a link destination before a line comment' => ["a <!-- x\n[link](foo-->) %% c\n", []],
            'a comment before a line comment' => ["a <!-- x --> %% note\n", [[1, 3]]],
            'a closer inside a line comment' => ["a <!-- x\nb %% -->\n", []],
            'a closer before a line comment' => ["> <!-- a\n> b --> %% c\n", [[1, 3]]],
            'two openers, one closer' => ["<!-- a <!-- b -->\n", [[1, 1], [1, 8]]],
        ];
    }

    /**
     * @param string $source
     * @param array<int, array{int, int}> $expected
     */
    #[DataProvider('lintShapes')]
    public function testOnlyAVisibleCloserCompletesTheComment(string $source, array $expected): void
    {
        $warnings = array_values(array_filter(
            (new DocumentLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'html-comment-renders-as-text',
        ));

        self::assertSame($expected, array_map(static fn ($warning): array => [$warning->line, $warning->column], $warnings));
    }

    public function testACommentInAFenceIsNotReported(): void
    {
        $rules = array_map(static fn ($warning): string => $warning->rule, (new DocumentLinter())->lint("```text\n<!-- x -->\n```\n"));

        self::assertNotContains('html-comment-renders-as-text', $rules);
    }
}
