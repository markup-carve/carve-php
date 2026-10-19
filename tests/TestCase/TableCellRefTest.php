<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Lint\ReferenceLinter;
use PHPUnit\Framework\TestCase;

final class TableCellRefTest extends TestCase
{
    public function testCsvAndPipeTablesProjectIntoProseAndCode(): void
    {
        $converter = new CarveConverter();
        $csv = "{table #sales}\n```csv\nQuarter,Revenue\nQ1,120\nQ2,150\n```\n\nQ2: @{#sales[\"Q2\"][\"Revenue\"]}{code}.";
        self::assertStringContainsString('<p>Q2: <code>150</code>.</p>', $converter->convert($csv));

        $pipe = "{#sales}\n|= Quarter |= Revenue |\n| Q1 | 120 |\n| Q2 | 150 |\n\nNamed @{#sales[\"Q2\"][\"Revenue\"]}; numbered @{#sales[2][2]}.";
        self::assertStringContainsString('<p>Named 150; numbered 150.</p>', $converter->convert($pipe));
    }

    public function testRowKeyAndMissingReference(): void
    {
        $source = "{table #sales row-key=Quarter}\n```csv\nRevenue,Quarter\n120,Q1\n150,Q2\n```\n\n@{#sales[\"Q2\"][\"Revenue\"]} @{#sales[\"Q3\"][\"Revenue\"]}";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString('<p>150 @{#sales["Q3"]["Revenue"]}</p>', $html);
        self::assertStringNotContainsString('row-key=', $html);
    }

    public function testWireKeepsSelectorsAndRejectsSourceMismatch(): void
    {
        $converter = new CarveConverter();
        $source = "{#s}\n|= K |= V |\n| Q2 | 150 |\n\n@{#s[\"Q2\"][\"V\"]}";
        $codec = new AstCodec();
        $doc = $converter->parse($source);
        $converter->render($doc);
        $ast = $codec->encode($doc);
        self::assertSame('table_cell_ref', $ast['children'][1]['children'][0]['type']);
        self::assertSame('150', $ast['children'][1]['children'][0]['value']);
        self::assertStringContainsString('<p>150</p>', $converter->render($codec->decode($ast)));
        $ast['children'][1]['children'][0]['row'] = 'Q1';
        $this->expectException(AstDecodeException::class);
        $codec->decode($ast);
    }

    public function testDuplicateIdsAndIntermediateHeadersDoNotResolve(): void
    {
        $converter = new CarveConverter();
        $duplicate = "{#s}\n|= K |= V |\n| Q1 | 111 |\n\n{#s}\n|= K |= V |\n| Q2 | 222 |\n\n@{#s[1][2]}";
        self::assertStringContainsString('<p>@{#s[1][2]}</p>', $converter->convert($duplicate));
        $grouped = "{#s}\n|= K |= V |\n| Q1 | 111 |\n|= Group |= Header |\n| Q2 | 222 |\n\n@{#s[2][2]} @{#s[3][2]}";
        self::assertStringContainsString('<p>222 @{#s[3][2]}</p>', $converter->convert($grouped));
        self::assertContains('unresolved-table-cell-ref', array_column((new ReferenceLinter())->lint($grouped), 'rule'));
    }

    public function testMalformedAndUnresolvedReferencesHaveDiagnostics(): void
    {
        $source = "{#s}\n|= K |= V |\n| Q2 | 150 |\n\n@{#s[0][2]} @{#s[\"Q3\"][\"V\"]}";
        $warnings = (new ReferenceLinter())->lint($source);
        self::assertContains('malformed-table-cell-ref', array_column($warnings, 'rule'));
        self::assertContains('unresolved-table-cell-ref', array_column($warnings, 'rule'));
        self::assertStringContainsString('row key is absent', implode(' ', array_column($warnings, 'message')));
        self::assertContains('malformed-table-cell-ref', array_column((new ReferenceLinter())->lint('@{#s[99999999999999999999][2]}'), 'rule'));
        $quotedBrace = '@{#s["a}b"][0]}';
        $quotedWarning = (new ReferenceLinter())->lint($quotedBrace);
        self::assertSame($quotedBrace, substr($quotedBrace, $quotedWarning[0]->start, $quotedWarning[0]->end - $quotedWarning[0]->start));
        $unclosedQuote = "See @{#t[\"Q2][1] and *this*\nmore \"text\" here}";
        self::assertStringContainsString('<strong>this</strong>', (new CarveConverter())->convert($unclosedQuote));
    }
}
