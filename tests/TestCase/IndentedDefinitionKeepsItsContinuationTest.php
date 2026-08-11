<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A footnote definition at an item's content column keeps its continuation.
 *
 * PART 9 §16 extends a note body to following lines indented by >= 2 -
 * RELATIVE to the definition line. The column form was collected as
 * single-line, so a continuation under an indented definition went nowhere:
 * not into the item, not into the note, absent from the document
 * (carve-php#794). carve-js and carve-rs both keep it.
 *
 * carve-rs had the mirror defect - it measured from column 0 and swallowed a
 * container's `:::` closer into the note (carve-rs#591). The relative rule
 * fixes both directions.
 */
class IndentedDefinitionKeepsItsContinuationTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    protected function noteBody(string $source): string
    {
        $html = $this->converter->convert($source);
        $start = strpos($html, '<li id="fn1">');
        $this->assertNotFalse($start, 'no endnote in ' . $html);
        $end = strpos($html, '</li>', $start);

        return substr($html, $start, (int)$end - $start);
    }

    public function testAContinuationTwoColumnsPastTheDefinitionIsKept(): void
    {
        $this->assertStringContainsString('more', $this->noteBody("- a\n  [^f]: x\n    more\n\nsee[^f]\n"));
    }

    public function testTheTextIsNotLostFromTheDocument(): void
    {
        // The failure mode: `more` appeared nowhere at all.
        $html = $this->converter->convert("- a\n  [^f]: x\n    more\n\nsee[^f]\n");

        $this->assertStringContainsString('more', $html);
    }

    public function testALineAtTheDefinitionsOwnColumnIsNotContinuation(): void
    {
        $this->assertStringNotContainsString('more', $this->noteBody("- a\n  [^f]: x\n  more\n\nsee[^f]\n"));
    }

    public function testATopLevelDefinitionIsUnchanged(): void
    {
        $this->assertStringContainsString('more', $this->noteBody("[^f]: x\n  more\n\nsee[^f]\n"));
    }
}
