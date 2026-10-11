<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderLossException;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockExtension;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\Utility\ConsumedAbbreviationDefinitions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TerminalRenderersAllocateOnlyNeededMarkersTest extends TestCase
{
    private static function privateUseText(): string
    {
        $text = '';
        for ($code = 0xE000; $code <= 0xF8FF; $code++) {
            $text .= mb_chr($code, 'UTF-8');
        }

        return $text;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function contentContexts(): iterable
    {
        $text = self::privateUseText();
        foreach (['plain', 'ansi'] as $target) {
            foreach (
                [
                    'text' => "a{$text}b",
                    'code' => "`a{$text}b`",
                    'literal' => "!`a{$text}b`",
                    'image' => "before ![a{$text}b](/image) after",
                    'link' => "[a{$text}b](/url)",
                    'code-block' => "```\na{$text}b\n```",
                    'footnote' => "note[^a]\n\n[^a]: {$text}\n",
                ] as $name => $source
            ) {
                yield "{$target}-{$name}" => [$target, $source];
            }
        }
    }

    #[DataProvider('contentContexts')]
    public function testFullCharacterPoolNeedsNoAbbreviationMarker(string $target, string $source): void
    {
        $converter = $target === 'plain' ? CarveConverter::plainText() : CarveConverter::ansi();
        $result = $converter->convertWithReport($source, true);
        self::assertSame(1, substr_count($result->value, self::privateUseText()));
        self::assertSame([], $result->losses);
        self::assertSame(0, $result->totalLosses);
        self::assertFalse($result->truncated);
        self::assertSame($result->value, $converter->convert($source));
        $renderer = $target === 'plain' ? new PlainTextRenderer() : new AnsiRenderer();
        self::assertSame($result->value, $renderer->render($converter->parse($source)));
        self::assertSame(0, $converter->convertWithReport("*[A]: alpha\n\nA\n")->totalLosses);
        self::assertSame($result->value, $converter->convertWithReport($source)->value);
    }

    public function testFallbackDefinitionNodesNeedMarkersButOpaquePayloadDoesNot(): void
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text(self::privateUseText()));
        $carrier = new BlockExtension('org.example.test', $paragraph);
        $carrier->setPayload(['format' => 'json', 'value' => ['opaque' => new AbbreviationDefinition('A', 'alpha')]]);
        $document = new Document();
        $document->appendChild($carrier);
        self::assertFalse(ConsumedAbbreviationDefinitions::hasDefinitionNodes($document));
        foreach ([new PlainTextRenderer(), new AnsiRenderer()] as $renderer) {
            self::assertSame(1, substr_count($renderer->render($document), self::privateUseText()));
        }
        $carrier->setFallback(new AbbreviationDefinition('A', 'alpha'));
        self::assertTrue(ConsumedAbbreviationDefinitions::hasDefinitionNodes($document));
        foreach (
            [
                [new PlainTextRenderer(), "*[A]: alpha\n"],
                [new AnsiRenderer(), "\033[2m*[A]: alpha\033[0m\n"],
            ] as [$renderer, $expected]
        ) {
            self::assertSame($expected, $renderer->render($document));
        }
    }

    public function testFullCharacterPoolStillReportsRawOmissions(): void
    {
        $text = self::privateUseText();
        $source = "`{$text}`{=html} and `two`{=typst} tail";
        foreach ([CarveConverter::plainText(), CarveConverter::ansi()] as $converter) {
            $result = $converter->convertWithReport($source);
            self::assertSame(2, $result->totalLosses);
            self::assertSame(['html', 'typst'], array_column($result->losses, 'format'));
            $bounded = $converter->convertWithReport($source, maxRenderLosses: 0);
            self::assertSame(2, $bounded->totalLosses);
            self::assertSame([], $bounded->losses);
            self::assertTrue($bounded->truncated);
            try {
                $converter->convertWithReport($source, strictLosses: true, maxRenderLosses: 1);
                self::fail('Strict rendering must refuse raw omissions');
            } catch (RenderLossException $exception) {
                self::assertSame(2, $exception->totalLosses);
                self::assertCount(1, $exception->losses);
                self::assertTrue($exception->truncated);
            }
            self::assertSame(0, $converter->convertWithReport('clean', true)->totalLosses);
        }
    }
}
