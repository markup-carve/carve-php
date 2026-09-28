<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Six of the seven fuzz reductions where this engine read a document differently
 * from the executable spec, each with the rows around it that must not move
 * (markup-carve/carve-php#2632).
 *
 * The seventh - a comment PAST a quote's content column - was held back from
 * this file and fixed in markup-carve/carve-php#2651 instead. It looked like a
 * dispute between the oracle and a container-boundary ruling, and it was not:
 * carve-js answers what the oracle answers, so this engine was the lone reader
 * out and there was nothing to rule. The appearance came from measuring carve-js
 * through a three-day-old `dist/` in a checkout carrying another lane's
 * uncommitted edits - built clean, it sides with the oracle.
 *
 * Every expectation below is the oracle's output - `scripts/spec/layout.mjs`
 * plus `scripts/spec/html.mjs` in markup-carve/carve at 38829a97 - captured by
 * running it per row rather than by reading this engine back.
 *
 * EACH REPRODUCER WAS WIDENED BEFORE ANYTHING CHANGED, because four of the six
 * were narrower than the defect. The `{}` in the marker case is incidental: any
 * attribute block promotes the lazy line. The trailing space breaks a named
 * `[label]` as readily as an empty one. The rows that already answered correctly
 * are here for the same reason: each fix opens something the parser used to
 * refuse, and the shape next door is where an over-correction shows up first.
 */
class SixFuzzShapesAgreeWithTheOracleTest extends TestCase
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
            'a comment marker inside a strong span' => [
                "*b %%\nb*\n",
                "<p><strong>b\nb</strong></p>\n",
            ],
            'the same span with no line after the comment' => [
                "*b %% x*\n",
                "<p>*b</p>\n",
            ],
            'a later delimiter kind answers the same' => [
                "~b %%\nb~\n",
                "<p><s>b\nb</s></p>\n",
            ],
            'a code span still swallows the marker' => [
                "`a %%\nb`\n",
                "<p><code>a %%\nb</code></p>\n",
            ],
            'a quote caption followed by a bare pipe' => [
                ">\n^ -\n|\n",
                "<figure>\n  <blockquote>\n\n  </blockquote>\n  <figcaption>-\n|</figcaption>\n</figure>\n",
            ],
            'a real table row still ends the caption' => [
                ">\n^ -\n| a |\n",
                "<figure>\n  <blockquote>\n\n  </blockquote>\n  <figcaption>-</figcaption>\n</figure>\n<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n",
            ],
            // A comment PAST the column belongs with this one and is not here:
            // it was the seventh row, and markup-carve/carve-php#2651 fixed it
            // separately once carve-js turned out to answer what the oracle
            // answers.
            'a comment at the content column is unchanged' => [
                "> %%\np\n",
                "<blockquote>\n\n</blockquote>\n<p>p</p>\n",
            ],
            'a nested ordered marker with an empty attribute block' => [
                "> . *\n.{} _\n",
                "<blockquote>\n  <ol>\n    <li>*\n.{} _</li>\n  </ol>\n</blockquote>\n",
            ],
            'the same lazy marker with no attribute block' => [
                "> . a\n. b\n",
                "<blockquote>\n  <ol>\n    <li>a\n. b</li>\n  </ol>\n</blockquote>\n",
            ],
            'a marker with a class attribute answers the same' => [
                "> . a\n.{.c} b\n",
                "<blockquote>\n  <ol>\n    <li>a\n.{.c} b</li>\n  </ol>\n</blockquote>\n",
            ],
            'a definition in a quote, then a lazy line' => [
                ">  [d]: u\n)\n",
                "<blockquote><p>[d]: u\n)</p></blockquote>\n",
            ],
            'a definition at the content column still ends it' => [
                "> [d]: u\n)\n",
                "<blockquote>\n\n</blockquote>\n<p>)</p>\n",
            ],
            'a definition term on a marker line, then an abbreviation' => [
                ". :: t\n*[A]: b\n",
                "<ol>\n  <li>\n    <dl>\n      <dt>t\n*[A]: b</dt>\n    </dl>\n  </li>\n</ol>\n",
            ],
            'the same term at document level' => [
                ":: t\n*[A]: b\n",
                "<dl>\n  <dt>t\n*[A]: b</dt>\n</dl>\n",
            ],
            'a reference definition still ends the term' => [
                ":: t\n[d]: u\n",
                "<dl>\n  <dt>t</dt>\n</dl>\n",
            ],
            'a comment still ends the term' => [
                ":: t\n%% c\n",
                "<dl>\n  <dt>t</dt>\n</dl>\n",
            ],
            'an empty div label with a trailing space' => [
                ":::[] \n",
                "<div>\n  <p class=\"div-label\"></p>\n</div>\n",
            ],
            'an empty div label without one' => [
                ":::[]\n",
                "<div>\n  <p class=\"div-label\"></p>\n</div>\n",
            ],
            'a named label with a trailing space' => [
                ":::[a] \n",
                "<div>\n  <p class=\"div-label\">a</p>\n</div>\n",
            ],
            'text after the label is not a fence' => [
                ":::[a] x\n",
                "<p>:::[a] x</p>\n",
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
