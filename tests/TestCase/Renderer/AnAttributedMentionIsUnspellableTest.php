<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attribute block after a mention or tag stays literal text, so no source
 * reads back as one that carries attributes (markup-carve/carve-php#2083).
 */
class AnAttributedMentionIsUnspellableTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function attributed(): array
    {
        return [
            'a mention with a class' => [['type' => 'mention', 'user' => 'name', 'attrs' => ['classes' => ['c'], 'order' => ['.class']]], 'mention'],
            'a mention with an id' => [['type' => 'mention', 'user' => 'name', 'attrs' => ['id' => 'x']], 'mention'],
            'a tag with a class' => [['type' => 'tag', 'name' => 'tag', 'attrs' => ['classes' => ['c'], 'order' => ['.class']]], 'tag'],
            'a tag with a key/value' => [['type' => 'tag', 'name' => 'tag', 'attrs' => ['keyValues' => ['data-k' => 'v']]], 'tag'],
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param string $type
     */
    #[DataProvider('attributed')]
    public function testTheWriterRefusesTheTree(array $node, string $type): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => [$node]]],
        ]);

        try {
            (new CarveRenderer())->render($document);
            $this->fail('No SourceUnspellableException was thrown');
        } catch (SourceUnspellableException $exception) {
            $this->assertSame($type, $exception->nodeType);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function linked(): array
    {
        return [
            'a mention with a destination' => ['mention', '@alice'],
            'a tag with a destination' => ['tag', '#release'],
        ];
    }

    #[DataProvider('linked')]
    public function testAMentionWithADestinationIsRefusedToo(string $class, string $label): void
    {
        $mention = new Mention($class, '/u/1', $label);
        $mention->setAttribute('id', 'x');
        $paragraph = new Paragraph();
        $paragraph->appendChild($mention);
        $document = new Document();
        $document->appendChild($paragraph);

        try {
            (new CarveRenderer())->render($document);
            $this->fail('No SourceUnspellableException was thrown');
        } catch (SourceUnspellableException $exception) {
            $this->assertSame($class, $exception->nodeType);
        }
    }
}
