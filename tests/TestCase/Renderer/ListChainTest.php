<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Extension\StaticRenderExtensionInterface;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\RenderMode;
use MarkupCarve\Carve\SafeMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ListChainTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function bodies(): array
    {
        $bodies = [
            'paragraph' => "text\n",
            'soft break' => "a\nb\n",
            'hard break' => "a\\\nb\n",
            'several paragraphs' => "a\n\nb\n",
            'code' => "```\n raw\ntext\n```\n",
            'raw pre' => "```=html\n<pre>\n raw\n</pre>\n```\n",
            'unclosed pre' => "```=html\n<pre>\n raw\n```\n",
            'pre prefix' => "```=html\n<prefix-el>\n raw\n```\n",
            'raw lines' => "```=html\n<p>x</p>\nplain\n```\n",
            'inline raw' => "a `<pre>`{=html}\nb\n",
            'dropped raw' => "```=other\nraw\n```\n",
            'empty raw' => "```=html\n```\n",
            'comment' => "%% comment\n",
        ];
        $rows = [];
        foreach ([2, 32, 192] as $depth) {
            foreach ($bodies as $name => $body) {
                $rows[$name . '/' . $depth] = [$depth, $body];
            }
        }

        return $rows;
    }

    #[DataProvider('bodies')]
    public function testChainMatchesRecursiveOutputWithMixedMarkersAndAttributes(int $depth, string $body): void
    {
        $doc = (new CarveConverter())->parse($body);
        $nodes = $doc->getChildren();
        for ($index = 0; $index < $depth; $index++) {
            $list = new ListBlock(
                $index % 2 === 0 ? ListBlock::TYPE_BULLET : ListBlock::TYPE_ORDERED,
                start: 3,
                tight: $index % 3 !== 0,
                marker: $index % 2 === 0 ? '*' : ')',
                style: $index % 2 === 0 ? null : 'a',
            );
            $item = new ListItem();
            $item->setChildren($nodes);
            $list->appendChild($item);
            if ($index === 0 || $index === intdiv($depth, 2)) {
                $list->setAttribute('title', "one\ntwo & ü");
                $item->setAttribute('title', "three\nfour & ü");
            }
            $nodes = [$list];
        }
        $doc->setChildren($nodes);
        foreach (['round-trip', 'safe', 'xhtml', 'static'] as $mode) {
            $reference = new class ($mode === 'xhtml') extends HtmlRenderer {
            };
            $candidate = new HtmlRenderer($mode === 'xhtml');
            foreach ([$reference, $candidate] as $renderer) {
                $renderer->setRoundTripMode($mode === 'round-trip');
                $renderer->setSafeMode($mode === 'safe' ? new SafeMode() : null);
                $renderer->setRenderMode($mode === 'static' ? RenderMode::STATIC : RenderMode::INTERACTIVE);
            }
            self::assertSame($reference->render(clone $doc), $candidate->render(clone $doc), $mode);
        }
    }

    public function testChainInsidePlannedContainersMatchesRecursiveOutput(): void
    {
        foreach (["text\n", "```=html\n<pre>\n raw\n</pre>\n```\n", "```=html\n<pre>\n raw\n```\n"] as $body) {
            $doc = (new CarveConverter())->parse($body);
            $nodes = $doc->getChildren();
            for ($depth = 0; $depth < 32; $depth++) {
                $item = new ListItem();
                $item->setChildren($nodes);
                $list = new ListBlock();
                $list->appendChild($item);
                $nodes = [$list];
            }
            foreach ([new Div(), new BlockQuote(), new ListItem()] as $wrapper) {
                $wrapper->setChildren($nodes);
                if ($wrapper instanceof ListItem) {
                    $wrapper->setChildren(array_merge((new CarveConverter())->parse("lead\n")->getChildren(), $nodes));
                    $outer = new ListBlock();
                    $outer->appendChild($wrapper);
                    $doc->setChildren([$outer]);
                } else {
                    $doc->setChildren([$wrapper]);
                }
                $reference = new class extends HtmlRenderer {
                };
                self::assertSame($reference->render(clone $doc), (new HtmlRenderer())->render(clone $doc));
            }
        }
    }

    public function testListListenersObserveEveryList(): void
    {
        $doc = (new CarveConverter())->parse(str_repeat('- ', 128) . "text\n");
        foreach (['render.list', 'render.*'] as $event) {
            $calls = 0;
            $renderer = new HtmlRenderer();
            $renderer->on($event, static function (RenderEvent $event) use (&$calls): void {
                if ($event->getNode() instanceof ListBlock) {
                    $calls++;
                }
            });
            $renderer->render($doc);
            self::assertSame(128, $calls);
        }
    }

    public function testTaskWrapperKeepsItsCheckbox(): void
    {
        $doc = (new CarveConverter())->parse('- [x] ' . str_repeat('- ', 32) . "text\n");
        $reference = new class extends HtmlRenderer {
        };
        $html = (new HtmlRenderer())->render($doc);
        self::assertSame($reference->render(clone $doc), $html);
        self::assertStringContainsString('<input type="checkbox" checked disabled', $html);
    }

    public function testOverDepthListsRefuseAndRestoreTheRenderer(): void
    {
        $node = new ListBlock();
        for ($depth = 0; $depth < 1000; $depth++) {
            $item = new ListItem();
            $item->appendChild($node);
            $parent = new ListBlock();
            $parent->appendChild($item);
            $node = $parent;
        }
        $renderer = new HtmlRenderer();
        try {
            $renderer->renderNodeFragment($node);
            self::fail('Expected a render depth refusal');
        } catch (RenderDepthExceededException) {
            self::assertSame(0, (new ReflectionProperty($renderer, 'renderDepth'))->getValue($renderer));
            self::assertSame("<ul>\n</ul>\n", $renderer->renderNodeFragment(new ListBlock()));
        }
    }

    public function testStaticExtensionsObserveEveryList(): void
    {
        $extension = new class implements StaticRenderExtensionInterface {
            public int $calls = 0;

            public function register(CarveConverter $converter): void
            {
            }

            public function renderStaticHtml(RenderEvent $event, HtmlRenderer $renderer): bool
            {
                if ($event->getNode() instanceof ListBlock) {
                    $this->calls++;
                }

                return false;
            }
        };
        $renderer = new HtmlRenderer();
        $renderer->setRenderMode(RenderMode::STATIC);
        $renderer->addStaticRenderExtension($extension);
        $doc = (new CarveConverter())->parse(str_repeat('- ', 128) . "text\n");
        $reference = new class extends HtmlRenderer {
        };
        self::assertSame($reference->render(clone $doc), $renderer->render($doc));
        self::assertSame(128, $extension->calls);
    }

    public function testAuthoredTaskStateSurvivesAWrapper(): void
    {
        $doc = (new CarveConverter())->parse('- [?] ' . str_repeat('- ', 32) . "text\n");
        $reference = new class extends HtmlRenderer {
        };
        $html = (new HtmlRenderer())->render($doc);
        self::assertSame($reference->render(clone $doc), $html);
        self::assertStringContainsString('data-task-state="?"', $html);
    }

    public function testExactDepthBoundaryAndRecovery(): void
    {
        $node = new ListBlock();
        $leaf = new ListItem();
        $leaf->setChildren((new CarveConverter())->parse("text\n")->getChildren());
        $node->appendChild($leaf);
        for ($depth = 1; $depth < HtmlRenderer::MAX_RENDER_DEPTH; $depth++) {
            $item = new ListItem();
            $item->appendChild($node);
            $parent = new ListBlock();
            $parent->appendChild($item);
            $node = $parent;
        }
        $renderer = new HtmlRenderer();
        try {
            $renderer->renderNodeFragment($node);
            self::fail('Expected refusal at the depth limit');
        } catch (RenderDepthExceededException) {
            self::assertSame(0, (new ReflectionProperty($renderer, 'renderDepth'))->getValue($renderer));
        }
        $child = $node->getChildren()[0]->getChildren()[0];
        $reference = new class extends HtmlRenderer {
        };
        self::assertSame($reference->renderNodeFragment($child), $renderer->renderNodeFragment($child));
    }

    public function testBodyDepthRefusalRestoresSkippedListDepth(): void
    {
        $nodes = (new CarveConverter())->parse("text\n")->getChildren();
        for ($depth = 0; $depth < 300; $depth++) {
            $quote = new BlockQuote();
            $quote->setChildren($nodes);
            $nodes = [$quote];
        }
        for ($depth = 0; $depth < 300; $depth++) {
            $item = new ListItem();
            $item->setChildren($nodes);
            $list = new ListBlock();
            $list->appendChild($item);
            $nodes = [$list];
        }
        $renderer = new HtmlRenderer();
        try {
            $renderer->renderNodeFragment($nodes[0]);
            self::fail('Expected a refusal from the chain body');
        } catch (RenderDepthExceededException) {
            self::assertSame(0, (new ReflectionProperty($renderer, 'renderDepth'))->getValue($renderer));
        }
    }
}
