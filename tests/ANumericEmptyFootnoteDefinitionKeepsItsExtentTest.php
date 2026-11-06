<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ANumericEmptyFootnoteDefinitionKeepsItsExtentTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function numericLabels(): array
    {
        return [
            'zero' => ['0'],
            'one' => ['1'],
            'multiple digits' => ['12'],
        ];
    }

    #[DataProvider('numericLabels')]
    public function testAnEmptyDefinitionWithANumericLabelRenders(string $label): void
    {
        $source = "a[^{$label}]\n\n[^{$label}]: {empty}\n";
        $expected = <<<'HTML'
<p>a<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
