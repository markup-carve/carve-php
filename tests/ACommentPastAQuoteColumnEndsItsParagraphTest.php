<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A COMMENT IS A BLOCK AT EVERY COLUMN (PART 9 §24 C3), so one written PAST a
 * quote's content column ends the quote's paragraph and the unquoted line below
 * it goes to document level.
 *
 * `trackBlockQuoteLazyState()` asked `$atContentColumn` first, so a comment one
 * column past the boundary left the paragraph open and that line folded into the
 * quote instead - and this engine was the only reader that did it. The oracle and
 * carve-js both publish the line outside (markup-carve/carve-php#2651).
 *
 * PAST THE COLUMN IS NOT BELOW IT, which is what made the defect look settled.
 * After the `> ` prefix is stripped a quote's content column is 0, so an indented
 * comment inside the quote sits at 1 - past the boundary. The ruling the gate was
 * credited with, carve-php#1424's reading of markup-carve/carve#1350, is about a
 * line BELOW the column, and the list-item shapes it cites reach this tracker at
 * column 0 where the gate admitted them either way. No corpus document carries
 * the quote shape at all.
 *
 * THE TRAILING LINE HAS TO BE UNQUOTED for the question to arise: write `> p` and
 * every reader keeps it inside, correctly. Both spellings are guarded below,
 * along with the two rows that DO need the column test - an indented attribute
 * line and an indented reference definition are ordinary paragraph text in a
 * quote, so the line under them still folds, and a fix that dropped their gate
 * too would show up there first.
 *
 * Expectations are the oracle's output (`scripts/spec/layout.mjs` plus
 * `scripts/spec/html.mjs`) at markup-carve/carve 774eb404, run per row.
 */
class ACommentPastAQuoteColumnEndsItsParagraphTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a comment past the column by a tab' => [
                "> \t%%\np\n",
                "<blockquote>\n\n</blockquote>\n<p>p</p>\n",
            ],
            'a comment past the column by one space' => [
                ">  %%\np\n",
                "<blockquote>\n\n</blockquote>\n<p>p</p>\n",
            ],
            'a comment past the column by three spaces' => [
                ">    %%\np\n",
                "<blockquote>\n\n</blockquote>\n<p>p</p>\n",
            ],
            'a comment fence past the column' => [
                "> \t%%%\np\n",
                "<blockquote>\n\n</blockquote>\n<p>p</p>\n",
            ],
            'the same shape with text around it' => [
                "> a\n>  %% c\ntail\n",
                "<blockquote><p>a</p></blockquote>\n<p>tail</p>\n",
            ],
            'a nested quote answers the same' => [
                "> > a\n> >  %% c\ntail\n",
                "<blockquote>\n  <blockquote><p>a</p></blockquote>\n</blockquote>\n<p>tail</p>\n",
            ],
            'GUARD a comment at the content column' => [
                "> %%\np\n",
                "<blockquote>\n\n</blockquote>\n<p>p</p>\n",
            ],
            'GUARD the line below is quoted' => [
                "> head\n>   %% c\n> tail\n",
                "<blockquote>\n  <p>head</p>\n  <p>tail</p>\n</blockquote>\n",
            ],
            'GUARD a blank line after the comment' => [
                "> head\n>   %% c\n\n> tail\n",
                "<blockquote><p>head</p></blockquote>\n<blockquote><p>tail</p></blockquote>\n",
            ],
            'GUARD an indented attribute line still folds' => [
                "> q\n>  {.k}\ntail\n",
                "<blockquote><p>q\n{.k}\ntail</p></blockquote>\n",
            ],
            'GUARD an indented reference definition still folds' => [
                ">  [d]: u\n)\n",
                "<blockquote><p>[d]: u\n)</p></blockquote>\n",
            ],
            'GUARD an item below its column still folds' => [
                "- a\n %% c\nb\n",
                "<ul>\n  <li>a\n    b\n  </li>\n</ul>\n",
            ],
            'GUARD an item at column 0 still folds' => [
                "- a\n%% c\nb\n",
                "<ul>\n  <li>a\n    b\n  </li>\n</ul>\n",
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     */
    #[DataProvider('shapes')]
    public function testTheOracleAnswerIsThisEngineAnswer(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }
}
