<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\WikilinksExtension;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\InlineParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContainerAndBracketBoundariesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function bracketRuns(): array
    {
        return [
            'escaped paren leaves the bracket run' => ['[/a]\\(b)/', '<p>[/a](b)/</p>'],
            'escaped opener preserves emphasis' => ['\\[/a](b)/', '<p>[<em>a](b)</em></p>'],
            'unescaped destination opens a link' => ['[/a](b)/', '<p><a href="b">/a</a>/</p>'],
            'bare bracket bounds emphasis' => ['[/a]b/', '<p>[/a]b/</p>'],
            'comment inside a bare run' => ['x [a %% c] d', '<p>x [a] d</p>'],
            'comment at the start of a bare run' => ['x [%% c] d', '<p>x [] d</p>'],
            'emphasis within the run' => ['[/a/]', '<p>[<em>a</em>]</p>'],
        ];
    }

    #[DataProvider('bracketRuns')]
    public function testBracketPrecedence(string $source, string $html): void
    {
        $this->assertSame($html . "\n", (new CarveConverter())->convert($source . "\n"));
    }

    public function testNestedBareBracketsKeepTheOuterTriggerScanLinear(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            public int $triggerBytes = 0;

            protected function parseLink(string $text, int $pos): ?array
            {
                if ($text !== $this->linkTriggerText) {
                    $this->triggerBytes += strlen($text);
                }

                return parent::parseLink($text, $pos);
            }
        };
        $source = str_repeat('[a[b]] ', 100);
        $parser->parse(new Paragraph(), $source);
        $this->assertLessThan(strlen($source) * 3, $parser->triggerBytes);
    }

    public function testAttachedBlankPayloadLines(): void
    {
        foreach (['-', '1.'] as $marker) {
            foreach (['```', '~~~', '``` php'] as $fence) {
                foreach ([1, 2, 3] as $blanks) {
                    $source = $marker . ' ' . $fence . "\n+\n" . str_repeat("\n", $blanks) . "tail\n";
                    $document = (new CarveConverter())->parse($source);
                    $code = $document->getChildren()[0]->getChildren()[0]->getChildren()[0];
                    $this->assertInstanceOf(CodeBlock::class, $code, $source);
                    $this->assertSame(str_repeat("\n", $blanks), $code->getContent(), $source);
                    $this->assertCount(2, $document->getChildren(), $source);
                }
            }
        }
    }

    public function testBlankPayloadAfterALaterFenceInAnItem(): void
    {
        $document = (new CarveConverter())->parse("- a\n\n  ```\n+\n\ntail\n");
        $code = $document->getChildren()[0]->getChildren()[0]->getChildren()[1];
        $this->assertInstanceOf(CodeBlock::class, $code);
        $this->assertSame("\n", $code->getContent());
    }

    public function testLabelNodesKeepDocumentExtensions(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new WikilinksExtension());
        $source = ":::[x [[Page]] %% hidden]\nbody\n:::\n";
        $div = $converter->parse($source)->getChildren()[0];
        $this->assertInstanceOf(Div::class, $div);
        $this->assertNotEmpty($div->getLabelNodes());
        $this->assertSame('x [[Page]]', $div->getLabel());
        $this->assertStringContainsString('<a href="page" class="wikilink" data-wikilink="Page">Page</a>', $converter->convert($source));
    }

    public function testABareBracketCommentDoesNotCutTheStoredLabel(): void
    {
        foreach (['[x %% y] z', 'a [%% c] d'] as $label) {
            $converter = new CarveConverter();
            $document = $converter->parse(':::[' . $label . " %% outer]\nbody\n:::\n");
            $div = $document->getChildren()[0];
            $this->assertInstanceOf(Div::class, $div);
            $this->assertSame($label, $div->getLabel());
            $written = (new CarveRenderer())->render($document);
            $this->assertInstanceOf(Div::class, $converter->parse($written)->getChildren()[0]);
            $this->assertSame($converter->convert(':::[' . $label . "]\nbody\n:::\n"), $converter->convert($written));
        }
    }

    public function testLabelCommentCutUsesSourceBytesAndKeepsClosedConstructs(): void
    {
        $label = 'é {%a %% secret%}';
        $document = (new CarveConverter())->parse(':::[' . $label . " \t%% outer]\nbody\n:::\n");
        $div = $document->getChildren()[0];
        $this->assertInstanceOf(Div::class, $div);
        $this->assertSame($label, $div->getLabel());
    }
}
