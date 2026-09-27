<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class AnUnquotedAttributeUsesTheGrammarExclusionsTest extends TestCase
{
    public function testPipeAndBackslashKeepTheAttributeBlockLiteral(): void
    {
        $converter = new CarveConverter();
        foreach (['a|b', 'a\\b', 'a\\|b'] as $value) {
            self::assertFalse(AttributeParser::isValidPayload('k=' . $value));
            self::assertSame([], AttributeParser::parse('k=' . $value));
            self::assertStringNotContainsString(' k=', $converter->convert('*x*{k=' . $value . '}'));
            self::assertStringContainsString('{k=', $converter->convert('*x*{k=' . $value . '}'));
        }
    }

    public function testAnOpeningBraceBelongsToTheUnquotedValue(): void
    {
        $converter = new CarveConverter();
        self::assertSame(['k' => 'a{b'], AttributeParser::parse('k=a{b'));
        self::assertSame("<p><strong k=\"a{b\">x</strong></p>\n", $converter->convert('*x*{k=a{b}'));
        self::assertSame("<p k=\"a{b\">x</p>\n", $converter->convert("{k=a{b}\nx"));
    }

    public function testRowAttributeBracesSurviveWritingAndReparsing(): void
    {
        $converter = new CarveConverter();
        foreach (['a{b', '"a{b"', '"a}b"'] as $value) {
            $source = '| a |{k=' . $value . '}';
            $html = $converter->convert($source);
            self::assertStringContainsString('<tr k=', $html);
            $written = (new CarveRenderer())->render($converter->parse($source));
            self::assertSame($html, $converter->convert($written));
        }
    }

    public function testAReferenceImageDoesNotRepeatItsBraceAttribute(): void
    {
        $converter = new CarveConverter();
        $source = "![a{x=y][r]{k=a{b}\n\n[r]: /u\n";
        $writer = new CarveRenderer();
        $written = $writer->render($converter->parse($source));
        self::assertSame($source, $written);
        self::assertSame($written, $writer->render($converter->parse($written)));
    }

    public function testTheWriterQuotesBackslashesAndPreservesTheirValue(): void
    {
        $converter = new CarveConverter();
        $source = '*x*{k="a\\\\b"}';
        $written = (new CarveRenderer())->render($converter->parse($source));
        self::assertStringContainsString('k="a\\\\b"', $written);
        self::assertSame($converter->convert($source), $converter->convert($written));
    }
}
