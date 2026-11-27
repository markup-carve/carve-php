<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Export;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Export\AnsiExporter;
use MarkupCarve\Carve\Export\MarkdownExporter;
use MarkupCarve\Carve\Export\PlainTextExporter;
use PHPUnit\Framework\TestCase;

final class DerivedTextBudgetExportTest extends TestCase
{
    public function testAnOversizedExpansionLeavesBudgetForLaterOccurrences(): void
    {
        $cases = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        )['cases'];

        foreach ($cases as $case) {
            $id = $case['id'];
            if ($id !== 'CC-590' && $id !== 'CC-1475') {
                continue;
            }
            $tree = $case['tree'] ?? Carve::parse($case['input']);
            $suffixes = $id === 'CC-590'
                ? ['markdown' => 'Z<abbr title="c">Y</abbr>X' . "\n", 'text' => 'ZY (c)X' . "\n"]
                : ['markdown' => ' B <abbr title="zzzzzzzzzz">C</abbr>' . "\n", 'text' => ' B C (zzzzzzzzzz)' . "\n"];

            $exporters = ['markdown' => new MarkdownExporter(), 'plain' => new PlainTextExporter(), 'ansi' => new AnsiExporter()];
            foreach ($exporters as $target => $exporter) {
                $result = $exporter->export($tree);
                $output = $target === 'ansi' ? (string)preg_replace('/\e\[[0-9;]*m/', '', $result->output) : $result->output;
                $suffix = $suffixes[$target === 'markdown' ? 'markdown' : 'text'];
                self::assertSame($suffix, substr($output, -strlen($suffix)), $id . ' ' . $target);
                self::assertSame(['derived-text-budget'], $result->codes(), $id . ' ' . $target);
            }
        }
    }
}
