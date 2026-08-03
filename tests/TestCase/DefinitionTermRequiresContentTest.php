<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9's MARKER REQUIRES CONTENT rule, applied to the definition-term marker.
 *
 * The rule, paraphrased rather than quoted because it spells its examples with
 * runs of spaces that the code sniffer collapses - and collapsing them would
 * turn the example into its own opposite: a marker line with no content is
 * paragraph text rather than a list, whether it is bare or carries trailing
 * whitespace only. Trailing whitespace is ignored, so however many spaces
 * follow the marker it behaves the same, and an editor stripping them cannot
 * change the meaning. Verbatim at `resources/grammar.ebnf`, MARKER REQUIRES
 * CONTENT.
 *
 * The rule named bullets and ordered markers; `::` is the sibling nobody
 * extended it to, and the engines split three ways (carve#512). Under the old
 * behavior here, `::` with ONE trailing space was a paragraph and `::` with TWO
 * was a definition list - so stripping a trailing space changed the document's
 * structure, which is the precise thing the rule exists to prevent.
 *
 * carve-rs already behaves this way.
 */
class DefinitionTermRequiresContentTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $html));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function contentLessMarkers(): array
    {
        return [
            'bare' => ["::\n"],
            'one trailing space' => [":: \n"],
            'two trailing spaces' => ["::  \n"],
            'three trailing spaces' => ["::   \n"],
            'space then tab' => [":: \t\n"],
            'tab only' => ["::\t\n"],
        ];
    }

    #[DataProvider('contentLessMarkers')]
    public function testAContentLessMarkerIsParagraphText(string $source): void
    {
        $this->assertSame('<p>::</p>', $this->squash($this->converter->convert($source)));
    }

    public function testStrippingATrailingSpaceCannotChangeTheStructure(): void
    {
        // The rule's own rationale. These three must be indistinguishable.
        $bare = $this->squash($this->converter->convert("::\n"));

        $this->assertSame($bare, $this->squash($this->converter->convert(":: \n")));
        $this->assertSame($bare, $this->squash($this->converter->convert("::  \n")));
    }

    public function testATermWithContentStillWorks(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd>d</dd> </dl>',
            $this->squash($this->converter->convert(":: t\n:  d\n")),
        );
    }

    public function testATermWithExtraSeparatorWhitespaceStillWorks(): void
    {
        $this->assertSame(
            '<dl> <dt>x</dt> </dl>',
            $this->squash($this->converter->convert("::  x\n")),
        );
    }
}
