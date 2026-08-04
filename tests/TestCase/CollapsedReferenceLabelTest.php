<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A collapsed reference `[label][]` is matched by the label the author wrote.
 *
 * This engine used to STRIP inline formatting characters from the bracket text
 * before looking the definition up, which inverted the rule: a definition
 * carrying any of `_ * ~ ^ + = { } [ ] ` ` could not be reached by the label
 * that defined it, while a plain definition WAS reached by a decorated label
 * that never named it. Every collapsed reference with markup in the label was
 * affected, not only the caret shape that surfaced it (carve-php#768).
 *
 * carve-rs and carve-js both match on the written label.
 *
 * The one exception is a HEADING-derived definition (PART 11 R1): it is keyed
 * by the heading's text, so a label with markup gets one retry without it -
 * matching carve-rs. carve-js resolves neither of those, which is
 * markup-carve/carve#648.
 */
class CollapsedReferenceLabelTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function labelProvider(): array
    {
        return [
            'caret' => ['^', '^'],
            'underscore' => ['a_b', 'a_b'],
            'asterisk' => ['a*b', 'a*b'],
            'tilde' => ['a~b', 'a~b'],
            'plus' => ['a+b', 'a+b'],
            'equals' => ['a=b', 'a=b'],
            'brace' => ['a{b', 'a{b'],
        ];
    }

    #[DataProvider('labelProvider')]
    public function testACollapsedReferenceResolvesOnTheWrittenLabel(string $label, string $rendered): void
    {
        $html = $this->converter->convert("[{$label}]: /x\n\nsee [{$label}][]\n");

        $this->assertSame("<p>see <a href=\"/x\">{$rendered}</a></p>\n", $html);
    }

    public function testAnEmphasizedLabelResolvesAndKeepsItsEmphasis(): void
    {
        $html = $this->converter->convert("[*bold*]: /x\n\nsee [*bold*][]\n");

        $this->assertSame("<p>see <a href=\"/x\"><strong>bold</strong></a></p>\n", $html);
    }

    public function testADecoratedLabelDoesNotReachAPlainDefinition(): void
    {
        // The inverse of the bug: `[bold]: /x` is not what `[*bold*][]` names.
        // carve-js and carve-rs leave this literal too.
        $html = $this->converter->convert("[bold]: /x\n\nsee [*bold*][]\n");

        $this->assertSame("<p>see [*bold*][]</p>\n", $html);
    }

    public function testAHeadingReferenceStillResolvesThroughItsMarkup(): void
    {
        // A heading definition is keyed by the heading's TEXT, so this label
        // gets the one retry without markup (matching carve-rs).
        $html = $this->converter->convert("# *bold* heading\n\n[*bold* heading][]\n");

        $this->assertStringContainsString('href="#bold-heading"', $html);
    }
}
