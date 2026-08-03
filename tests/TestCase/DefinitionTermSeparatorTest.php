<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The separator after `::` is the SPACE character.
 *
 * `definition_term = "::", space, inline_content, newline` and `space = ' '`,
 * so `::` followed by a tab is ordinary paragraph text. Every other marker
 * whose separator the grammar specifies as a space already refused a tab here:
 * `-`, `1.`, `#`, `>`, `[a]:`, `[^a]:` and `*[A]:`. The definition term was the
 * last one accepting it, which made carve-rs - the only engine matching the
 * grammar - look like the outlier (carve#532).
 */
class DefinitionTermSeparatorTest extends TestCase
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

    public function testTabAfterTheMarkerIsParagraphText(): void
    {
        $html = $this->converter->convert("::\tterm\n:  d\n");

        $this->assertStringNotContainsString('<dl>', $html);
        $this->assertStringContainsString('<p>', $html);
    }

    public function testSpaceAfterTheMarkerStillOpensADefinitionList(): void
    {
        $html = $this->squash($this->converter->convert(":: term\n:  d\n"));

        $this->assertSame('<dl> <dt>term</dt> <dd>d</dd> </dl>', $html);
    }

    public function testMoreThanOneSpaceIsStillATerm(): void
    {
        $html = $this->squash($this->converter->convert(":: term\n:  d\n"));

        $this->assertStringContainsString('<dt>term</dt>', $html);
    }

    public function testTabAfterTheMarkerInsideAListItemIsAlsoRefused(): void
    {
        // The nested path runs its own copy of the decision, so a fix reaching
        // only the top level would leave the tab opening a list here.
        $html = $this->converter->convert("- item\n\n  ::\tterm\n  :  d\n");

        $this->assertStringNotContainsString('<dl>', $html);
    }

    public function testATabAfterTheTermEndsTheTermRatherThanContinuingIt(): void
    {
        // A term line followed by a tab-led line: the tab line is not a term of
        // its own, whatever else it is.
        $html = $this->converter->convert(":: term\n::\tsecond\n:  d\n");

        $this->assertStringNotContainsString('<dt>second</dt>', $html);
    }
}
