<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\ReferenceLinter;
use PHPUnit\Framework\TestCase;

class ReferenceLinterTest extends TestCase
{
    public function testReportsUnresolvedReferencesAndDuplicateDefinitions(): void
    {
        $cases = [
            'duplicate-heading-id' => "# A\n\n# A\n",
            'broken-crossref' => "see </#nope>\n",
            'unresolved-reference-link' => "see [text][nope]\n",
            'unresolved-footnote' => "see[^nope]\n",
            'unused-footnote-definition' => "text\n\n[^a]: never used\n",
            'duplicate-footnote-definition' => "x[^a]\n\n[^a]: one\n\n[^a]: two\n",
            'footnote-labels-differ-only-in-whitespace' => "see [^a b]\n\n[^a b]: one\n\n[^a  b]: two\n",
        ];
        foreach ($cases as $rule => $source) {
            $warnings = (new ReferenceLinter())->lint($source);
            $this->assertContains($rule, array_column($warnings, 'rule'), $source);
        }
    }

    public function testResolvedReferencesDoNotProduceWarnings(): void
    {
        $source = "# Target\n\nsee </#Target> [Target][] [text][r] and [^a b]\n\n[r]: u\n\n[^a  b]: note\n";
        $this->assertSame([], (new ReferenceLinter())->lint($source));
    }

    public function testLiteralExamplesDoNotCountAsDuplicateDefinitions(): void
    {
        foreach (["```\n[^a]: sample\n```", "%%%\n[^a]: sample\n%%%", '\\[^a]: sample', '`[^a]: sample`'] as $body) {
            $source = "x[^a]\n\n[^a]: note\n\n{$body}\n";
            $this->assertNotContains('duplicate-footnote-definition', array_column((new ReferenceLinter())->lint($source), 'rule'));
        }
    }

    public function testUnicodeAndCrLfLocationsUseOriginalBytes(): void
    {
        $source = "😀\r\n\r\n> see </#missing>\r\n";
        $warnings = (new ReferenceLinter())->lint($source);
        $this->assertCount(1, $warnings);
        $this->assertSame(3, $warnings[0]->line);
        $this->assertSame('</#missing>', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }

    public function testRepeatedDefinitionsInQuotesKeepTheirLocation(): void
    {
        $source = "x[^a]\r\n\r\n> [^a]: one\r\n\r\n> [^a]: two\r\n";
        $warnings = (new ReferenceLinter())->lint($source);
        $this->assertCount(1, $warnings);
        $this->assertSame('duplicate-footnote-definition', $warnings[0]->rule);
        $this->assertSame(5, $warnings[0]->line);
        $this->assertSame(3, $warnings[0]->column);
        $this->assertSame('[^a]:', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }
}
