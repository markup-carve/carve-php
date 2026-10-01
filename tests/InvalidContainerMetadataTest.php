<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Lint\SourceLinter;
use PHPUnit\Framework\TestCase;

final class InvalidContainerMetadataTest extends TestCase
{
    public function testBareTabTitlesRecoverWithoutNamingTabs(): void
    {
        $source = ":::: tabs\n::: tab Install\nBody one.\n:::\n::: tab Configure\nBody two.\n:::\n::::\n";
        $converter = new CarveConverter();
        $converter->addExtension(new TabsExtension());
        $html = $converter->convert($source);
        self::assertStringContainsString('class="tabs-label">Tab 1</label>', $html);
        self::assertStringContainsString('class="tabs-label">Tab 2</label>', $html);
        self::assertStringContainsString('<p>Body one.</p>', $html);
        self::assertStringNotContainsString(':::', $html);
        $warnings = array_values(array_filter((new SourceLinter())->lint($source), static fn ($w) => $w->rule === 'fence-title-syntax'));
        self::assertSame([2, 5], array_column($warnings, 'line'));
    }

    public function testMalformedMetadataKeepsNestedBlocksAndFigureContainers(): void
    {
        foreach (['Bare title', '“Curly title”', '"unclosed', '[unclosed', '"Good" [broken', "\t\"Tabbed\"", '{.inline}'] as $metadata) {
            $source = ":::: outer\n::: figure {$metadata}\n# Heading\n\n- one\n- two\n:::\n::::\nAfter.\n";
            $html = (new CarveConverter())->convert($source);
            self::assertStringContainsString('<div class="figure">', $html, $metadata);
            self::assertStringContainsString('<h1', $html);
            self::assertStringContainsString('<ul>', $html);
            self::assertStringNotContainsString('<figure', $html);
            self::assertStringNotContainsString(':::', $html);
            $warnings = array_filter((new SourceLinter())->lint($source), static fn ($w) => $w->rule === 'fence-title-syntax');
            self::assertCount(1, $warnings);
        }
    }

    public function testOpaquePayloadsAndGluedKindsDoNotRecover(): void
    {
        foreach (["```\n::: tab Wrong\n```\n", "%%%\n::: tab Wrong\n%%%\n", ":::tab Wrong\nbody\n"] as $source) {
            self::assertSame([], array_values(array_filter((new SourceLinter())->lint($source), static fn ($w) => $w->rule === 'fence-title-syntax')));
        }
    }

    public function testUnicodeSeparatorsAreDiagnosedNotAcceptedAsPadding(): void
    {
        foreach (["\u{0085}", "\u{FEFF}"] as $ws) {
            $source = "::: note{$ws}\"Title\"\nx\n:::\n";
            $html = (new CarveConverter())->convert($source);
            self::assertStringContainsString('<aside', $html);
            self::assertStringNotContainsString('admonition-title', $html);
            self::assertCount(1, array_filter((new SourceLinter())->lint($source), static fn ($w) => $w->rule === 'fence-title-syntax'));
        }
    }

    public function testBomAndCrLfDiagnosticOffsets(): void
    {
        $source = "\u{FEFF}::: widget Wrong😀\r\nbody\r\n:::\r\n";
        $warnings = array_values(array_filter((new SourceLinter())->lint($source), static fn ($w) => $w->rule === 'fence-title-syntax'));
        self::assertCount(1, $warnings);
        self::assertSame('::: widget Wrong😀', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
        self::assertSame(1, $warnings[0]->line);
        self::assertSame(2, $warnings[0]->column);
    }

    public function testFormattingRequiresReviewBeforeDroppingMetadata(): void
    {
        $patch = CarveConverter::toCarvePatch("::: note Wrong\nbody\n:::\n");
        self::assertSame([], $patch->edits);
        self::assertSame('invalid-container-metadata', $patch->unresolved[0]->code);
    }

    public function testDjotMigrationKeepsRejectedOpenerText(): void
    {
        $source = (new DjotToCarve())->convert("::: tip Custom Title\nbody\n:::\n");
        $html = (new CarveConverter())->convert($source);
        self::assertSame("<p>::: tip Custom Title\nbody\n:::</p>", trim($html));
    }

    public function testContainerKindRemainsAscii(): void
    {
        self::assertStringNotContainsString('<div', (new CarveConverter())->convert("::: noté Title\nbody\n:::\n"));
    }

    public function testProseOpenerDoesNotBlockFormatting(): void
    {
        $patch = CarveConverter::toCarvePatch("  ::: widget Bad\nx\n:::\n");
        self::assertNotEmpty($patch->edits);
        self::assertSame([], $patch->unresolved);
    }

    public function testAuthoredMetadataAndNestedValidFencesDuringDjotMigration(): void
    {
        foreach (['"T" extra' => ' “T” extra', '“T”' => ' “T”', '{.x}' => ''] as $metadata => $visible) {
            $source = "::: tip {$metadata}\nbody\n:::\n";
            $migrated = (new DjotToCarve())->convert($source);
            self::assertSame("<p>::: tip{$visible}\nbody\n:::</p>", trim((new CarveConverter())->convert($migrated)));
        }
        $source = "::: tip Bad X\n\n::: note\nx\n:::\n:::\n";
        $migrated = (new DjotToCarve())->convert($source);
        self::assertSame("<p>::: tip Bad X</p>\n<aside class=\"admonition note\" aria-label=\"Note\">\n  <p>x</p>\n</aside>\n<p>:::</p>", trim((new CarveConverter())->convert($migrated)));
    }

    public function testLineAndHardBreakFencesKeepTheirOwnMigratedCloser(): void
    {
        foreach (['::: |', '::: \\'] as $opener) {
            $source = "::: tip Bad X\n{$opener}\nl\n:::\nout\n:::\n";
            $migrated = (new DjotToCarve())->convert($source);
            self::assertSame("\\::: tip Bad X\n{$opener}\nl\n:::\nout\n\\:::\n", $migrated);
        }
    }

    public function testFootnoteMarkerOpenerIsDiagnosedAndFormattingRequiresReview(): void
    {
        $source = "a[^1]\n\n[^1]: ::: tip Bad X\n    body\n    :::\n";
        $warnings = array_values(array_filter((new SourceLinter())->lint($source), static fn ($w) => $w->rule === 'fence-title-syntax'));
        self::assertCount(1, $warnings);
        self::assertSame(3, $warnings[0]->line);
        self::assertSame('::: tip Bad X', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
        self::assertSame('invalid-container-metadata', CarveConverter::toCarvePatch($source)->unresolved[0]->code);
    }
}
