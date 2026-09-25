<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\CitationDefinition;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

class DefinitionNodesTest extends TestCase
{
    public function testAbbreviationDefinitionsKeepTheirPositionsAndShadowedEntries(): void
    {
        $source = "*[API]: first\n\nBetween\n\n*[API]: second\n";
        $document = (new CarveConverter())->parse($source);
        $wire = (new ProseMirrorRenderer())->render($document);
        $this->assertIsArray($wire['content']);
        $content = $wire['content'];
        $this->assertIsArray($content[0]);
        $this->assertIsArray($content[2]);

        $this->assertSame(
            ['carveAbbreviationDefinition', 'paragraph', 'carveAbbreviationDefinition'],
            array_column($content, 'type'),
        );
        $this->assertSame(['abbr' => 'API', 'expansion' => 'first'], $content[0]['attrs']);
        $this->assertSame(['abbr' => 'API', 'expansion' => 'second'], $content[2]['attrs']);

        $back = (new ProseMirrorToCarve())->convert($wire);
        $this->assertSame(
            CarveConverter::carve()->render($document),
            CarveConverter::carve()->render($back),
        );
        $this->assertCount(3, $back->getChildren());
    }

    public function testCitationDefinitionCarriesKeyAttributesAndInlineEntry(): void
    {
        $document = new Document();
        $definition = new CitationDefinition('smith24');
        $definition->setAttribute('author', 'Smith');
        $definition->setAttribute('year', '2024');
        $definition->appendChild(new Text('Paper '));
        $strong = new Strong();
        $strong->appendChild(new Text('title'));
        $definition->appendChild($strong);
        $document->appendChild($definition);

        $wire = (new ProseMirrorRenderer())->render($document);
        $this->assertIsArray($wire['content']);
        $this->assertIsArray($wire['content'][0]);
        $definitionWire = $wire['content'][0];
        $this->assertIsArray($definitionWire['attrs']);
        $this->assertSame('carveCitationDefinition', $definitionWire['type']);
        $this->assertSame('smith24', $definitionWire['attrs']['key']);
        $this->assertSame(
            ['author' => 'Smith', 'year' => '2024'],
            $definitionWire['attrs']['carveKeyValues'],
        );
        $this->assertSame(
            [
                ['type' => 'text', 'text' => 'Paper '],
                ['type' => 'text', 'text' => 'title', 'marks' => [['type' => 'bold']]],
            ],
            $definitionWire['content'],
        );

        $back = (new ProseMirrorToCarve())->convert($wire);
        $this->assertSame(
            CarveConverter::carve()->render($document),
            CarveConverter::carve()->render($back),
        );
    }

    public function testLegacyDocAttributesStillRestoreAbbreviationDefinitions(): void
    {
        $wire = [
            'type' => 'doc',
            'attrs' => [
                'carveAbbreviations' => ['API' => 'expansion'],
                'carveAbbreviationDefinitions' => [['abbr' => 'API', 'expansion' => 'expansion']],
                'carveAbbreviationsBeforeBody' => true,
            ],
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body']]]],
        ];

        $back = (new ProseMirrorToCarve())->convert($wire);
        $this->assertInstanceOf(AbbreviationDefinition::class, $back->getChildren()[0]);
        $this->assertCount(2, $back->getChildren());
    }

    public function testEditedDefinitionNodeOverridesStaleDocAttributes(): void
    {
        $wire = [
            'type' => 'doc',
            'attrs' => [
                'carveAbbreviations' => ['API' => 'old'],
                'carveAbbreviationDefinitions' => [['abbr' => 'API', 'expansion' => 'old']],
            ],
            'content' => [
                ['type' => 'carveAbbreviationDefinition', 'attrs' => ['abbr' => 'API', 'expansion' => 'new']],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'API']]],
            ],
        ];

        $back = (new ProseMirrorToCarve())->convert($wire);
        $this->assertSame(['API' => 'new'], $back->getAbbreviations());
        $this->assertSame([['abbr' => 'API', 'expansion' => 'new']], $back->getAbbreviationDefinitions());
        $this->assertCount(2, $back->getChildren());
    }

    public function testDeletingAllDefinitionNodesDoesNotRestoreLegacyAttrs(): void
    {
        $document = (new CarveConverter())->parse("*[API]: old\n\nBody\n");
        $wire = (new ProseMirrorRenderer())->render($document);
        $this->assertArrayNotHasKey('attrs', $wire);
        $this->assertIsArray($wire['content']);
        unset($wire['content'][0]);
        $wire['content'] = array_values($wire['content']);

        $back = (new ProseMirrorToCarve())->convert($wire);
        $this->assertSame("Body\n", CarveConverter::carve()->render($back));
        $this->assertSame([], $back->getAbbreviations());
    }

    public function testRemovingAnAuthoredDefinitionFromAstDoesNotRestoreIt(): void
    {
        $document = (new CarveConverter())->parse("*[API]: old\n\nBody\n");
        $document->removeChild($document->getChildren()[0]);

        $wire = (new ProseMirrorRenderer())->render($document);
        $this->assertIsArray($wire['content']);
        $this->assertSame(['paragraph'], array_column($wire['content'], 'type'));

        $back = (new ProseMirrorToCarve())->convert($wire);
        $this->assertSame("Body\n", CarveConverter::carve()->render($back));
    }

    public function testIncompleteDefinitionNodeDoesNotRestoreLegacyAttrs(): void
    {
        $wire = [
            'type' => 'doc',
            'attrs' => ['carveAbbreviations' => ['API' => 'old']],
            'content' => [['type' => 'carveAbbreviationDefinition', 'attrs' => ['abbr' => 'API']]],
        ];
        $back = (new ProseMirrorToCarve())->convert($wire);

        $this->assertSame(['API' => ''], $back->getAbbreviations());
        $this->assertCount(1, $back->getChildren());
    }

    public function testFirstDefinitionSetsBeforeBodyFlag(): void
    {
        $source = "*[A]: first\n\nBody\n\n*[B]: second\n";
        $document = (new CarveConverter())->parse($source);
        $wire = (new ProseMirrorRenderer())->render($document);
        $back = (new ProseMirrorToCarve())->convert($wire);

        $this->assertSame($document->hasAbbreviationsBeforeBody(), $back->hasAbbreviationsBeforeBody());
        $this->assertSame(
            CarveConverter::carve()->render($document),
            CarveConverter::carve()->render($back),
        );
    }

    public function testMapOnlyDefinitionsAreRenderedAsNodes(): void
    {
        $document = new Document();
        $document->setAbbreviations(['API' => 'expansion']);
        $wire = (new ProseMirrorRenderer())->render($document);
        $this->assertIsArray($wire['content']);

        $this->assertSame(
            ['type' => 'carveAbbreviationDefinition', 'attrs' => ['abbr' => 'API', 'expansion' => 'expansion']],
            $wire['content'][0],
        );
        $this->assertArrayNotHasKey('attrs', $wire);
    }
}
