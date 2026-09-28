<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use LogicException;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class AstImportDoesNotWriteSourceTest extends TestCase
{
    public function testAstImportDoesNotCallTheSourceExit(): void
    {
        $converter = new class extends HtmlToCarve {
            public function convert(string $html): string
            {
                throw new LogicException('The source writer must not run.');
            }
        };
        $result = $converter->convertToAstWithReport('<p id="kept"><ruby>x<rt>a</rt></ruby></p>');

        $this->assertSame('ruby', $result->value['children'][0]['children'][0]['type']);
        $this->assertSame('kept', $result->value['children'][0]['attrs']['id']);
        $this->assertSame([], $result->diagnostics);
    }

    public function testSourceOnlyLossDoesNotSpendTheAstDiagnosticBudget(): void
    {
        $converter = new HtmlToCarve(maxDiagnostics: 1);
        $result = $converter->convertToAstWithReport('<p><ruby>x<rt>a</rt></ruby></p><p onclick="x()">y</p>');

        $this->assertCount(1, $result->diagnostics);
        $this->assertSame('attribute-dropped', $result->diagnostics[0]->code);
        $this->assertSame('/p[2]', $result->diagnostics[0]->path);
    }

    public function testAReusedConverterObservesTheCurrentExit(): void
    {
        $converter = new HtmlToCarve();
        $html = '<p><ruby>x<rt>a</rt></ruby></p>';
        $this->assertSame([], $converter->convertToAstWithReport($html)->diagnostics);
        $this->assertSame('structure-unspellable', $converter->convertWithReport($html)->diagnostics[0]->code);
        $this->assertSame([], $converter->convertToAstWithReport($html)->diagnostics);
    }

    public function testAThrowingInspectorDoesNotLeakTheAstTarget(): void
    {
        $converter = new class extends HtmlToCarve {
            private bool $fail = true;

            protected function inspectImportLoss(string $html): array
            {
                if ($this->fail) {
                    $this->fail = false;

                    throw new LogicException('Inspection failed.');
                }

                return parent::inspectImportLoss($html);
            }
        };
        try {
            $converter->convertToAstWithReport('<p>first</p>');
            $this->fail('The inspector must throw.');
        } catch (LogicException $exception) {
            $this->assertSame('Inspection failed.', $exception->getMessage());
        }
        $result = $converter->convertWithReport('<p><ruby>x<rt>a</rt></ruby></p>');

        $this->assertSame('structure-unspellable', $result->diagnostics[0]->code);
    }
}
