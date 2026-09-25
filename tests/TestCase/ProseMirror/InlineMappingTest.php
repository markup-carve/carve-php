<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use Closure;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\SmallCaps;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mapped inline wrappers keep their content and marks. An unmapped empty atom
 * remains a reported drop.
 */
class InlineMappingTest extends TestCase
{
    /**
     * @return array<string, array{0: \Closure, 1: string}>
     */
    public static function mappedProvider(): array
    {
        return [
            'small caps' => [
                static function (): InlineNode {
                    $node = new SmallCaps();
                    $node->appendChild(new Text('sc'));

                    return $node;
                },
                'before sc after',
            ],
            'small caps around an emphasis' => [
                static function (): InlineNode {
                    $emphasis = new Emphasis();
                    $emphasis->appendChild(new Text('sc'));
                    $node = new SmallCaps();
                    $node->appendChild($emphasis);

                    return $node;
                },
                'before sc after',
            ],
        ];
    }

    #[DataProvider('mappedProvider')]
    public function testTheMappedWrapperKeepsItsWords(Closure $make, string $expected): void
    {
        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render(self::documentAround($make()));

        $this->assertSame($expected, self::textOf($pm));
    }

    #[DataProvider('mappedProvider')]
    public function testTheMappedTypeIsNotReportedLost(Closure $make, string $expected): void
    {
        $renderer = new ProseMirrorRenderer();
        $renderer->render(self::documentAround($make()));

        $this->assertSame([], $renderer->degradedTypes());
        $this->assertSame([], $renderer->droppedTypes());
    }

    public function testAnUnmappedInlineWithNoContentStaysAReportedDrop(): void
    {
        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render(self::documentAround(new CaptionNumber()));

        $this->assertSame('before  after', self::textOf($pm));
        $this->assertArrayHasKey('caption_number', $renderer->droppedTypes());
        $this->assertSame([], $renderer->degradedTypes());
    }

    public function testAMappedInlineReportsNothing(): void
    {
        $emphasis = new Emphasis();
        $emphasis->appendChild(new Text('em'));

        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render(self::documentAround($emphasis));

        $this->assertSame('before em after', self::textOf($pm));
        $this->assertSame([], $renderer->degradedTypes());
        $this->assertSame([], $renderer->droppedTypes());
    }

    /**
     * The enclosing emphasis and small caps both reach the text.
     */
    public function testTheEnclosingMarksReachTheText(): void
    {
        $smallCaps = new SmallCaps();
        $smallCaps->appendChild(new Text('sc'));
        $emphasis = new Emphasis();
        $emphasis->appendChild($smallCaps);

        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render(self::documentAround($emphasis));

        /** @var array<int, array<string, mixed>> $content */
        $content = $pm['content'][0]['content'];
        $marked = array_values(array_filter(
            $content,
            static fn (array $node): bool => ($node['text'] ?? null) === 'sc',
        ));
        $this->assertCount(1, $marked);
        $this->assertSame([['type' => 'italic'], ['type' => 'carveSmallCaps']], $marked[0]['marks'] ?? null);
        $this->assertSame([], $renderer->degradedTypes());
    }

    private static function documentAround(InlineNode $node): Document
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('before '));
        $paragraph->appendChild($node);
        $paragraph->appendChild(new Text(' after'));
        $document = new Document();
        $document->appendChild($paragraph);

        return $document;
    }

    /**
     * @param array<string, mixed> $pm
     */
    private static function textOf(array $pm): string
    {
        /** @var array<int, array<string, mixed>> $content */
        $content = $pm['content'][0]['content'] ?? [];

        return implode('', array_map(
            static fn (array $node): string => (string)($node['text'] ?? ''),
            $content,
        ));
    }
}
