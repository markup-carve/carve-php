<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §10g (markup-carve/carve#1122): the composite figure degrades
 * DETERMINISTICALLY on every non-HTML target, so engines produce one spelling
 * instead of each meeting the graceful-degradation floor its own way.
 */
class ACompositeFigureSurvivesEveryTargetTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "{#fig-x}\n::: figure\n![one](a.png)\n^ (a) One\n\n![two](b.png)\n^ (b) Two\n:::\n^ Figure #: Group caption\n";

    public function testMarkdownEmitsPanelsThenTheBoldGroupCaptionLast(): void
    {
        // T1: each host degraded as usual, each panel caption an emphasized
        // paragraph after its host, the group caption LAST as a bold
        // paragraph, its number resolved.
        $expected = "![one](a.png)\n\n*(a) One*\n\n![two](b.png)\n\n*(b) Two*\n\n**Figure 1: Group caption**\n";

        $this->assertSame($expected, CarveConverter::markdown()->convert(self::SOURCE));
    }

    public function testPlainTextPutsTheGroupCaptionFirst(): void
    {
        // T2: caption-first, because on a caption-less target the group
        // caption is the only line that says what the following blocks are
        // one of; then per panel its caption line, then its host.
        $expected = "Figure 1: Group caption\n\n(a) One\none\n\n(b) Two\ntwo\n";

        $this->assertSame($expected, CarveConverter::plainText()->convert(self::SOURCE));
    }

    public function testTheTerminalFollowsThePlainTextOrderWithItsCaptionStyling(): void
    {
        $ansi = CarveConverter::ansi()->convert(self::SOURCE);
        $stripped = (string)preg_replace('/\x1b\[[0-9;]*m/', '', $ansi);

        $groupAt = strpos($stripped, 'Figure 1: Group caption');
        $panelAt = strpos($stripped, '(a) One');
        $this->assertNotFalse($groupAt);
        $this->assertNotFalse($panelAt);
        $this->assertLessThan($panelAt, $groupAt, 'the group caption line comes first on the terminal target');
    }

    public function testStrayContentIsPreservedInPlaceOnEveryTarget(): void
    {
        // §10g floor: preserved stray content is CONTENT and no target may
        // silently discard it.
        $source = "::: figure\nShot the same day.\n\n![one](a.png)\n^ (a) One\n:::\n^ Figure #: G\n";

        $this->assertStringContainsString('Shot the same day.', CarveConverter::markdown()->convert($source));
        $this->assertStringContainsString('Shot the same day.', CarveConverter::plainText()->convert($source));
    }
}
