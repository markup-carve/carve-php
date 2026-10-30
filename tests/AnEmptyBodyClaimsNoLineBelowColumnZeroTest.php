<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 section 17 L3 (`AND FLUSH-LEFT MEANS COLUMN 0`) gives the continuation
 * marker its own control: a refused `+` behaves "exactly as if the `+` line had
 * been a comment". In the FIRST-BLOCK form - `: +` for a description, `- +`
 * for a list item - no paragraph is open, so the `+` genuinely IS a marker and
 * the clause reads its payload's column. A payload at any column other than 0
 * refuses the marker, and the `+` then reads like a letter (carve#4396).
 *
 * WHY A RELATION AND NOT A PAIR OF GOLDENS. The clause states that a refused
 * `+` and a letter give one answer. So the band below asserts the marker
 * spelling EQUALS its letter control, and the column-0 rows assert the
 * attached reading, without which a form that refused everything would
 * satisfy all the rest.
 *
 * THE LIST ITEM IS NOT A FREE PASS. The oracle already answered the item and
 * did not change for it, but that is a fact about the oracle: this engine is
 * measured here too, in both containers.
 *
 * Pinned upstream by corpus category 436.
 */
class AnEmptyBodyClaimsNoLineBelowColumnZeroTest extends TestCase
{
    protected function html(string $source): string
    {
        return trim(CarveConverter::create()->convert($source));
    }

    protected function marker(string $container, int $col): string
    {
        $pad = str_repeat(' ', $col);

        return $container === 'description'
            ? ":: t\n:  +\n{$pad}flush\n"
            : "- +\n{$pad}flush\n";
    }

    protected function comment(string $container, int $col): string
    {
        $pad = str_repeat(' ', $col);

        return $container === 'description'
            ? ":: t\n:  %% c\n{$pad}flush\n"
            : "- %% c\n{$pad}flush\n";
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function refusedBandProvider(): array
    {
        $rows = [];
        foreach (['description', 'item'] as $container) {
            foreach ([1, 2, 3, 4] as $col) {
                $rows["{$container} at column {$col}"] = [$container, $col];
            }
        }

        return $rows;
    }

    #[DataProvider('refusedBandProvider')]
    public function testTheMarkerEndsTheBodyExactlyWhereItsCommentDoes(string $container, int $col): void
    {
        $letter = str_replace('+', 'z', $this->marker($container, $col));
        $this->assertSame(
            str_replace('z', '+', $this->html($letter)),
            $this->html($this->marker($container, $col)),
            "{$container} at payload column {$col}: the refused marker must read like a letter",
        );
    }

    public function testTheRefusedBandLeavesThePayloadOutsideTheContainer(): void
    {
        // The relation above is satisfied by any answer both spellings share,
        // so it cannot say WHICH answer. These pin the oracle's.
        $dd = "<dl>\n  <dt>t</dt>\n  <dd>+\nflush</dd>\n</dl>";
        $commented = "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<p>flush</p>";
        foreach ([1, 2] as $col) {
            $this->assertSame($dd, $this->html($this->marker('description', $col)));
            $this->assertSame($commented, $this->html($this->comment('description', $col)));
        }

        $this->assertSame("<ul>\n  <li>+\nflush</li>\n</ul>", $this->html($this->marker('item', 1)));
        $this->assertSame("<ul>\n  <li></li>\n</ul>\n<p>flush</p>", $this->html($this->comment('item', 1)));
    }

    public function testALineAtTheContentColumnIsTheContainersFirstBlock(): void
    {
        // The other end of the band. A change that refused everything would
        // pass the rows above and break these.
        $dd = "<dl>\n  <dt>t</dt>\n  <dd>flush</dd>\n</dl>";
        $this->assertSame("<dl>\n  <dt>t</dt>\n  <dd>+\nflush</dd>\n</dl>", $this->html($this->marker('description', 3)));
        $this->assertSame($dd, $this->html($this->comment('description', 3)));

        $li = "<ul>\n  <li>flush</li>\n</ul>";
        $this->assertSame("<ul>\n  <li>+\nflush</li>\n</ul>", $this->html($this->marker('item', 2)));
        $this->assertSame($li, $this->html($this->comment('item', 2)));
    }

    public function testAtColumnZeroTheTwoSpellingsMustDiffer(): void
    {
        // THE ONE PAIR THAT MUST NOT AGREE. At column 0 the marker is not
        // refused, so the first-block form keeps the one flush-left block it
        // names, while the comment spelling ends the body as any comment does.
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>flush</dd>\n</dl>",
            $this->html($this->marker('description', 0)),
        );
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<p>flush</p>",
            $this->html($this->comment('description', 0)),
        );
        $this->assertSame("<ul>\n  <li>flush</li>\n</ul>", $this->html($this->marker('item', 0)));
        $this->assertSame(
            "<ul>\n  <li></li>\n</ul>\n<p>flush</p>",
            $this->html($this->comment('item', 0)),
        );

        foreach (['description', 'item'] as $container) {
            $this->assertNotSame(
                $this->html($this->comment($container, 0)),
                $this->html($this->marker($container, 0)),
                "{$container}: column 0 is where the two spellings must differ",
            );
        }
    }

    public function testAMarkerUnderAnOpenParagraphStaysLiteralText(): void
    {
        // NOT IN SCOPE, pinned so the port cannot quietly take it. A marker
        // cannot interrupt a paragraph, so under an open body the `+` is
        // ordinary text. All four containers agree and this must not change.
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d\n+\nflush</dd>\n</dl>",
            $this->html(":: t\n:  d\n   +\nflush\n"),
        );
    }

    public function testTheListItemSurvivesTheRoundTrip(): void
    {
        // PART 11 section 1a on the shapes this ruling creates. The item
        // survives in both spellings; the DESCRIPTION rows are the two that do
        // not, and they are deliberately not asserted here - see the writer
        // note on markup-carve/carve-php#1809. Pinning the half that holds
        // keeps a later change from quietly breaking it too.
        $writer = CarveConverter::create(null, new CarveRenderer());
        foreach (["- +\n flush\n", "- %% c\n flush\n"] as $source) {
            $written = $writer->convert($source);
            $this->assertSame($this->html($source), $this->html($written), $source);
            $this->assertSame($written, $writer->convert($written), $source);
        }
    }
}
