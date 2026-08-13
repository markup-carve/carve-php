<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class SemanticSpanSugarTest extends TestCase
{
    public function testFixedRegistryAndValueMappings(): void
    {
        $converter = new CarveConverter();
        $source = "[CSS]{dfn abbr=\"Cascading Style Sheets\"}\n[Noon]{time=\"12:00\"} [x]{code mark samp var kbd cite}";

        self::assertSame(
            "<p><dfn><abbr title=\"Cascading Style Sheets\">CSS</abbr></dfn>\n"
            . "<time datetime=\"12:00\">Noon</time> <span code=\"\" mark=\"\"><cite><kbd><var><samp>x</samp></var></kbd></cite></span></p>\n",
            $converter->convert($source),
        );
    }

    public function testRemainingAttributesUseOneHardenedOuterSpan(): void
    {
        $converter = new CarveConverter();

        self::assertSame(
            "<p><span id=\"copy\" class=\"shortcut\" data-key=\"copy\"><kbd><strong>Ctrl</strong>+C</kbd></span></p>\n",
            $converter->convert('[*Ctrl*+C]{#copy .shortcut kbd data-key="copy" onclick="alert(1)"}'),
        );
    }

    public function testUnknownAndCaseVariantAttributesRemainOrdinary(): void
    {
        $converter = new CarveConverter();

        self::assertSame("<p><span widget=\"\" KBD=\"\">x</span></p>\n", $converter->convert('[x]{widget KBD}'));
    }
}
