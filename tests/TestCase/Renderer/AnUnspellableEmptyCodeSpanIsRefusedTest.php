<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An empty code span is an open backtick run, which ends only at the end of a
 * block or at a braced closer. Anywhere else the writer's output reads back as
 * a different tree, so the writer refuses it (markup-carve/carve-php#2044).
 *
 * The trees are built by parsing a `Q` placeholder span and emptying it, since
 * no Carve source builds them.
 */
class AnUnspellableEmptyCodeSpanIsRefusedTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function unspellableProvider(): array
    {
        return [
            'text after it in a strike' => ["~`Q` y~\n"],
            'text after it in a paragraph' => ["x`Q`y\n"],
            'attributes at the end of a strike' => ["~x `Q`{.c}~\n"],
            'attributes at the end of a paragraph' => ["x `Q`{.c}\n"],
            'a soft break after it' => ["x `Q`\ny\n"],
            'the end of a link label' => ["[x `Q`](u)\n"],
            'a braced closer inside a link label' => ["[{~x `Q`~}](u)\n"],
            'the end of a middle table cell' => ["| a | x `Q` | c |\n"],
            'a braced closer inside a middle table cell' => ["| a | {~x `Q`~} | c |\n"],
        ];
    }

    #[DataProvider('unspellableProvider')]
    public function testTheWriterRefusesTheTree(string $template): void
    {
        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($this->emptied($template));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function spellableProvider(): array
    {
        return [
            'the end of a paragraph' => ["x `Q`\n"],
            'the end of a strike' => ["~x `Q`~\n"],
            'a strike with text after it' => ["{~x `Q`~}y\n"],
            'the end of a superscript' => ["{^x `Q`^}\n"],
            'the end of an insertion' => ["{+x `Q`+}\n"],
            'a braced strike inside a strong' => ["*~x `Q`~ y*\n"],
            'the end of the last table cell' => ["| a | x `Q` |\n"],
            'a braced strike with text after it in the last table cell' => ["| a | {~x `Q`~}y |\n"],
            'the end of a heading' => ["# x `Q`\n"],
        ];
    }

    #[DataProvider('spellableProvider')]
    public function testTheWrittenFormReadsBackAsTheTree(string $template): void
    {
        $document = $this->emptied($template);
        $expected = $this->ast($document);

        $this->assertSame($expected, $this->ast(CarveConverter::create()->parse((new CarveRenderer())->render($document))));
    }

    /**
     * No source builds a refused tree, so `carve fmt` never throws. Each row
     * puts an empty span where a tree above is refused.
     *
     * @return array<string, array{0: string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'text after it in a strike' => ["~`` y~\n"],
            'text after it in a paragraph' => ["x``y\n"],
            'attributes after it' => ["x ``{.c}\n"],
            'a soft break after it' => ["x ``\ny\n"],
            'a link label' => ["[x ``](u)\n"],
            'a braced closer inside a link label' => ["[{~x ``~}](u)\n"],
            'a middle table cell' => ["| a | x `` | c |\n"],
            'a braced strike' => ["{~x ``~}y\n"],
            'the end of a paragraph' => ["x ``\n"],
            'the end of the last table cell' => ["| a | x `` |\n"],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testFormattingASourceKeepsItsTree(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($this->ast(CarveConverter::create()->parse($source)), $this->ast(CarveConverter::create()->parse($formatted)));
    }

    #[DataProvider('sourceProvider')]
    public function testFormattingASourceIsIdempotent(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    protected function emptied(string $template): Document
    {
        $document = CarveConverter::create()->parse($template);
        $found = 0;
        $walk = function (Node $node) use (&$walk, &$found): void {
            if ($node instanceof Code && $node->getContent() === 'Q') {
                $node->setContent('');
                $found++;
            }
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk($document);
        $this->assertSame(1, $found, 'the template must hold one placeholder span');

        return $document;
    }

    protected function ast(Document $document): string
    {
        $ast = (new AstCodec())->encode($document);
        unset($ast['srcByteLength']);

        return json_encode($ast, JSON_THROW_ON_ERROR);
    }
}
