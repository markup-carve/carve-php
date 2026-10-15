<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P0-023: ill-formed UTF-8 becomes U+FFFD, one per maximal subpart, so
 * an importer never writes Carve that is not well-formed.
 */
class ImportersReplaceIllFormedInputTest extends TestCase
{
    /**
     * @return array<string, array{object}>
     */
    public static function importers(): array
    {
        return [
            'markdown' => [new MarkdownToCarve()],
            'djot' => [new DjotToCarve()],
            'bbcode' => [new BbcodeToCarve()],
        ];
    }

    #[DataProvider('importers')]
    public function testConvertReplacesIllFormedBytes(MarkdownToCarve|DjotToCarve|BbcodeToCarve $importer): void
    {
        $this->assertSame("a\u{FFFD}b \u{FFFD}\u{FFFD}\u{FFFD}c\n", $importer->convert("a\xC3b \xED\xA0\x80c\n"));
    }

    #[DataProvider('importers')]
    public function testFidelityReportReplacesIllFormedBytes(MarkdownToCarve|DjotToCarve|BbcodeToCarve $importer): void
    {
        $this->assertSame("a\u{FFFD}b\n", $importer->convertWithFidelityReport("a\xE2\x82b\n")->value);
    }
}
