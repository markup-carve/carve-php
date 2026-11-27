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
}
