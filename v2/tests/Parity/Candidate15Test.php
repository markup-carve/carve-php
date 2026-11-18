<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Parity;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\TestCase;

final class Candidate15Test extends TestCase
{
    public function testMalformedRegionMarkerDoesNotSplitParagraph(): void
    {
        self::assertSame("<p>a\nb</p>", Carve2::toHtml("a\n%% #region-x\nb"));
    }

    public function testGluedItemAttributesMerge(): void
    {
        self::assertSame("<ul>\n  <li class=\"a b\">x</li>\n</ul>", Carve2::toHtml('-{.a}{.b} x'));
    }

    public function testNestedParagraphBraceReportsAtFirstText(): void
    {
        $source = "-{.x} {.x\n     .y}\n  # h";
        $findings = array_values(array_filter(
            Carve2::lint($source),
            static fn ($d): bool => $d->code === 'block-marker-as-text',
        ));
        self::assertCount(1, $findings);
        self::assertSame([6, 9], [$findings[0]->startOffset, $findings[0]->endOffset]);
    }

    public function testEscapedEmphasisIsNotAnOpener(): void
    {
        self::assertNotContains('unclosed-inline-emphasis', array_map(
            static fn ($d): string => $d->code,
            Carve2::lint('a\\*b'),
        ));
    }

    public function testLintReturnsParseIncludeDiagnostic(): void
    {
        $findings = Carve2::lint('{{ "a" }}');
        self::assertSame(['include-no-resolver', 'parse', 0, 9], [
            $findings[0]->code, $findings[0]->channel, $findings[0]->startOffset, $findings[0]->endOffset,
        ]);
    }

    public function testBidiControlInsideMathIsReported(): void
    {
        $source = "$`x\u{2066}y`\n";
        $findings = array_values(array_filter(
            Carve2::lint($source),
            static fn ($d): bool => $d->code === 'bidi-control-in-source',
        ));
        self::assertCount(1, $findings);
        self::assertSame([3, 6], [$findings[0]->startOffset, $findings[0]->endOffset]);
    }

    public function testDroppedRawBlockAddsNoExtraItemBlank(): void
    {
        self::assertSame("<ul>\n  <li>a\n  </li>\n</ul>", Carve2::toHtml("- a\n \n ```=latex\n x"));
    }

    public function testPanelTargetUsesGroupNumberWithoutGroupId(): void
    {
        $source = "::: figure\n{#p}\n![a](a)\n^ (a) One\n:::\n^ Figure #: Both\n\nSee </#p>.";
        self::assertStringContainsString('<a href="#p">Figure 1a</a>', Carve2::toHtml($source));
    }
}
