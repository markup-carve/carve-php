<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A caret ending one node opens an inline note against the `[` the next node
 * writes, so the writer escapes it where a note could open
 * (markup-carve/carve-php#2061). The parser never builds these trees, so they
 * are built by hand.
 */
class ACaretBeforeANodeThatOpensWithABracketIsEscapedTest extends TestCase
{
    /**
     * @return array<string, array{0: \Closure(): array<\MarkupCarve\Carve\Node\Node>, 1: string, 2: string}>
     */
    public static function treeProvider(): array
    {
        return [
            'a link' => [
                fn (): array => [new Text('x ^'), self::link('n')],
                "x \\^[n](u)\n",
                '<p>x ^<a href="u">n</a></p>',
            ],
            'a span' => [
                fn (): array => [new Text('x^'), self::span('n')],
                "x\\^[n]{.k}\n",
                '<p>x^<span class="k">n</span></p>',
            ],
            'a literal backslash before the caret' => [
                fn (): array => [new Text('a\\^'), self::link('n')],
                "a\\\\\\^[n](u)\n",
                '<p>a\\^<a href="u">n</a></p>',
            ],
            'control: an escaped caret' => [
                fn (): array => [new EscapedText('^'), self::link('n')],
                "\\^[n](u)\n",
                '<p>^<a href="u">n</a></p>',
            ],
            'control: an empty link label, which opens no note' => [
                fn (): array => [new Text('x ^'), self::link('')],
                "x ^[](u)\n",
                '<p>x ^<a href="u"></a></p>',
            ],
        ];
    }

    /**
     * @param \Closure(): array<\MarkupCarve\Carve\Node\Node> $inlines
     * @param string $carve
     * @param string $readBack
     */
    #[DataProvider('treeProvider')]
    public function testTheTreeIsWritten(Closure $inlines, string $carve, string $readBack): void
    {
        $this->assertSame($carve, (new CarveRenderer())->render(self::document($inlines())));
    }

    /**
     * @param \Closure(): array<\MarkupCarve\Carve\Node\Node> $inlines
     * @param string $carve
     * @param string $readBack
     */
    #[DataProvider('treeProvider')]
    public function testTheWrittenSourceReadsBackAsTheTree(Closure $inlines, string $carve, string $readBack): void
    {
        $written = (new CarveRenderer())->render(self::document($inlines()));

        $this->assertSame($readBack, trim(CarveConverter::create()->convert($written)));
    }

    /**
     * Control: inside a note no note opens, so the caret stays bare.
     */
    public function testACaretInsideANoteStaysBare(): void
    {
        $note = new InlineFootnote();
        $note->appendChild(new Text('x ^'));
        $note->appendChild(self::link('n'));

        $this->assertSame("y ^[x ^[n](u)]\n", (new CarveRenderer())->render(self::document([new Text('y '), $note])));
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $inlines
     */
    private static function document(array $inlines): Document
    {
        $paragraph = new Paragraph();
        foreach ($inlines as $inline) {
            $paragraph->appendChild($inline);
        }
        $document = new Document();
        $document->appendChild($paragraph);

        return $document;
    }

    private static function link(string $label): Link
    {
        $link = new Link('u');
        if ($label !== '') {
            $link->appendChild(new Text($label));
        }

        return $link;
    }

    private static function span(string $label): Node
    {
        $span = new Span();
        $span->setAttribute('class', 'k');
        $span->appendChild(new Text($label));

        return $span;
    }
}
