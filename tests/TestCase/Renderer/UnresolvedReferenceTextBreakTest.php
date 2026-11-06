<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\SoftBreakMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnresolvedReferenceTextBreakTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function references(): array
    {
        return [
            'link' => ["[x][a\nb]\n", "[x][a b]\n"],
            'image' => ["![x\ny][]\n", "![x y][]\n"],
            'marked image' => ["![*x*][a\nb]\n", "![*x*][a b]\n"],
        ];
    }

    #[DataProvider('references')]
    public function testSourceBreakIsOneSpaceInBothTextTargets(string $source, string $expected): void
    {
        foreach ([new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
            self::assertSame($expected, CarveConverter::create(renderer: $renderer)->convert($source));
        }
    }

    public function testConfiguredNewlineModeKeepsTheSourceBreak(): void
    {
        $source = "[x][a\nb]\n";
        foreach ([new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
            $renderer->setSoftBreakMode(SoftBreakMode::Newline);
            self::assertSame($source, CarveConverter::create(renderer: $renderer)->convert($source));
        }
    }

    public function testHardBreaksInFailedReferencesStayOnTwoLines(): void
    {
        foreach (
            [
                "::: |\n[a\nb][missing]\n:::\n" => "[a\nb][missing]\n",
                "[a\\\nb][missing]\n" => "[a\\\nb][missing]\n",
                "[a\nb\\\nc][missing]\n" => "[a b\\\nc][missing]\n",
                "[a `x\ny`][missing]\n" => "[a `x\ny`][missing]\n",
                "[a\nb `x\ny`][missing]\n" => "[a b `x\ny`][missing]\n",
                "[x\ny `x\ny`][missing]\n" => "[x y `x\ny`][missing]\n",
            ] as $source => $expected
        ) {
            foreach ([new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
                self::assertSame($expected, CarveConverter::create(renderer: $renderer)->convert($source));
            }
        }
    }

    public function testPositionlessHardBreakInFailedReferenceStaysOnTwoLines(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $link = new Link('');
        $link->setRawReferenceLabel("[a\\\nb][missing]");
        $link->appendChild(new Text('a'));
        $link->appendChild(new HardBreak());
        $link->appendChild(new Text('b'));
        $paragraph->appendChild($link);
        $document->appendChild($paragraph);

        foreach ([new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
            self::assertSame("[a\\\nb][missing]\n", $renderer->render($document));
        }
    }

    public function testPositionlessSpacedHardBreakAndVerbatimBreakStayOnTwoLines(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $link = new Link('');
        $link->setRawReferenceLabel("[a\\  \nb `x\ny`][missing]");
        $link->appendChild(new Text('a'));
        $link->appendChild(new HardBreak());
        $link->appendChild(new Text('b '));
        $link->appendChild(new Code("x\ny"));
        $paragraph->appendChild($link);
        $document->appendChild($paragraph);

        foreach ([new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
            self::assertSame("[a\\  \nb `x\ny`][missing]\n", $renderer->render($document));
        }
    }
}
