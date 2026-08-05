<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An all-digit reference label does not kill the render (carve-php#881).
 *
 * PHP turns an all-digit ARRAY KEY into an int. A reference label is any inline
 * text, so `[5]: /u` keys the definition map with `5` rather than `"5"`, and
 * `appendLinkReferenceDefinitions` handed that int to a constructor typed
 * `string`:
 *
 *     TypeError: LinkReferenceDefinition::__construct(): Argument #1 ($label)
 *     must be of type string, int given
 *
 * A fatal on an ordinary document - `[t][5]` with `[5]: /u` is nothing unusual,
 * and both other engines render it.
 *
 * Same coercion that broke a digit-only abbreviation term in #880, and the
 * attribute-name mapping in the very next statement already carried the
 * `strval` guard this one needed.
 */
class NumericReferenceLabelTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function numericLabels(): array
    {
        return [
            'single digit' => ['5'],
            'zero' => ['0'],
            'leading zeros' => ['007'],
            'multi digit' => ['42'],
            // The control: a label PHP does not coerce.
            'alphabetic' => ['a'],
            'alphanumeric' => ['a1'],
        ];
    }

    #[DataProvider('numericLabels')]
    public function testAReferenceWithThisLabelRenders(string $label): void
    {
        $html = (new CarveConverter())->convert("[t][{$label}]\n\n[{$label}]: /u\n");

        $this->assertStringContainsString('<a href="/u">t</a>', $html);
    }

    #[DataProvider('numericLabels')]
    public function testItSerializesToo(string $label): void
    {
        // The AST path builds the same definition node, so it takes the same
        // constructor and would have thrown the same way.
        $json = (new AstCodec())
            ->encodeJson((new CarveConverter())->parse("[t][{$label}]\n\n[{$label}]: /u\n"));

        $this->assertStringContainsString('link_reference_definition', $json);
        $this->assertStringContainsString('"label"', $json);
    }

    #[DataProvider('numericLabels')]
    public function testTheWriterReproducesIt(string $label): void
    {
        $converter = CarveConverter::create(
            renderer: new CarveRenderer(),
        );
        $out = $converter->convert("[t][{$label}]\n\n[{$label}]: /u\n");

        $this->assertStringContainsString("[{$label}]", $out);
    }

    public function testADigitOnlyAbbreviationTermStillWorks(): void
    {
        // The sibling case from #880, pinned here too - both are one coercion
        // and a fix for either could plausibly be written to cover only itself.
        $html = (new CarveConverter())->convert("*[9]: nine\n\n9 here\n");

        $this->assertStringContainsString('<abbr', $html);
    }
}
