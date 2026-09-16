<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text ending in `:name` before a node that opens with `[` would read back as
 * an inline extension, so the colon is escaped (markup-carve/carve#2068).
 */
class AColonNameBeforeABracketIsEscapedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function imports(): array
    {
        return [
            'before a span' => ['<p>x :name<span class="k">n</span></p>', "x \\:name[n]{.k}\n"],
            'before a link' => ['<p>x :name<a href="u">n</a></p>', "x \\:name[n](u)\n"],
            'intraword' => ['<p>x:name<span class="k">n</span></p>', "x\\:name[n]{.k}\n"],
            'a digit-first name is no extension' => ['<p>x :1<a href="u">n</a></p>', "x :1[n](u)\n"],
            'across a flattened wrapper' => ['<p><span>x :name</span><a href="u">n</a></p>', "x \\:name[n](u)\n"],
            'an escaped backslash before the colon' => ['<p>x \\:name<a href="u">n</a></p>', "x \\\\\\:name[n](u)\n"],
            'inside one text run' => ['<p>x :name[n]</p>', "x \\:name[n]\n"],
        ];
    }

    #[DataProvider('imports')]
    public function testTheImporterEscapesTheColon(string $html, string $carve): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    /**
     * @return array<string, array{array<int, array<string, mixed>>, string}>
     */
    public static function writes(): array
    {
        $link = ['type' => 'link', 'href' => 'u', 'children' => [['type' => 'text', 'value' => 'n']]];

        return [
            'before a span' => [
                [['type' => 'text', 'value' => 'x :name'], ['type' => 'span', 'attrs' => ['classes' => ['k']], 'children' => [['type' => 'text', 'value' => 'n']]]],
                "x \\:name[n]{.k}\n",
            ],
            'before a link' => [[['type' => 'text', 'value' => 'x :name'], $link], "x \\:name[n](u)\n"],
            'intraword' => [[['type' => 'text', 'value' => 'x:name'], $link], "x\\:name[n](u)\n"],
            'a digit-first name is no extension' => [[['type' => 'text', 'value' => 'x :1'], $link], "x :1[n](u)\n"],
            'a backslash before the colon' => [[['type' => 'text', 'value' => 'x \\:name'], $link], "x \\\\\\:name[n](u)\n"],
            'an escaped colon is not escaped again' => [
                [['type' => 'text', 'value' => 'x '], ['type' => 'escaped_text', 'value' => ':'], ['type' => 'text', 'value' => 'name'], $link],
                "x \\:name[n](u)\n",
            ],
            'a node that opens no bracket' => [[['type' => 'text', 'value' => 'x :name'], ['type' => 'code', 'value' => 'c']], "x :name`c`\n"],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @param string $carve
     */
    #[DataProvider('writes')]
    public function testTheWriterEscapesTheColon(array $children, string $carve): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => $children]],
        ]);

        $this->assertSame($carve, (new CarveRenderer())->render($document));
    }
}
