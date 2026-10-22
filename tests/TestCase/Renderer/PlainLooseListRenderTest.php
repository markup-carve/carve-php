<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class PlainLooseListRenderTest extends TestCase
{
    public function testPlainLooseListEscapesText(): void
    {
        $document = self::document();

        self::assertSame(
            "<ul>\n  <li><p>one &amp; two</p></li>\n  <li><p>three</p></li>\n</ul>\n",
            (new HtmlRenderer())->render($document),
        );
    }

    public function testParagraphListenerStillHandlesPlainLooseItems(): void
    {
        $renderer = new HtmlRenderer();
        $renderer->on('render.paragraph', static function (RenderEvent $event): void {
            $event->setHtml("<p>hooked</p>\n");
        });

        $html = $renderer->render(self::document());

        self::assertSame(2, substr_count($html, '<p>hooked</p>'));
        self::assertStringNotContainsString('one &amp; two', $html);
    }

    private static function document(): Document
    {
        $document = new Document();
        $list = new ListBlock(ListBlock::TYPE_BULLET, marker: '-');
        $list->setTight(false);
        foreach (['one & two', 'three'] as $content) {
            $item = new ListItem();
            $paragraph = new Paragraph();
            $paragraph->appendChild(new Text($content));
            $item->appendChild($paragraph);
            $list->appendChild($item);
        }
        $document->appendChild($list);

        return $document;
    }
}
