<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Section;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class MixedContainerLayoutTest extends TestCase
{
    use ScalingGuardTrait;

    public function testMixedFramesMatchTheRecursiveRenderer(): void
    {
        $reference = new class extends HtmlRenderer {
        };
        foreach ([0, 1, 2, 3, 20, 150] as $depth) {
            foreach (['plain', 'empty', 'pre', 'unfinished-pre', 'tag', 'unfinished-tag', 'attrs', 'title'] as $kind) {
                $doc = $this->document($depth, $kind);
                self::assertSame($reference->render($doc), (new HtmlRenderer())->render($doc), "$depth $kind");
            }
        }
    }

    public function testWildcardListenersSeeEachNodeOnce(): void
    {
        $doc = $this->document(20, 'plain');
        $renderer = new HtmlRenderer();
        $seen = [];
        $renderer->on('render.*', static function ($event) use (&$seen): void {
            $seen[] = $event->getNode()->getType();
        });
        $reference = new class extends HtmlRenderer {
        };
        self::assertSame($reference->render($doc), $renderer->render($doc));
        self::assertGreaterThan(10, count($seen));
    }

    public function testFragmentsWithoutLineEndingsAndSplitRawTagsKeepTheirLayout(): void
    {
        foreach ([["<pre>\ncontents\n", '</pr', "e>\n"], ['<math>x</math>', '<p>tail</p>'], ['<a title="one', 'two">tail</a>'], ['<pre>x', "\ny\n</pre>"], ['<p>x</p>\n', "\n\n"]] as $fragments) {
            $doc = new Document();
            $div = new Div();
            $doc->appendChild($div);
            foreach ($fragments as $_) {
                $div->appendChild(new Paragraph());
            }
            $outputs = [];
            foreach (
                [
                    new class extends HtmlRenderer {
                    }, new HtmlRenderer(),
                ] as $renderer
            ) {
                $index = 0;
                $renderer->on('render.paragraph', static function (RenderEvent $event) use ($fragments, &$index): void {
                    $event->setHtml($fragments[$index++]);
                });
                $outputs[] = $renderer->render($doc);
                self::assertSame(count($fragments), $index);
            }
            self::assertSame($outputs[0], $outputs[1]);
        }
    }

    public function testNonItemListChildrenKeepTheirBlankLines(): void
    {
        $doc = new Document();
        $list = new ListBlock();
        $list->appendChild(new Comment('before'));
        $item = new ListItem();
        $p = new Paragraph();
        $p->appendChild(new Text('item'));
        $item->appendChild($p);
        $list->appendChild($item);
        $list->appendChild(new Comment('after'));
        $doc->appendChild($list);
        self::assertSame("<ul>\n\n  <li>item</li>\n\n</ul>\n", (new HtmlRenderer())->render($doc));
    }

    #[Group('scaling')]
    public function testMixedContainersScaleWithNodesAndOutputBytes(): void
    {
        foreach (['plain', 'pre'] as $kind) {
            $renderer = new HtmlRenderer();
            $small = $this->document(40, $kind, 4000);
            $large = $this->document(160, $kind, 16000);
            $smallBytes = strlen($renderer->render($small));
            $largeBytes = strlen($renderer->render($large));
            $docs = [$smallBytes => $small, $largeBytes => $large];
            $this->assertConversionScalesLinearly(
                static function (string $input) use ($renderer, $docs): void {
                    $renderer->render($docs[strlen($input)]);
                },
                str_repeat('x', $smallBytes),
                str_repeat('x', $largeBytes),
                'mixed containers and ' . $kind . ' payload, per output byte',
                $smallBytes,
                $largeBytes,
            );
        }
    }

    private function document(int $depth, string $kind, int $words = 20): Document
    {
        $doc = new Document();
        $parent = $doc;
        for ($i = 0; $i < $depth; $i++) {
            $node = match ($i % 6) {
                0 => new Div(),
                1 => new Section(),
                2 => new BlockQuote(),
                3 => new ListBlock(),
                4 => new Figure(),
                5 => new FigureGroup(),
            };
            if ($kind === 'attrs') {
                $node->setAttribute('title', "one\ntwo");
            }
            if ($kind === 'title' && $node instanceof Div) {
                $node->setAttribute('class', 'note');
                $node->setHeader('Title');
            }
            $parent->appendChild(new Comment('hidden'));
            $parent->appendChild($node);
            $parent = $node;
            if ($node instanceof ListBlock) {
                $item = new ListItem();
                $node->appendChild($item);
                $parent = $item;
            }
        }
        if ($kind === 'pre' || $kind === 'unfinished-pre' || $kind === 'tag' || $kind === 'unfinished-tag') {
            $raw = match ($kind) {
                'pre' => "<pre>\n" . str_repeat("payload contents\n", $words) . "</pre>\n<p>after</p>\n",
                'unfinished-pre' => "<pre>one\n two\n",
                'unfinished-tag' => '<a title="one',
                default => "<a title=\"one\ntwo\">link</a>\n",
            };
            $parent->appendChild(new RawBlock($raw, 'html'));
        } elseif ($kind !== 'empty') {
            $p = new Paragraph();
            $p->appendChild(new Text(str_repeat('payload ', $words)));
            $parent->appendChild($p);
        }

        return $doc;
    }
}
