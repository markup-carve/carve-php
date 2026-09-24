<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Node\Inline\SmallCaps;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §28 (CARVE-P12-050): the interchange-only small-caps span.
 */
final class SmallCapsInterchangeTest extends TestCase
{
    /**
     * @param array<string, mixed> $attrs
     *
     * @return array<string, mixed>
     */
    private static function payload(array $attrs = []): array
    {
        $node = ['type' => 'small_caps'];
        if ($attrs !== []) {
            $node['attrs'] = $attrs;
        }
        $node['children'] = [['type' => 'text', 'value' => 'Nato']];

        return [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => [$node]]],
        ];
    }

    public function testItDecodesAndRoundTrips(): void
    {
        $codec = new AstCodec();
        $document = $codec->decode(self::payload());
        $node = $document->getChildren()[0]->getChildren()[0];

        self::assertInstanceOf(SmallCaps::class, $node);
        self::assertSame(self::payload(), $codec->encode($document));
        self::assertSame(self::payload(), $codec->encode(clone $document));
    }

    public function testAttributesRoundTrip(): void
    {
        $attrs = ['id' => 'n', 'classes' => ['org'], 'order' => ['#id', '.class']];
        $codec = new AstCodec();

        self::assertSame(self::payload($attrs), $codec->encode($codec->decode(self::payload($attrs))));
    }

    public function testHtmlWrapsTheChildrenInASmallcapsSpan(): void
    {
        $document = (new AstCodec())->decode(self::payload());

        self::assertSame(
            '<p><span class="smallcaps">Nato</span></p>' . "\n",
            (new HtmlRenderer())->render($document),
        );
    }

    public function testHtmlMergesTheBaseClassIntoTheAuthoredClassSlot(): void
    {
        $document = (new AstCodec())->decode(
            self::payload(['id' => 'n', 'classes' => ['org'], 'order' => ['#id', '.class']]),
        );

        self::assertSame(
            '<p><span id="n" class="smallcaps org">Nato</span></p>' . "\n",
            (new HtmlRenderer())->render($document),
        );
    }

    public function testMarkdownUsesTheSameSpan(): void
    {
        $document = (new AstCodec())->decode(self::payload());

        self::assertSame(
            '<span class="smallcaps">Nato</span>',
            trim((new MarkdownRenderer())->render($document)),
        );
    }

    public function testPlainAndAnsiKeepTheLetterCase(): void
    {
        $document = (new AstCodec())->decode(self::payload());

        self::assertSame('Nato', trim((new PlainTextRenderer())->render($document)));
        self::assertSame('Nato', trim((new AnsiRenderer())->render($document)));
    }

    public function testCarveWritesTheChildrenWithoutTheWrapper(): void
    {
        $document = (new AstCodec())->decode(self::payload());

        self::assertSame('Nato', trim((new CarveRenderer())->render($document)));
    }

    public function testCarveKeepsAttributesOnAnAttributedSpan(): void
    {
        $document = (new AstCodec())->decode(
            self::payload(['id' => 'n', 'classes' => ['org'], 'order' => ['#id', '.class']]),
        );

        self::assertSame('[Nato]{#n .org}', trim((new CarveRenderer())->render($document)));
    }
}
