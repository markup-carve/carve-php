<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use Closure;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Ruby;
use MarkupCarve\Carve\Node\Inline\SmallCaps;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#2324. An unmapped inline lost its whole subtree, and the loss was
 * reported only as a drop, so the words were gone with nothing standing in for
 * them. Neither type has a Carve spelling, so both trees are built by hand.
 */
class AnUnmappedInlineKeepsItsWordsTest extends TestCase
{
    /**
     * @return array<string, array{0: \Closure, 1: string, 2: string}>
     */
    public static function unmappedProvider(): array
    {
        return [
            'ruby' => [
                static function (): InlineNode {
                    return new Ruby([['base' => [new Text('a')], 'annotation' => [new Text('b')]]]);
                },
                'ruby',
                'before a(b) after',
            ],
            'small caps' => [
                static function (): InlineNode {
                    $node = new SmallCaps();
                    $node->appendChild(new Text('sc'));

                    return $node;
                },
                'small_caps',
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
                'small_caps',
                'before sc after',
            ],
        ];
    }

    #[DataProvider('unmappedProvider')]
    public function testTheChildrenStandInForTheNode(Closure $make, string $type, string $expected): void
    {
        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render(self::documentAround($make()));

        $this->assertSame($expected, self::textOf($pm));
    }

    #[DataProvider('unmappedProvider')]
    public function testTheTypeIsNamedInTheReport(Closure $make, string $type, string $expected): void
    {
        $renderer = new ProseMirrorRenderer();
        $renderer->render(self::documentAround($make()));

        $this->assertArrayHasKey($type, $renderer->degradedTypes());
        $this->assertNotSame('', $renderer->degradedTypes()[$type]);
        $this->assertSame([], $renderer->droppedTypes());
    }

    /**
     * The control. An unmapped inline with nothing inside it has no stand-in, so
     * it stays a reported DROP - and a mapped inline reports neither. Both hold
     * whichever way the unmapped-with-children branch behaves.
     */
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
     * The enclosing marks reach the stand-in, since the node they were carried
     * through is gone and its children sit in the run directly.
     */
    public function testTheEnclosingMarksReachTheStandIn(): void
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
        $this->assertSame([['type' => 'italic']], $marked[0]['marks'] ?? null);
        $this->assertArrayHasKey('small_caps', $renderer->degradedTypes());
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
