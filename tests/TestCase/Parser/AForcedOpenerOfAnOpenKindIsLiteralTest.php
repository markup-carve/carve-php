<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 section 9 E3 holds for forced spans: an opener of an open kind is
 * literal whether it is bare or forced, so no source spells a span inside a
 * span of its kind (markup-carve/carve#2078, corpus section 471).
 */
class AForcedOpenerOfAnOpenKindIsLiteralTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function ruled(): array
    {
        return [
            'a forced opener in a forced span' => ["a{*{*x*}*}b\n", "<p>a<strong>{*x</strong>*}b</p>\n"],
            'a forced opener in a bare span' => ["*a {*b*} c*\n", "<p><strong>a {*b</strong>} c*</p>\n"],
            'a bare opener in a forced span' => ["{*a *b* c*}\n", "<p><strong>a *b* c</strong></p>\n"],
            'through a span of another kind' => ["{/a *b {/c/}*/}\n", "<p><em>a *b {/c</em>*/}</p>\n"],
            'the bare span closes on the forced closer' => ["*a {*b*}\n", "<p><strong>a {*b</strong>}</p>\n"],
            'a substitution inside a strike stays one' => ["~a{~b~>c~} d~\n", "<p><s>a<del>b</del><ins>c</ins> d</s></p>\n"],
            'a braced span of another kind is a scope' => ["{*a {/b {*c*} d/} e*}\n", "<p><strong>a <em>b <strong>c</strong> d</em> e</strong></p>\n"],
            'a closer inside that scope stays in it' => ["{*a {/b *} d/} e*}\n", "<p><strong>a <em>b *} d</em> e</strong></p>\n"],
            'a bare span around the scope' => ["*a {/b *c* d/} e*\n", "<p><strong>a <em>b <strong>c</strong> d</em> e</strong></p>\n"],
            'a bare closer skips a nested scope' => ["*a {/b {=x/} y*=} d/} e*\n", "<p><strong>a <em>b <mark>x/} y*</mark> d</em> e</strong></p>\n"],
            'another kind still nests' => ["{*a {/b/} c*}\n", "<p><strong>a <em>b</em> c</strong></p>\n"],
        ];
    }

    #[DataProvider('ruled')]
    public function testTheInnerOpenerIsReadAsE3Says(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::create()->convert($source));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function nestings(): array
    {
        $text = ['type' => 'text', 'value' => 'x'];

        return [
            'emphasis in emphasis' => [['type' => 'emphasis', 'children' => [['type' => 'emphasis', 'children' => [$text]]]]],
            'strong in strong' => [['type' => 'strong', 'children' => [['type' => 'strong', 'children' => [$text]]]]],
            'strike in strike beside text' => [['type' => 'strike', 'children' => [['type' => 'text', 'value' => 'a'], ['type' => 'strike', 'children' => [$text]]]]],
        ];
    }

    /**
     * A braced span starts its own scope, so a span of an enclosing kind nests
     * again inside it and the writer braces the span between (markup-carve/carve#2091).
     */
    public function testTheWriterBracesTheSpanBetweenTwoOfOneKind(): void
    {
        $text = ['type' => 'text', 'value' => 'x'];
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'strong', 'children' => [['type' => 'text', 'value' => 'a '], ['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => 'b '], ['type' => 'strong', 'children' => [$text]], ['type' => 'text', 'value' => ' d']]], ['type' => 'text', 'value' => ' e']]],
                    ],
                ],
            ],
        ]);

        $written = (new CarveRenderer())->render($document);

        $this->assertSame("*a {/b *x* d/} e*\n", $written);
        $this->assertSame((new CarveConverter())->render($document), (new CarveConverter())->convert($written));
    }

    /**
     * The writer used to brace the inner span, which now reads back as a
     * different tree.
     *
     * @param array<string, mixed> $span
     */
    #[DataProvider('nestings')]
    public function testTheWriterRefusesASameKindNesting(array $span): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => [$span]]],
        ]);

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }
}
