<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * `<ins>` has had its own marker for a while, and its `<del>` twin has always
 * had one. Only the diagnostic list had not caught up: `ins` was missing from
 * the elements the importer knows, so every import of one reported
 * `element-unwrapped` - a loss that does not happen. A false diagnostic is as
 * expensive as a missing one, because a report nobody trusts stops being read.
 */
class HtmlImportInsertDiagnosticTest extends TestCase
{
    protected HtmlToCarve $converter;

    protected CarveConverter $carve;

    protected function setUp(): void
    {
        $this->converter = new HtmlToCarve();
        $this->carve = new CarveConverter();
    }

    /**
     * @return list<string>
     */
    protected function diagnosticCodes(string $html): array
    {
        return array_map(
            static fn ($diagnostic): string => $diagnostic->code,
            (new HtmlToCarve())->convertWithReport($html)->diagnostics,
        );
    }

    public function testInsertIsMappedNotUnwrapped(): void
    {
        $carve = $this->converter->convert('<p>a <ins>added</ins> b</p>');

        $this->assertSame("a {+added+} b\n", $carve);
        $this->assertSame("<p>a <ins>added</ins> b</p>\n", $this->carve->convert($carve));
    }

    public function testInsertReportsNoUnwrappingLoss(): void
    {
        $this->assertNotContains('element-unwrapped', $this->diagnosticCodes('<p>a <ins>added</ins> b</p>'));
    }

    /**
     * CONTROL. The twin already reported nothing, and still does - the two
     * sides of the pair have to answer the same way.
     */
    public function testDeleteStillReportsNoUnwrappingLoss(): void
    {
        $this->assertSame("a {-cut-} b\n", $this->converter->convert('<p>a <del>cut</del> b</p>'));
        $this->assertNotContains('element-unwrapped', $this->diagnosticCodes('<p>a <del>cut</del> b</p>'));
    }

    /**
     * CONTROL. An element that really is unwrapped still says so, so the fix
     * narrowed the report rather than silencing it.
     */
    public function testAnActuallyUnwrappedElementStillReportsIt(): void
    {
        $this->assertContains('element-unwrapped', $this->diagnosticCodes('<p>a <bdi>text</bdi> b</p>'));
    }
}
