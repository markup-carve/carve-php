<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Import\HtmlImporter;
use PHPUnit\Framework\TestCase;

final class ImportRangeI02Test extends TestCase
{
    public function testDiagnosticRangeCoversWholeElement(): void
    {
        $html = '<!doctype html><html><head><title>T</title></head><body><p>x</p></body></html>';
        $result = HtmlImporter::import($html);
        $head = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->foreignType === 'head'));
        self::assertCount(1, $head);
        self::assertSame([21, 50], [$head[0]->startOffset, $head[0]->endOffset]);
    }

    public function testNonVoidHtmlSelfClosingSpellingStillNeedsClosingTag(): void
    {
        $html = '<div onclick="x"/>text</div>';
        $result = HtmlImporter::import($html);
        $drop = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'import-attribute-dropped'));
        self::assertCount(1, $drop);
        self::assertSame([0, strlen($html)], [$drop[0]->startOffset, $drop[0]->endOffset]);
    }

    public function testImplicitParagraphCloseBoundsItsRangeBeforeNextSibling(): void
    {
        $html = '<p onclick=x>one<div>two</div></p>';
        $result = HtmlImporter::import($html);
        $drop = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'import-attribute-dropped'));
        self::assertCount(1, $drop);
        self::assertSame([0, strpos($html, '<div>')], [$drop[0]->startOffset, $drop[0]->endOffset]);
    }

    public function testElementWithoutCloserExtendsThroughEndOfInput(): void
    {
        $html = '<p onclick=x>hello';
        $result = HtmlImporter::import($html);
        $drop = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'import-attribute-dropped'));
        self::assertCount(1, $drop);
        self::assertSame([0, strlen($html)], [$drop[0]->startOffset, $drop[0]->endOffset]);
    }

    public function testImplicitChildCloseStopsAtParentClosingTag(): void
    {
        $html = '<p><span onclick=x>hello</p>';
        $result = HtmlImporter::import($html);
        $drop = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'import-attribute-dropped'));
        self::assertCount(1, $drop);
        self::assertSame([3, strpos($html, '</p>')], [$drop[0]->startOffset, $drop[0]->endOffset]);
    }

    public function testPlaintextApparentCloserIsTextAndRangeRunsToEnd(): void
    {
        $html = '<plaintext onclick=x>one</plaintext>two';
        $result = HtmlImporter::import($html);
        $ranged = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->foreignType === 'plaintext'));
        self::assertNotEmpty($ranged);
        foreach ($ranged as $entry) {
            self::assertSame([0, strlen($html)], [$entry->startOffset, $entry->endOffset]);
        }
    }

    public function testForeignSelfClosingAndLegacyVoidRangesEndAtOpeningTag(): void
    {
        foreach (['<svg/>tail', '<math onclick=x/>tail', '<keygen onclick=x>tail', '<basefont onclick=x>tail'] as $html) {
            $result = HtmlImporter::import($html);
            $tagEnd = strpos($html, '>') + 1;
            $ranged = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->startOffset === 0));
            self::assertNotEmpty($ranged, $html);
            foreach ($ranged as $entry) {
                self::assertSame($tagEnd, $entry->endOffset, $html);
            }
        }
    }

    public function testTemplateContentTokensDoNotShiftFollowingSiblingRanges(): void
    {
        $html = '<template onclick=x><p>hi</p></template><p>tail</p>';
        foreach ([false, true] as $legacy) {
            $result = HtmlImporter::import($html, [], $legacy);
            $drop = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->foreignType === 'template'));
            self::assertCount(1, $drop);
            self::assertSame([0, strpos($html, '<p>tail')], [$drop[0]->startOffset, $drop[0]->endOffset]);
        }
    }

    public function testRawTextEndTagAllowsTrailingAttributes(): void
    {
        $html = '<script onclick=x>foo</script foo><p onclick=y>bar</p>';
        foreach ([false, true] as $legacy) {
            $result = HtmlImporter::import($html, [], $legacy);
            $script = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->foreignType === 'script'));
            $paragraph = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->foreignType === 'p'));
            self::assertCount(1, $script);
            self::assertCount(1, $paragraph);
            self::assertSame([0, strpos($html, '<p onclick=y>')], [$script[0]->startOffset, $script[0]->endOffset]);
            self::assertSame([strpos($html, '<p onclick=y>'), strlen($html)], [$paragraph[0]->startOffset, $paragraph[0]->endOffset]);
        }
    }
}
