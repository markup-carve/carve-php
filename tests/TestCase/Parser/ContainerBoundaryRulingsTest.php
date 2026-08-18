<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three rulings that arrived with the bump to spec 5aab8fe.
 *
 * The corpus documents are the acceptance test and they live in the submodule;
 * these are the RULES behind them, spelled where the engine can be read against
 * them, plus the shapes each rule reaches that no corpus document pins.
 */
class ContainerBoundaryRulingsTest extends TestCase
{
    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * A LINE BLOCK HARDENS A SOFT BREAK AT EVERY DEPTH
     * (markup-carve/carve#1351).
     *
     * The promotion reached direct children only, which made the engine
     * contradict itself: the BACKSLASH spelling of a boundary already hardened
     * inside an emphasis run while the plain spelling of the same boundary did
     * not. One line boundary produces one `<br>`, however it is spelled.
     *
     * @return array<string, array{string, string}>
     */
    public static function nestedBreakProvider(): array
    {
        return [
            'plain boundary inside strong' => ["::: |\n*a\nb*\n:::\n", "<strong>a<br>\nb</strong>"],
            // The row that made the old reading self-contradictory: this one
            // already hardened, so the two spellings disagreed.
            'backslash boundary inside strong' => ["::: |\n*a\\\nb*\n:::\n", "<strong>a<br>\nb</strong>"],
            'inside emphasis' => ["::: |\n/a\nb/\n:::\n", "<em>a<br>\nb</em>"],
            'two containers deep' => ["::: |\n*/a\nb/*\n:::\n", "<em>a<br>\nb</em>"],
            'inside a link label' => ["::: |\n[a\nb](/u)\n:::\n", "a<br>\nb</a>"],
        ];
    }

    #[DataProvider('nestedBreakProvider')]
    public function testABreakHardensAtEveryDepth(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->html($source));
    }

    /**
     * THE EXEMPTION IS NODE-PRESENCE, NOT DEPTH.
     *
     * A verbatim run swallows the boundary into its own content, so there is no
     * soft break left to harden and no `<br>` to emit. Without this row the
     * rule above could be read as "every boundary in a line block hardens",
     * which is the reading that would put a `<br>` inside a code span.
     */
    public function testARunThatSwallowedTheBoundaryEmitsNoBreak(): void
    {
        $this->assertStringNotContainsString('<br>', $this->html("::: |\na `b\nc` d\n:::\n"));
    }

    /**
     * A TABLE IS A TABLE HOWEVER ITS LAST ROW IS SPELLED
     * (markup-carve/carve#1348).
     *
     * A continuation row carries no leading pipe, so the row test did not see
     * it and the container reported an open paragraph its table did not have.
     * The standard-row spelling of the same table already sent the tail out,
     * which is what made this a defect rather than a reading.
     *
     * @return array<string, array{string}>
     */
    public static function tableEndsOnAContinuationRowProvider(): array
    {
        return [
            'in a list item' => ["- | a |\n  + b |\ntail\n"],
            'in a quote' => ["> | a |\n> + b |\ntail\n"],
            'in a quote in a description' => [":: t\n:  > | a |\n   > + b |\ntail\n"],
            'in a description' => [":: t\n:  | a |\n   + b |\ntail\n"],
        ];
    }

    #[DataProvider('tableEndsOnAContinuationRowProvider')]
    public function testAContinuationRowLeavesNoOpenParagraph(string $source): void
    {
        $this->assertStringContainsString("</table>\n", $this->html($source));
        $this->assertStringEndsWith("<p>tail</p>\n", $this->html($source));
    }

    /**
     * ONLY WHERE A TABLE IS ABOVE IT (markup-carve/carve#1349).
     *
     * With no row above, `+ b |` is ordinary prose and the paragraph it belongs
     * to stays open, so a flush-left line still folds in. This is the control
     * the row above would take away if the continuation row were read as a
     * table wherever it appears.
     */
    public function testAContinuationRowWithNoTableAboveIsProse(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n+ b |\ntail</li>\n</ul>\n",
            $this->html("- a\n  + b |\ntail\n"),
        );
    }

    /**
     * AN INVISIBLE LINE AT A CONTAINER'S CONTENT COLUMN ENDS THE PARAGRAPH,
     * NOT THE CONTAINER (markup-carve/carve#1350).
     *
     * ASSERTED ON THE WHOLE DOCUMENT, because `tail` renders as `<p>tail</p>`
     * whether it lands at document level or folds into the container - a
     * containment check passes on exactly the defect it is meant to catch.
     *
     * @return array<string, array{string, string}>
     */
    public static function invisibleAtTheContentColumnProvider(): array
    {
        $item = "<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>\n";
        $description = "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p>tail</p>\n";

        return [
            'reference definition in an item' => ["- a\n  [r]: /u\ntail\n", $item],
            'footnote definition in an item' => ["- a\n  [^f]: t\ntail\n", $item],
            'reference definition in a description' => [":: t\n:  a\n   [r]: /u\ntail\n", $description],
            'comment in a description' => [":: t\n:  a\n   %% c\ntail\n", $description],
            // Not pinned by the corpus: #1350 is stated about a container's
            // content column rather than about descriptions, and nothing points
            // the other way. The LIST spelling of this one still folds and is
            // filed as markup-carve/carve-php#1421 - its tracker answers "is a
            // paragraph open" and "is the item still collecting" with one flag.
            'comment in a quote' => [
                "> a\n> %% c\ntail\n",
                "<blockquote><p>a</p></blockquote>\n<p>tail</p>\n",
            ],
        ];
    }

    #[DataProvider('invisibleAtTheContentColumnProvider')]
    public function testAnInvisibleLineAtTheContentColumnEndsTheParagraph(
        string $source,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * BELOW the column the same line is a LAZY continuation and still folds.
     *
     * The corpus pins both of these, and they are what keeps the rule about the
     * COLUMN rather than about the character.
     *
     * @return array<string, array{string, string}>
     */
    public static function invisibleBelowTheColumnProvider(): array
    {
        return [
            'comment at column 0' => ["- a\n%% c\nb\n", "<li>a\n    b\n  </li>"],
            'comment one column short' => ["- a\n %% c\nb\n", "<li>a\n    b\n  </li>"],
            // One column short of the item's content column, so it is not the
            // definition the column would have made it.
            'reference definition one column short' => ["- a\n [r]: /u\ntail\n", '[r]: /u'],
            // NOT AT THE COLUMN reads in both directions. A comment written
            // with a space of indentation INSIDE the quote is ordinary
            // paragraph text, exactly as an indented attribute line there is,
            // so a flush-left line still folds into the quote. This is the
            // control for the quote row above: without it the column test can
            // be dropped and every comment closes.
            'comment indented inside a quote' => [
                "> a\n>  %% c\ntail\n",
                "<blockquote>\n  <p>a</p>\n  <p>tail</p>\n</blockquote>\n",
            ],
        ];
    }

    #[DataProvider('invisibleBelowTheColumnProvider')]
    public function testAnInvisibleLineBelowTheColumnStillFolds(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->html($source));
    }
}
