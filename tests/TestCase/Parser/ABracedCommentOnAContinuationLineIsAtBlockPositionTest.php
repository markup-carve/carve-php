<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `{%` that starts a paragraph's continuation line is at block position (CARVE-P2-035, carve#5189).
 * Expected output is the spec model's at carve main ce3f1307.
 */
final class ABracedCommentOnAContinuationLineIsAtBlockPositionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'root, continuation at the brace column' => [
            "pre\n{% a\nb %}\n",
            "<p>pre\n{% a\nb %}</p>",
        ];

        yield 'root, continuation past the brace' => [
            "pre\n{% a\n  b %}\n",
            "<p>pre\n</p>",
        ];

        yield 'indented root paragraph' => [
            "  pre\n  {% a\n  b %}\n",
            "<p>pre\n{% a\nb %}</p>",
        ];

        yield 'a following line joins the paragraph' => [
            "pre\n{% a\nb %} tail\n",
            "<p>pre\n{% a\nb %} tail</p>",
        ];

        yield 'list item, continuation at the brace column' => [
            "- pre\n  {% a\n  b %}\n",
            "<ul>\n  <li>pre\n{% a\nb %}</li>\n</ul>",
        ];

        yield 'list item, continuation past the brace' => [
            "- pre\n  {% a\n    b %}\n",
            "<ul>\n  <li>pre\n</li>\n</ul>",
        ];

        yield 'quote, continuation at the brace column' => [
            "> pre\n> {% a\n> b %}\n",
            "<blockquote><p>pre\n{% a\nb %}</p></blockquote>",
        ];

        yield 'a run opened mid-line stays inline' => [
            "pre {% a\nb %} c\n",
            '<p>pre  c</p>',
        ];
    }

    #[DataProvider('shapes')]
    public function testTheRunFollowsTheBlockColumnRule(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function erased(): iterable
    {
        yield 'root' => ["pre\n{% a\n  b %}\n"];
        yield 'list item' => ["- pre\n  {% a\n    b %}\n"];
        yield 'quote' => ["> pre\n> {% a\n>   b %}\n"];
        yield 'after a hard break' => ["pre\\\n{% a\n  b %}\n"];
    }

    #[DataProvider('erased')]
    public function testTheFormatterKeepsAnErasedRunErased(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);

        self::assertSame(trim((new CarveConverter())->convert($source)), trim((new CarveConverter())->convert($formatted)));
    }
}
