<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class SemanticSpanSugarTest extends TestCase
{
    public function testCoreRegistryAndValueMappings(): void
    {
        $converter = new CarveConverter();
        $source = "[HTML]{abbr=\"HyperText Markup Language\"}\n[Noon]{time=\"12:00\"} [Tab]{kbd}";

        self::assertSame(
            "<p><abbr title=\"HyperText Markup Language\">HTML</abbr>\n"
            . "<time datetime=\"12:00\">Noon</time> <kbd>Tab</kbd></p>\n",
            $converter->convert($source),
        );
    }

    public function testLeftoversRideTheElementRatherThanAWrapper(): void
    {
        $converter = new CarveConverter();

        self::assertSame(
            "<p><kbd id=\"copy\" class=\"shortcut\" data-key=\"copy\"><strong>Ctrl</strong>+C</kbd></p>\n",
            $converter->convert('[*Ctrl*+C]{#copy .shortcut kbd data-key="copy" onclick="alert(1)"}'),
        );
    }

    public function testNamesOutsideCoreStayOrdinaryAttributes(): void
    {
        $converter = new CarveConverter();

        self::assertSame(
            "<p><span samp=\"\" var=\"\" cite=\"\" dfn=\"\">x</span></p>\n",
            $converter->convert('[x]{samp var cite dfn}'),
        );
    }

    public function testUnknownAndCaseVariantAttributesRemainOrdinary(): void
    {
        $converter = new CarveConverter();

        self::assertSame("<p><span widget=\"\" KBD=\"\">x</span></p>\n", $converter->convert('[x]{widget KBD}'));
    }
}
