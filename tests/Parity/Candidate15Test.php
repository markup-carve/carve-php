<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Parity;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\TestCase;

final class Candidate15Test extends TestCase
{
    public function testMalformedRegionMarkerDoesNotSplitParagraph(): void
    {
        self::assertSame("<p>a\nb</p>", Carve::toHtml("a\n%% #region-x\nb"));
    }

    public function testGluedItemAttributesMerge(): void
    {
        self::assertSame("<ul>\n  <li class=\"a b\">x</li>\n</ul>", Carve::toHtml('-{.a}{.b} x'));
    }

    public function testNestedParagraphBraceReportsAtFirstText(): void
    {
        $source = "-{.x} {.x\n     .y}\n  # h";
        $findings = array_values(array_filter(
            Carve::lint($source),
            static fn ($d): bool => $d->code === 'block-marker-as-text',
        ));
        self::assertCount(1, $findings);
        self::assertSame([6, 9], [$findings[0]->startOffset, $findings[0]->endOffset]);
    }

    public function testEscapedEmphasisIsNotAnOpener(): void
    {
        self::assertNotContains('unclosed-inline-emphasis', array_map(
            static fn ($d): string => $d->code,
            Carve::lint('a\\*b'),
        ));
    }

    public function testLintReturnsParseIncludeDiagnostic(): void
    {
        $findings = Carve::lint('{{ "a" }}');
        self::assertSame(['include-no-resolver', 'parse', 0, 9], [
            $findings[0]->code, $findings[0]->channel, $findings[0]->startOffset, $findings[0]->endOffset,
        ]);
    }

    public function testBidiControlInsideMathIsReported(): void
    {
        $source = "$`x\u{2066}y`\n";
        $findings = array_values(array_filter(
            Carve::lint($source),
            static fn ($d): bool => $d->code === 'bidi-control-in-source',
        ));
        self::assertCount(1, $findings);
        self::assertSame([3, 6], [$findings[0]->startOffset, $findings[0]->endOffset]);
    }

    public function testDroppedRawBlockAddsNoExtraItemBlank(): void
    {
        self::assertSame("<ul>\n  <li>a\n  </li>\n</ul>", Carve::toHtml("- a\n \n ```=latex\n x"));
    }

    public function testPanelTargetUsesGroupNumberWithoutGroupId(): void
    {
        $source = "::: figure\n{#p}\n![a](a)\n^ (a) One\n:::\n^ Figure #: Both\n\nSee </#p>.";
        self::assertStringContainsString('<a href="#p">Figure 1a</a>', Carve::toHtml($source));
    }

    public function testCatalogLintRangesAcrossContainers(): void
    {
        foreach (['CC-695', 'CC-751', 'CC-811', 'CC-883', 'CC-962', 'CC-1014'] as $id) {
            $case = Corpus::clauseCases()[$id];
            $expected = $case['expected']['diagnostics'][0];
            $found = array_values(array_filter(
                Carve::lint($case['input'], Corpus::caseOptions($case)),
                static fn ($d): bool => $d->code === $expected['code'],
            ));
            self::assertNotEmpty($found, $id);
            $prefix = substr($case['input'], 0, $found[0]->startOffset);
            $line = substr_count($prefix, "\n") + 1;
            $feed = strrpos($prefix, "\n");
            $column = strlen($prefix) - ($feed === false ? 0 : $feed + 1) + 1;
            self::assertSame([$expected['line'], $expected['column']], [$line, $column], $id);
        }
    }
}
