<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\TestCase;

class CollectedDefinitionsKeepTheirSourceOrderTest extends TestCase
{
    public function testDefinitionBeforeParagraphStaysBeforeIt(): void
    {
        $source = "[bold]: /x\n\nsee [*bold*][]\n";
        foreach ([CarveConverter::markdown(), CarveConverter::plainText(), CarveConverter::ansi()] as $converter) {
            $output = $converter->convert($source);
            self::assertLessThan(strpos($output, 'see'), strpos($output, '[bold]: /x'));
        }
    }

    public function testSeparateParseAndRenderKeepDefinitionBeforeParagraph(): void
    {
        $source = "[bold]: /x\n\nsee [*bold*][]\n";
        foreach ([CarveConverter::markdown(), CarveConverter::plainText(), CarveConverter::ansi()] as $converter) {
            $output = $converter->render($converter->parse($source));
            self::assertLessThan(strpos($output, 'see'), strpos($output, '[bold]: /x'));
        }
    }

    public function testCustomConverterTracksPositionsDuringConvert(): void
    {
        $source = "[bold]: /x\n\nsee [*bold*][]\n";
        foreach ([CarveConverter::markdown(new BlockParser()), new CarveConverter(renderer: new MarkdownRenderer())] as $converter) {
            $output = $converter->convert($source);
            self::assertLessThan(strpos($output, 'see'), strpos($output, '[bold]: /x'));
        }
    }

    public function testCustomConverterTracksPositionsDuringSeparateParseAndRender(): void
    {
        $source = "[bold]: /x\n\nsee [*bold*][]\n";
        foreach ([CarveConverter::markdown(new BlockParser()), new CarveConverter(renderer: new MarkdownRenderer())] as $converter) {
            $output = $converter->render($converter->parse($source));
            self::assertLessThan(strpos($output, 'see'), strpos($output, '[bold]: /x'));
        }
    }

    public function testDefinitionBetweenBlocksStaysBetweenThem(): void
    {
        $source = "- a\n  [r]: /u\n more\ntail\n";
        foreach ([CarveConverter::markdown(), CarveConverter::plainText(), CarveConverter::ansi()] as $converter) {
            $output = $converter->convert($source);
            self::assertLessThan(strpos($output, '[r]: /u'), strpos($output, 'a'));
            self::assertLessThan(strpos($output, 'more'), strpos($output, '[r]: /u'));
        }
    }

    public function testPlainAndTerminalKeepDefinitionAttributes(): void
    {
        $source = "> text\n> [a]: /u {.c}\nlazy\n";
        foreach ([CarveConverter::plainText(), CarveConverter::ansi()] as $converter) {
            self::assertStringContainsString('[a]: /u {.c}', $converter->convert($source));
        }
    }

    public function testIncludedDefinitionStaysAtTheIncludeSite(): void
    {
        $resolver = new class implements IncludeResolverInterface {
            public function resolve(string $path, IncludeContext $context): string
            {
                return "[c]: /c\n\nChild text.\n";
            }
        };
        $converter = CarveConverter::markdown();
        $source = "\n\nParent one.\n\nParent two.\n\n{{ child.crv }}\n\nParent end.\n";
        $document = $converter->transform($converter->parse($source), new IncludeExpander($resolver));
        $output = $converter->render($document);

        self::assertLessThan(strpos($output, '[c]: /c'), strpos($output, 'Parent two.'));
        self::assertLessThan(strpos($output, 'Child text.'), strpos($output, '[c]: /c'));
        self::assertLessThan(strpos($output, 'Parent end.'), strpos($output, '[c]: /c'));
    }

    public function testParentDefinitionsStayOnTheirSideOfAnInclude(): void
    {
        $resolver = new class implements IncludeResolverInterface {
            public function resolve(string $path, IncludeContext $context): string
            {
                return "Child one.\n\nFiller.\n\nFiller two.\n\nChild last.\n";
            }
        };
        $converter = CarveConverter::markdown();
        $source = "[before]: /b\n\n{{ child.crv }}\n\n[after]: /a\n\nUse [*after*][].\n";
        $document = $converter->transform($converter->parse($source), new IncludeExpander($resolver));
        $output = $converter->render($document);

        self::assertLessThan(strpos($output, 'Child one.'), strpos($output, '[before]: /b'));
        self::assertLessThan(strpos($output, '[after]: /a'), strpos($output, 'Child last.'));
        self::assertLessThan(strpos($output, 'Use'), strpos($output, '[after]: /a'));
    }
}
