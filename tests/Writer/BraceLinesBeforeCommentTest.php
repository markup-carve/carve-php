<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-I-028, C2-W-125 case one (carve#6731): stacked brace lines before a line comment escape only the first brace.
 */
final class BraceLinesBeforeCommentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function forms(): array
    {
        return [
            'two lines' => ["\\{a}\n{b}\n%% c\n", "\\{a}\n{b}\n%% c\n"],
            'three escaped lines' => ["\\{a}\n\\{b}\n\\{c}\n%% c\n", "\\{a}\n{b}\n{c}\n%% c\n"],
            'bare comment' => ["\\{a}\n{b}\n%%\n", "\\{a}\n{b}\n%%\n"],
            'in a quote' => ["> \\{a}\n> {b}\n> %% c\n", "> \\{a}\n> {b}\n> %% c\n"],
            'in a list item' => ["- \\{a}\n  {b}\n  %% c\n", "- \\{a}\n  {b}\n  %% c\n"],
            'two comments' => ["\\{a}\n{b}\n%% c\n%% d\n", "\\{a}\n{b}\n%% c\n%% d\n"],
            'a block behind keeps both' => ["\\{a}\n{b}\nx\n", "\\{a}\n\\{b}\nx\n"],
            'text behind the comment keeps both' => ["\\{a}\n{b}\n%% c\nx\n", "\\{a}\n\\{b}\n%% c\nx\n"],
            'text first' => ["y\n{a}\n%% c\n", "y\n{a}\n%% c\n"],
            'behind a case-eight paragraph' => ["^[\\] [\\^](u)\n\n\\{a}\n{b}\n%% c\n", "^[\\] [^](u)\n\n\\{a}\n{b}\n%% c\n"],
            'one line behind a case-eight paragraph' => ["^[\\] [\\^](u)\n\n\\{a}\n%% c\n", "^[\\] [^](u)\n\n\\{a}\n%% c\n"],
            'six case-eight paragraphs between' => [
                str_repeat("^[\\] t [\\^](u)\n\n\\{a}\n%% c\n\n", 6),
                rtrim(str_repeat("^[\\] t [^](u)\n\n\\{a}\n%% c\n\n", 6)) . "\n",
            ],
            'attribute line above text behind a case-eight paragraph' => [
                "^[\\] [\\^](u)\n\n\\{a}\n{b} y\n",
                "^[\\] [^](u)\n\n\\{a}\n\\{b} y\n",
            ],
        ];
    }

    #[DataProvider('forms')]
    public function testForms(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame([], $result->diagnostics);
        self::assertSame($expected, $result->source);
        self::assertSame($expected, Carve::format($expected));
        self::assertTrue(TreeEquality::equal(json_decode(Carve::toJson($source), true), json_decode(Carve::toJson($expected), true)));
    }
}
