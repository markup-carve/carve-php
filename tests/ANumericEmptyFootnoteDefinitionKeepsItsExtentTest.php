<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
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

    public function testNormalizedLabelStillMapsToItsOpeningLine(): void
    {
        $source = "a[^a  b]\n\n[^a  b]: {empty}\n  \n";
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $ast = (new AstCodec())->encode($converter->parse($source));
        $footnotes = array_values(array_filter(
            $ast['children'],
            static fn (array $node): bool => $node['type'] === 'footnote',
        ));

        self::assertCount(1, $footnotes);
        self::assertSame(3, $footnotes[0]['pos']['startLine']);
        self::assertSame(3, $footnotes[0]['pos']['endLine']);
        self::assertSame(10, $footnotes[0]['pos']['startOffset']);
        self::assertSame(26, $footnotes[0]['pos']['endOffset']);
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
