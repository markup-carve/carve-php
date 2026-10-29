<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AQuotedListChildNeedsNoExtraBlankLineTest extends TestCase
{
    use RuleATodo;

    public function testTheDivFollowsTheNestedListDirectly(): void
    {
        $source = ">   - a\n> - b\n>     - c\n>   :::\n1. lazy\n";
        $formatted = CarveConverter::toCarve($source);
        $expected = "> - a\n>\n>\n>\n> - b\n>   - c\n>   :::\n>\n>   :::\n\n1. lazy\n";

        self::assertSame($expected, $formatted);
        $converter = new CarveConverter();
        self::assertSame($converter->convert($source), $converter->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testTheFigureFollowsTheNestedListDirectly(): void
    {
        $source = "> - - text\n>   > q\n>\n>   ^ cap\n> - c\n";
        $formatted = CarveConverter::toCarve($source);
        $expected = "> - - text\n> +\n> > q\n> ^ cap\n> - c\n";

        self::assertSame($expected, $formatted);
        $converter = new CarveConverter();
        self::assertSame($converter->convert($source), $converter->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
