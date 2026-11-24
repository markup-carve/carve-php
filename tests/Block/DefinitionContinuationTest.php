<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6507, C2-B-083 and C2-B-084: link and abbreviation definitions continue on indented lines.
 */
final class DefinitionContinuationTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'only the line directly below is a tail line' => [
                "[a]: /u\n  \"T\"\n  {.c}\n\n[a]\n",
                "<p>{.c}</p>\n<p><a href=\"/u\" title=\"T\">a</a></p>",
            ],
            'a block glued to the title is no tail' => [
                "[a]: /u\n  \"T\"{.c}\n\n[a]\n",
                "<p>\u{201C}T\u{201D}{.c}</p>\n<p><a href=\"/u\">a</a></p>",
            ],
            'the attribute line and the tail block merge in order' => [
                "{.a k=1}\n[a]: /u\n  {.b k=2}\n\n[a]\n",
                '<p><a href="/u" class="a b" k="2">a</a></p>',
            ],
            'a blank line ends an expansion' => [
                "*[X]: a\n  b\n\n  c\n\nX\n",
                "<p>c</p>\n<p><abbr title=\"a b\">X</abbr></p>",
            ],
            'an expansion takes every indented line' => [
                "*[X]: a\n  - b\n  > c\n\nX\n",
                '<p><abbr title="a - b &gt; c">X</abbr></p>',
            ],
            'a comment-only line closes no definition (C2-B-137)' => [
                "*[X]: a\n  %% note\n  b\n\nX\n",
                '<p><abbr title="a b">X</abbr></p>',
            ],
            'a tail line reads across a comment-only line (C2-B-137)' => [
                "[a]: /u\n%% c\n  \"T\"\n\n[a]\n",
                '<p><a href="/u" title="T">a</a></p>',
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $html): void
    {
        $this->assertSame($html, Carve::toHtml($source));
    }

    public function testTheDefinitionEndsWithItsLastLine(): void
    {
        $def = Carve::parse("*[X]: a\n  b\n\nX\n")->children[0] ?? null;

        $this->assertNotNull($def);
        $this->assertSame([0, 11], [$def->start, $def->end]);
    }
}
