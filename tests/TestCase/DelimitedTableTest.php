<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\DelimitedTableLinter;
use PHPUnit\Framework\TestCase;

class DelimitedTableTest extends TestCase
{
    public function testCaptionedCsvUsesTheTablePathAndKeepsItsSourceForm(): void
    {
        $source = "{table aligns=left,right,right}\n```csv\nItem,Qty,Price\nApple,12,0.40\n\"Pear, green\",3,0.55\n```\n^ Stock on hand\n";
        $converter = new CarveConverter();
        $html = $converter->convert($source);
        self::assertStringContainsString('<caption>Stock on hand</caption>', $html);
        self::assertStringContainsString('>Pear, green</td>', $html);
        self::assertStringContainsString('<th scope="col"', $html);
        $ast = (new AstCodec())->encode($converter->parse($source));
        self::assertSame('table', $ast['children'][0]['type']);
        self::assertSame('csv', $ast['children'][0]['sourceFormat']);
        $written = CarveConverter::toCarve($source);
        self::assertStringContainsString('```csv', $written);
        self::assertSame($html, $converter->convert($written));
    }

    public function testTsvFieldsStayLiteralAndHeaderCanBeDisabled(): void
    {
        $source = "{table header-rows=0}\n```tsv\nA\tB\n^\t<x>\n```\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString('<td>^</td><td>&lt;x&gt;</td>', $html);
        self::assertStringNotContainsString('<th', $html);
        self::assertSame($html, (new CarveConverter())->convert(CarveConverter::toCarve($source)));
    }

    public function testQuotedNewlineStaysInsideOneCell(): void
    {
        $source = "{table}\n```csv\nName,Note\nA,\"one\ntwo\"\n```\n";
        self::assertStringContainsString("<td>one\ntwo</td>", (new CarveConverter())->convert($source));
    }

    public function testUnmarkedAndMalformedFencesStayCode(): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $plain = $codec->encode($converter->parse("```csv\na,b\n```\n"));
        $malformed = $codec->encode($converter->parse("{table}\n```csv\n\"bad\n```\n"));
        self::assertSame('code_block', $plain['children'][0]['type']);
        self::assertSame('code_block', $malformed['children'][0]['type']);
    }

    public function testLintReportsMalformedQuotesAndWideRecords(): void
    {
        $linter = new DelimitedTableLinter();
        $malformed = $linter->lint("{table}\n```csv\n\"bad\n```\n");
        $wide = $linter->lint("{table}\n```csv\na,b\n1\n2,3,4\n```\n");
        self::assertContains('delimited-table-malformed', array_map(static fn ($warning): string => $warning->rule, $malformed));
        self::assertContains('delimited-table-wide-row', array_map(static fn ($warning): string => $warning->rule, $wide));
        self::assertSame([], $linter->lint("{table}\n```csv\na,b\n1,2\n```\n"));
    }

    public function testImplicitHeaderWithFooterSurvivesFormatting(): void
    {
        $source = "{table footer-rows=1}\n```csv\nItem,Qty\nApple,2\nTotal,2\n```\n";
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $written = CarveConverter::toCarve($source);
        self::assertStringNotContainsString('header-rows=', $written);
        $before = $codec->encode($converter->parse($source));
        $after = $codec->encode($converter->parse($written));
        unset($before['srcByteLength'], $after['srcByteLength']);
        self::assertSame($before, $after);
    }

    public function testWideRecordInsideQuoteUsesParsedFields(): void
    {
        $source = "> {table}\n> ```csv\n> \"x,y\",b\n> 1,2,3\n> ```\n";
        $warnings = (new DelimitedTableLinter())->lint($source);
        self::assertContains('delimited-table-wide-row', array_map(static fn ($warning): string => $warning->rule, $warnings));
    }

    public function testBlankRecordsAndInvalidHeaderCountSurviveFormatting(): void
    {
        $converter = new CarveConverter();
        $sources = [
            "{table}\n```csv\na\n\n\nb\n```\n",
            "{table header-rows=x}\n```csv\na,b\n1,2\n```\n",
        ];
        foreach ($sources as $source) {
            self::assertSame($converter->convert($source), $converter->convert(CarveConverter::toCarve($source)));
        }
    }
}
