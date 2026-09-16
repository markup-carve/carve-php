<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CommonMark links an empty destination; Carve reads `[t]()` as literal text
 * (markup-carve/carve#2069), so the importer writes the label, in a span when a
 * title survives - the bytes the HTML importer writes for `<a href="">`.
 */
class AnEmptyMarkdownDestinationIsNotALinkTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'an empty inline destination' => ['[x]()', 'x'],
            'an image with an empty source' => ['![y]()', 'y'],
            'an empty pointy destination' => ['[z](<>)', 'z'],
            'whitespace around an empty destination' => ['[z]( <> )', 'z'],
            'a title keeps a span' => ['[q](<> "t q")', '[q]{title="t q"}'],
            'an image title keeps a span' => ['![h](<> (paren))', '[h]{title=paren}'],
            'a title is decoded, then quoted' => ['[f](<> "a\\"b &ast;")', '[f]{title="a\\"b *"}'],
            'an image is written as its plain alt text' => ['![a *b* `c` [d](u) \\* &amp;]()', 'a b c d * &'],
            'an image alt drops a code span\'s padding' => ['![a `` `x` `` b]()', 'a \\`x\\` b'],
            'an image at the start of a line escapes its block opener' => ['![- x]()', '\\- x'],
            'an image alt drops defined reference links only' => ["![a [b][r] [r][] [r] [z]]()\n\n[r]: /u", "a b r r [z]\n\n[r]: /u"],
            'deeply nested brackets' => ['[a [b [c]]]()', 'a [b [c]]'],
            'the label keeps its formatting' => ['pre [a *b* `c`]() post', 'pre a /b/ `c` post'],
            'a full reference' => ["[w][r]\n\n[r]: <>", 'w'],
            'collapsed, shortcut and case-folded references' => ["[r][] [r] [R]\n\n[r]: <>", 'r r R'],
            'a reference title is decoded' => ["[v][s]\n\n[s]: <> \"a &amp; \\\"b\"", '[v]{title="a & \\"b"}'],
            'a reference title on the next line' => ["[v][s]\n\n[s]: <>\n'next title'", '[v]{title="next title"}'],
            'an indented title on the next line' => ["[v][s]\n\n[s]: <>\n    'code'", '[v]{title=code}'],
            'a definition before its reference leaves no blank line' => ["[r]: <>\n\n[r]", 'r'],
            'a definition after a thematic break' => ["---\n[r]: <>\n\n[r]", "---\n\nr"],
            'a block quote interrupting a paragraph opens a definition' => ["text\n> [r]: <>\n\n[r]", "text\n\n> \n\nr"],
            'a definition in a block quote' => ["[r]\n\n> [r]: <>", "r\n\n> "],
            'a label holding a character reference' => ["[x][a&amp;b]\n\n[a&amp;b]: <>", 'x'],
            'a definition after a closed HTML comment' => ["<!-- c -->\n[r]: <>\n\n[r]", "```=html\n<!-- c -->\n```\n\nr"],
            'a definition after a multi-line HTML comment' => ["<!--\nx\n-->\n[r]: <>\n\n[r]", "```=html\n<!--\nx\n-->\n```\n\nr"],
            'a definition after a quote ends its open fence' => ["> ```\n> code\n\n[r]: <>\n\n[r]", "> ```\n> code\n> ```\n\nr"],
            'a definition after a quote ends its open HTML block' => ["> <script>\n> x\n\n[r]: <>\n\n[r]", "> ```=html\n> <script>\n> x\n> ```\n\nr"],
            'a definition after a list item ends its open fence' => ["- ```\n  code\n\n[r]: <>\n\n[r]", "- ```\n  code\n\n  ```\nr"],
            'a definition opening a later list item' => ["- one\n- [r]: <>\n\n[r]", "- one\n\nr"],
            'a definition after a starred thematic break' => ["* * *\n[r]: <>\n\n[r]", "* * *\n\nr"],
            'a definition opening a list item' => ["- [r]: <>\n- two\n\n[r]", "- two\n\nr"],
            'a definition continuing a list item' => ["- a\n\n    [r]: <>\n\n[r]", "- a\n\nr"],
            'a title on the line after a list item definition' => ["- [r]: <>\n  \"t\"\n- two\n\n[r]", "- two\n\n[r]{title=t}"],
            'a later non-empty definition stays' => ["[r]\n\n[r]: <>\n[r]: /later", "r\n\n[r]: /later"],
            'an empty destination on the next line' => ["[r]\n\n[r]:\n<>", 'r'],
            'a first definition continued on the next line wins' => ["[x][d]\n\n[d]:\n  /url\n[d]: <>", "[x][d]\n\n[d]:\n  /url"],
            'the first definition wins' => ["[x][d]\n\n[d]: /url\n[d]: <>", "[x][d]\n\n[d]: /url"],
            'a destination on an inline link is not a reference' => ["[r](/u)\n\n[r]: <>", '[r](/u)'],
            'a label at the start of a line escapes its block opener' => ['[- x]()', '\\- x'],
            'a label at the start of a quote escapes its block opener' => ['> [- x]()', '> \\- x'],
            'a label mid-line is not escaped' => ['a [- x]()', 'a - x'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheLinkIsWrittenAsItsLabel(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }

    /**
     * Shapes that are NOT an empty destination keep their link.
     *
     * @return array<string, array{string, string}>
     */
    public static function kept(): array
    {
        return [
            'a quoted destination after a space' => ['[u]( "t")', '[u]("t")'],
            'spaces around a destination' => ['[k]( /u )', '[k](/u)'],
            'a definition interrupting a paragraph' => ["text\n[p]: <>", "text\n\\[p]: <>"],
            'a definition lazily continuing a quoted paragraph' => ["> text\n[p]: <>", "> text\n\\[p]: <>"],
            'a definition in a fence' => ["[c]\n\n```\n\n[c]: <>\n```", "[c]\n\n```\n\n[c]: <>\n```"],
            'a definition after definition-like paragraph text' => ["[x]\n\n[x]: a b c\n[x]: <>", "[x]\n\n[x]: a b c\n\\[x]: <>"],
            'an indented code block after a list' => ["- a\n\nb\n\n    [r]: <>\n\n[r]", "- a\n\nb\n\n```\n[r]: <>\n```\n\n[r]"],
            'a definition in an open HTML block' => ["<script>\n\n[r]: <>\n</script>\n\n[r]", "```=html\n<script>\n\n[r]: <>\n</script>\n```\n\n[r]"],
            'a code span' => ['`[d]()`', '`[d]()`'],
        ];
    }

    #[DataProvider('kept')]
    public function testANonEmptyDestinationIsKept(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }

    public function testAPointyDestinationHoldingASpaceIsNotEmpty(): void
    {
        $this->assertNotSame('m', rtrim((new MarkdownToCarve())->convert('[m](< >)'), "\n"));
    }
}
