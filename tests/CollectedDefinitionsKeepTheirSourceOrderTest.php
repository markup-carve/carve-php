<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
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
}
