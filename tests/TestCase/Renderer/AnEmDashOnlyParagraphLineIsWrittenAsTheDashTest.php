<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Under rule A an indented `---` opens a thematic break, so the writer spells
 * a paragraph line that holds only a dash run as the dash characters
 * (ruling on carve#3885).
 */
class AnEmDashOnlyParagraphLineIsWrittenAsTheDashTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function runs(): iterable
    {
        yield 'em dash' => ['---', 'em_dash', "\u{2014}"];
        yield 'two en dashes' => ['----', 'en_dash', "\u{2013}\u{2013}"];
        yield 'em and en dash' => ['-----', 'em_dash', "\u{2014}\u{2013}"];
        yield 'two em dashes' => ['------', 'em_dash', "\u{2014}\u{2014}"];
    }

    #[DataProvider('runs')]
    public function testADashOnlyLineIsWrittenAsItsDashes(string $run, string $kind, string $dashes): void
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('foo'));
        $paragraph->appendChild(new SoftBreak());
        $paragraph->appendChild(new SmartPunctuation($kind, $run));
        $paragraph->appendChild(new SoftBreak());
        $paragraph->appendChild(new Text('bar'));
        $document = new Document();
        $document->appendChild($paragraph);

        $written = (new CarveRenderer())->render($document);

        $this->assertSame("foo\n{$dashes}\nbar\n", $written);
        $this->assertSame("<p>foo\n{$dashes}\nbar</p>", trim((new CarveConverter())->convert($written)));
    }

    public function testAnIndentedEmDashLineIsAThematicBreak(): void
    {
        $this->assertSame("<p>foo</p>\n<hr>\n<p>bar</p>", trim((new CarveConverter())->convert("foo\n ---\nbar\n")));
    }
}
