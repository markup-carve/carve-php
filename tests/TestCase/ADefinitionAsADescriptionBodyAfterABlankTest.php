<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * §10 I5: a definition written at a definition body's content column is an
 * interrupter AND it registers. The parser already read it that way - the `dd`
 * comes back empty because the line is an invisible one - but the extractor
 * stripped the `: ` description marker only when the line ABOVE was a term or
 * another description. A blank line between entries does not end a definition
 * list, it only makes it loose, so after one the marker went unstripped, the
 * definition was consumed by the parser and collected by nobody, and the
 * reference below it died (carve-php#1843).
 *
 * The adjacent spelling always worked, so the blank line was the whole
 * difference.
 */
class ADefinitionAsADescriptionBodyAfterABlankTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testADescriptionBodyDefinitionRegistersAcrossABlank(): void
    {
        $html = $this->converter->convert(":: term\n:  def\n\n:  [r]: /url\n\n[r][]\n");

        $this->assertStringContainsString('href="/url"', $html);
        $this->assertStringNotContainsString('[r]: /url', $html);
    }

    public function testTheAdjacentSpellingIsUnchanged(): void
    {
        $html = $this->converter->convert(":: term\n:  def\n:  [r]: /url\n\n[r][]\n");

        $this->assertStringContainsString('href="/url"', $html);
    }

    public function testItRegistersWhenTheDescriptionIsTheOnlyOne(): void
    {
        $html = $this->converter->convert(":: term\n\n:  [r]: /url\n\n[r][]\n");

        $this->assertStringContainsString('href="/url"', $html);
    }

    /**
     * The blank is only transparent WHILE the list is open. Prose between the
     * entries ends it, and the `: ` line below is then ordinary paragraph text
     * that registers nothing - which is what the previous non-blank line says.
     */
    public function testProseBetweenTheEntriesEndsTheListAndRefuses(): void
    {
        $html = $this->converter->convert(":: term\n:  def\n\npara\n\n:  [r]: /url\n\n[r][]\n");

        $this->assertStringNotContainsString('href="/url"', $html);
        $this->assertStringContainsString(':  [r]: /url', $html);
    }

    public function testAHeadingBetweenTheEntriesEndsItToo(): void
    {
        $html = $this->converter->convert(":: term\n:  def\n\n# h\n\n:  [r]: /url\n\n[r][]\n");

        $this->assertStringNotContainsString('href="/url"', $html);
    }

    public function testADescriptionMarkerWithNoListAboveRegistersNothing(): void
    {
        $html = $this->converter->convert("para\n\n:  [r]: /url\n\n[r][]\n");

        $this->assertStringNotContainsString('href="/url"', $html);
    }
}
