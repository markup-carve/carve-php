<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BlockQuoteChainTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function bodies(): array
    {
        return [
            'short' => [2, "text\n"],
            'paragraphs' => [32, "first\n\nsecond\n"],
            'code' => [128, "```\n raw\ntext\n```\n"],
            'raw html' => [192, "``` =html\n<pre>\n raw\n</pre>\n```\n"],
            'unclosed pre' => [128, "``` =html\n<pre>\n raw\n```\n"],
            'pre prefix' => [128, "``` =html\n<prefix-el>\n raw\n```\n"],
            'inline pre' => [128, "a `<pre>`{=html}\nb\n"],
            'dropped raw' => [128, "``` =other\nraw\n```\n"],
            'empty body' => [128, "%% comment\n"],
        ];
    }

    #[DataProvider('bodies')]
    public function testCumulativeIndentationMatchesTheRecursiveRenderer(int $depth, string $body): void
    {
        $prefix = str_repeat('> ', $depth);
        $source = $prefix . str_replace("\n", "\n" . $prefix, rtrim($body, "\n")) . "\n";
        $doc = (new CarveConverter())->parse($source);
        $quote = $doc->getChildren()[0];
        for ($index = 0; $index < $depth; $index++) {
            self::assertInstanceOf(BlockQuote::class, $quote);
            if ($index === 0 || $index === intdiv($depth, 2)) {
                $quote->setAttribute('title', "line one\nline two & ü");
            }
            $quote = $quote->getChildren()[0] ?? null;
        }
        $reference = new class extends HtmlRenderer {
        };
        self::assertSame($reference->render(clone $doc), (new HtmlRenderer())->render($doc));
    }

    public function testQuoteListenersObserveEveryNode(): void
    {
        $doc = (new CarveConverter())->parse(str_repeat('> ', 128) . "text\n");
        foreach (['render.block_quote', 'render.*'] as $event) {
            $calls = 0;
            $renderer = new HtmlRenderer();
            $renderer->on($event, static function ($renderEvent) use (&$calls): void {
                if ($renderEvent->getNode() instanceof BlockQuote) {
                    $calls++;
                }
            });
            $renderer->render($doc);
            self::assertSame(128, $calls);
        }
    }

    public function testOverDepthQuotesRefuseAndRestoreTheRenderer(): void
    {
        $node = new BlockQuote();
        for ($depth = 0; $depth < 1000; $depth++) {
            $parent = new BlockQuote();
            $parent->appendChild($node);
            $node = $parent;
        }
        $renderer = new HtmlRenderer();
        try {
            $renderer->renderNodeFragment($node);
            self::fail('Expected a render depth refusal');
        } catch (RenderDepthExceededException) {
            self::assertSame("<blockquote>\n\n</blockquote>\n", $renderer->renderNodeFragment(new BlockQuote()));
        }
    }
}
