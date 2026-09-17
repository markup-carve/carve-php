<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnExplicitEmptyIdKeepsItsKeyValueSpellingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1?: string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'ordinary attribute list' => ["{id=\"\"}\nx\n"],
            'bare id' => ["{id}\nx\n", "{id=\"\"}\nx\n"],
            'non-empty keyed id' => ["{id=foo}\nx\n", "{#foo}\nx\n"],
            'bare and keyed id share one slot' => ["{id id=\"\"}\nx\n", "{id=\"\"}\nx\n"],
            'last keyed id wins' => ["{#a id=\"\"}\nx\n", "{id=\"\"}\nx\n"],
            'last shorthand id wins' => ["{id=\"\" #a}\nx\n", "{#a}\nx\n"],
            'fenced div attribute list' => ["{id=\"\"}\n::: div\nx\n:::\n"],
            'fenced div keeps only the winning id' => ["{#a id=\"\"}\n::: div\nx\n:::\n", "{id=\"\"}\n::: div\nx\n:::\n"],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testTheCanonicalWriterPreservesAnExplicitId(string $source, ?string $expected = null): void
    {
        $document = (new CarveConverter())->parse($source);
        $written = (new CarveRenderer())->render($document);

        $this->assertSame($expected ?? $source, $written);
        $this->assertSame(
            (new CarveConverter())->convert($source),
            (new CarveConverter())->convert($written),
        );
    }

    public function testThePublishedAstUsesTheCanonicalIdSlot(): void
    {
        $ast = (new AstCodec())->encode((new CarveConverter())->parse("{id=foo}\nx\n"));

        $this->assertSame(['#id'], $ast['children'][0]['attrs']['order'] ?? null);
    }

    public function testTheWriterEmitsOnlyOneIdForDuplicateWireSlots(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('x'));
        $paragraph->setAttributesWithOrder(['id' => ''], ['#id', '#id']);
        $paragraph->setAttributeOrder(['#id', '#id']);
        $document->appendChild($paragraph);

        $this->assertSame("{id=\"\"}\nx\n", (new CarveRenderer())->render($document));
    }
}
