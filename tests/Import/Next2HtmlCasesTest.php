<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\TestCase;

final class Next2HtmlCasesTest extends TestCase
{
    public function testUniformVerticalAlignmentIsWrittenFromItsColumn(): void
    {
        $result = Carve::importHtml('<table><tr><td style="vertical-align: top;">x</td></tr></table>');
        self::assertSame("{valigns=top}\n| x |\n", $result->source);
    }

    public function testPinnedHtmlImportExamples(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/examples.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $ids = [
            'import-html-49', 'import-html-50', 'import-html-51',
            'import-html-52', 'import-html-53', 'import-html-54',
            'import-html-55', 'import-html-58', 'import-html-59',
        ];
        $seen = [];
        foreach ($spec['examples'] as $case) {
            if (!in_array($case['id'], $ids, true)) {
                continue;
            }
            $seen[] = $case['id'];
            $result = Carve::importHtml($case['html']);
            self::assertSame($case['expected']['carve'], $result->source, $case['id'] . ' source');
            self::assertSame(
                Corpus::sorted($case['expected']['children']),
                Corpus::sorted($result->document['children']),
                $case['id'] . ' tree',
            );
            foreach ($case['expected']['diagnostics'] ?? [] as $code) {
                self::assertContains($code, $result->codes(), $case['id'] . ' diagnostic');
            }
        }
        self::assertCount(count($ids), $seen);
    }
}
