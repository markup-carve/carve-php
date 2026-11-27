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
}
