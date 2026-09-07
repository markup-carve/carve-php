<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A nested footnote definition is an invisible inner container. PART 9 §16 puts
 * its body two columns past the definition, so a block opener at that column -
 * a list marker, heading, quote or nested definition - is note content, and an
 * unreferenced note renders nothing. In a description body the earlier
 * inner-container fixes (carve-php#1892, carve-php#1898) reached a quote and a
 * div but not a nested footnote definition, so its body block was flattened to
 * the host and published as a visible block in the `dd` (carve-php#1907).
 *
 * The knife-edge is the column: a block opener ONE column past the definition
 * stays below the body column, so the host keeps it (carve#1957). The col-3
 * control pins that the fix does not over-reach.
 *
 * carve-js and carve-rs both absorb the body block into the note; the rows here
 * are the geometries where they agree and this engine was the outlier.
 */
class ANestedFootnoteDefinitionOwnsItsBlockBodyInADescriptionTest extends TestCase
{
    protected function html(string $source): string
    {
        return trim(CarveConverter::create()->convert($source));
    }

    /**
     * The note body reaches column 4 (the definition sits at the dd content
     * column 2, its body two past). A block opener there is the note's, so the
     * note - never referenced - renders nothing and the description stays tight.
     *
     * @return array<string, array{string}>
     */
    public static function absorbedPayloads(): array
    {
        $cases = [];
        foreach (['item' => '- z', 'head' => '# h', 'quote' => '> z', 'definition' => ':: d'] as $name => $payload) {
            foreach ([4, 5] as $col) {
                $pad = str_repeat(' ', $col);
                $cases["{$name} at column {$col}"] = [":: t\n: body\n\n  [^g]: n\n{$pad}{$payload}\n\nafter\n"];
            }
        }

        return $cases;
    }

    #[DataProvider('absorbedPayloads')]
    public function testBlockBodyAtTheNoteColumnRendersNothing(string $source): void
    {
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<p>after</p>";
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * One column short of the body column the marker is the description's own
     * next block, so the sublist is visible and the item is loose (carve#1957).
     */
    public function testAMarkerBelowTheNoteColumnStaysInTheDescription(): void
    {
        $source = ":: t\n: body\n\n  [^g]: n\n   - z\n\nafter\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <ul>\n      <li>z</li>\n    </ul>\n  </dd>\n</dl>\n<p>after</p>";
        $this->assertSame($expected, $this->html($source));
    }
}
