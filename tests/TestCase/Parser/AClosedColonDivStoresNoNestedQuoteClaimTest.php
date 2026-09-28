<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A closed colon div two or more quotes deep stores no continuation claim.
 *
 * PART 0 owner selection: "A blank, heading, definition, comment, table or
 * closed fence establishes no such claim." A colon div closed inside the inner
 * quote claims nothing, so nothing in the OUTER quote can absorb the unmarked
 * line below it and the line belongs to the document
 * (markup-carve/carve#2516, ruled and pinned in the corpus by
 * markup-carve/carve#2524, reported as carve-php#2664).
 *
 * Every row is the oracle's reading, `scripts/spec/layout.mjs` with
 * `scripts/spec/html.mjs`, run at markup-carve/carve 66d4ed19. The first four
 * are the corpus documents carve#2524 appended to section 511 byte for byte;
 * they cannot run from `tests/spec` yet because this repo's pin predates that
 * commit, so they are carried here as carve-php#2655 and carve-php#2660 carried
 * theirs.
 *
 * THE CONTROLS ARE THE POINT of the other six. A shallower closer, a wider
 * closer and an unterminated div all leave the div open, and the claim then
 * stands - so a change that simply stopped claiming would pass the four rows
 * above and fail these.
 */
class AClosedColonDivStoresNoNestedQuoteClaimTest extends TestCase
{
    public function testTheUnmarkedLineLandsWhereTheOracleLeavesIt(): void
    {
        /** @var list<array{name:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/nested-quote-colon-div-claim.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(10, $rows);
        $converter = new CarveConverter();
        $failures = [];
        foreach ($rows as $row) {
            $actual = trim($converter->convert($row['source']));
            if ($actual !== $row['html']) {
                $failures[] = $row['name'] . "\n" . $actual;
            }
        }
        $this->assertSame([], $failures);
    }
}
