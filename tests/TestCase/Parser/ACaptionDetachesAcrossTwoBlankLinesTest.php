<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `caption_slot = [blank_line], caption` CARRIES ONE OPTIONAL BLANK LINE.
 *
 * PART 9 §4 spells the same allowance in words: a caption attaches to the
 * immediately preceding captionable block when it is adjacent or separated by
 * exactly one blank line, and TWO BLANK LINES DETACH, leaving the `^ ` line an
 * ordinary paragraph. This engine attached across any number of blank lines
 * (markup-carve/carve-php#1078).
 *
 * TWO OF THREE ENGINES PRODUCING THE ATTACHING ANSWER DOES NOT MAKE IT RIGHT.
 * The repository's rule that lets two agreeing engines pin a behavior applies
 * where a clause reads two ways; here the clause is explicit and the grammar
 * production is structural, so counting engines would have pinned the defect.
 * carve-js `62e0e5a` is the one that detaches and is the oracle for every row.
 *
 * WHY NOTHING CAUGHT IT: widening the slot to accept any number of blank lines
 * kills nothing in the whole corpus, because every caption document in it is
 * written with zero or one. The rows below are what distinguishes the two.
 */
class ACaptionDetachesAcrossTwoBlankLinesTest extends TestCase
{
    /**
     * The five captionable hosts of PART 9 §4.
     *
     * All five diverged and all five are fixed by ONE predicate, which is the
     * measurement worth recording: the blank-line run is skipped uncounted at
     * the top of the block loop, so no host parser can see it and the distance
     * has to be recovered once, where the caption is read. A sibling engine's
     * fence-boundary rule needed eight sites and the whitespace rule needed
     * fifteen; this one needs one, and the grid below is how that is known
     * rather than assumed.
     *
     * @return array<string, array{0: string}>
     */
    public static function captionableHosts(): array
    {
        return [
            'table' => ["| a | b |\n|---|---|\n| 1 | 2 |"],
            'fenced code block' => ["```\nx\n```"],
            'blockquote' => ['> q'],
            'image paragraph' => ['![alt](i.png)'],
            'standalone display math' => ['$$`E = mc^2`'],
        ];
    }

    protected function captionCount(string $source): int
    {
        return (int)preg_match_all(
            '/<(fig)?caption[ >]/',
            (new CarveConverter())->convert($source),
        );
    }

    #[DataProvider('captionableHosts')]
    public function testTwoBlankLinesDetachTheCaption(string $host): void
    {
        // THE ROW THAT PROVES THE FIX.
        $this->assertSame(0, $this->captionCount($host . "\n\n\n^ cap\n"));
    }

    #[DataProvider('captionableHosts')]
    public function testThreeBlankLinesDetachTheCaptionToo(string $host): void
    {
        // The allowance is ONE, not "an even number" and not "fewer than
        // three". A predicate testing only the line directly above the caption
        // passes the two-blank row by accident and fails here.
        $this->assertSame(0, $this->captionCount($host . "\n\n\n\n^ cap\n"));
    }

    #[DataProvider('captionableHosts')]
    public function testOneBlankLineStillAttaches(string $host): void
    {
        // A CONTROL. It passes today, it passed before the fix, and no mutation
        // of this defect moves it - it is here so a change that simply stopped
        // attaching captions could not be mistaken for a fix.
        $this->assertSame(1, $this->captionCount($host . "\n\n^ cap\n"));
    }

    #[DataProvider('captionableHosts')]
    public function testAnAdjacentCaptionStillAttaches(string $host): void
    {
        // The other CONTROL: zero blank lines is the other half of the slot.
        $this->assertSame(1, $this->captionCount($host . "\n^ cap\n"));
    }

    public function testTheDetachedLineIsAnOrdinaryParagraph(): void
    {
        // §4 does not merely refuse the attachment, it says what the line
        // BECOMES. Byte-identical to carve-js 62e0e5a.
        $this->assertSame(
            "<pre><code>x\n</code></pre>\n<p>^ cap</p>\n",
            (new CarveConverter())->convert("```\nx\n```\n\n\n^ cap\n"),
        );
    }

    /**
     * @return array<string, array{0: callable(string): string}>
     */
    public static function containers(): array
    {
        return [
            'in a div' => [fn (string $body): string => "::: d\n" . $body . ":::\n"],
            'in a blockquote' => [
                fn (string $body): string => implode("\n", array_map(
                    fn (string $line): string => $line === '' ? '>' : '> ' . $line,
                    explode("\n", rtrim($body, "\n")),
                )) . "\n",
            ],
            'in a list item' => [
                fn (string $body): string => "- start\n\n" . implode("\n", array_map(
                    fn (string $line): string => $line === '' ? '' : '  ' . $line,
                    explode("\n", rtrim($body, "\n")),
                )) . "\n",
            ],
        ];
    }

    #[DataProvider('containers')]
    public function testTheSlotHoldsInsideEveryContainerToo(callable $wrap): void
    {
        // Every container recurses through the same block loop, so the same
        // uncounted blank-line skip is between the host and the caption there.
        // Measured against carve-js on the full five-hosts-by-four-containers
        // grid, 20 rows, all agreeing.
        foreach (self::captionableHosts() as $name => [$host]) {
            $this->assertSame(
                1,
                $this->captionCount($wrap($host . "\n\n^ cap\n")),
                sprintf('one blank line stopped attaching for the %s host', $name),
            );
            $this->assertSame(
                0,
                $this->captionCount($wrap($host . "\n\n\n^ cap\n")),
                sprintf('two blank lines still attached for the %s host', $name),
            );
        }
    }
}
