<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block-attribute block may span any number of lines.
 *
 * Both normative files say so and neither caps it. `resources/grammar.ebnf`
 * spells the separator between two attributes
 * `attr_separator = (whitespace | continuation), opt_ws` with
 * `continuation = newline, opt_ws`, and the block is
 * `'{', opt_pad, attribute, {attr_separator, attribute}, opt_pad, '}'` - one
 * line break per separator, and the separator repeats without limit.
 * `resources/carve-core.ohm` says the same thing more directly:
 * `blockAttrs = "{" battrSp* attrItem (battrSp+ attrItem)* battrSp* "}"` with
 * `battrSp = " " | "\t" | "\n"`. carve-js, carve-rs and the executable spec all
 * read `{.a` + `.b` + `.c}` as ONE block.
 *
 * THE CAP WAS NOT WRITTEN AS A CAP. The continuation branch required the line
 * to be INDENTED, which is not what `continuation` says - the indent lives in
 * `opt_ws`, which is optional. `{.a` + `.b}` worked because the second line met
 * the CLOSING branch rather than the continuation one, so the defect only
 * became visible from the third line onward, and `{` + `.a` + `}` - the shape
 * the grammar's own note calls out as accepted by all three engines - did not
 * work at all.
 *
 * `opt_ws` IS SPACES AND TABS, not PCRE `\s`, and the strip is spelled that way
 * now - the old indent test used `\s`, which also eats a vertical tab and a form
 * feed. THAT CHANGE IS UNOBSERVABLE TODAY and is deliberately NOT given a
 * fixture: the attribute payload tokenizer splits on any whitespace, so a
 * vertical tab left in the payload is read as an attribute separator and
 * `{.a<VT>.b}` is one block on a single line too, on `main` as here. Which
 * characters `attr_separator` admits is a different production and a different
 * question; this ticket does not decide it, and a row asserting the narrowed
 * charlist would be a check that cannot fail.
 *
 * A BLANK LINE ENDS THE ATTEMPT (PART 15 A5, and `continuation` says "NOT a
 * blank line"). A line of spaces or tabs IS a blank line, and it used to be
 * accepted as interior padding because it matched the indent test - so
 * `{.a` + `<SP><SP><SP>` + `.b}` was one block where `{.a` + `` + `.b}` was
 * not. Both are literal text now.
 *
 * A QUOTED VALUE STOPS AT THE NEWLINE, so the break can only ever fall BETWEEN
 * two attributes. `quoted_value` excludes a newline in both of its alternatives
 * (PART 4) and `block_attributes` reads the same production, so a break inside
 * the quotes ends the production and the block is unrecognized. This engine
 * used to accept the shape and COLLAPSE the newline to a space, which no
 * production in either normative file describes - three engines improvising is
 * what an unstated rule looks like (markup-carve/carve#888, carve-php#986).
 * A first pass here read that contradiction the other way and EXEMPTED a quoted
 * value from the per-line rule; the ruling settled it the opposite way, and the
 * shapes pinned as ACCEPTING then are pinned as literal now.
 */
class BlockAttributeBlockSpansManyLinesTest extends TestCase
{
    /**
     * One row per line count, plus the two padding positions.
     *
     * THE THIRD LINE IS THE ONE THAT MATTERS. A two-line block already worked,
     * through the closing branch rather than the continuation branch, so a
     * fixture that stops at two lines cannot tell the cap from its absence.
     * Four lines are pinned as well: a fix that raised the cap by one instead
     * of removing it passes at three.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function multiLineProvider(): array
    {
        return [
            'one line' => ["{.a .b}\npara\n", 'class="a b"'],
            'two lines' => ["{.a\n.b}\npara\n", 'class="a b"'],
            'three lines' => ["{.a\n.b\n.c}\npara\n", 'class="a b c"'],
            'four lines' => ["{.a\n.b\n.c\n.d}\npara\n", 'class="a b c d"'],
            'ten lines' => [
                '{' . implode("\n", ['.a', '.b', '.c', '.d', '.e', '.f', '.g', '.h', '.i', '.j}']) . "\npara\n",
                'class="a b c d e f g h i j"',
            ],
            // The grammar's own note on `opt_pad`: "`{` + newline + `.a}` and
            // `{.a` + newline + `}` were outside the production while the
            // interior `{.a` + newline + `.b}` was inside it. All three engines
            // accept every one of the three."
            'a break after the opening brace' => ["{\n.a}\npara\n", 'class="a"'],
            'a break before the closing brace' => ["{.a\n}\npara\n", 'class="a"'],
            'a break at both ends' => ["{\n.a\n}\npara\n", 'class="a"'],
            'indented continuations still work' => ["{.a\n  .b\n  .c}\npara\n", 'class="a b c"'],
            'mixed kinds of attribute over lines' => ["{#i\n.c\nk=\"v\"}\npara\n", 'id="i"'],
            // A QUOTED VALUE ON ONE LINE is untouched by any of this - the
            // rule is about the line break, not about the quotes.
            'a quoted value on one line' => ["{k=\"a b\"}\npara\n", 'k="a b"'],
            'a single-quoted value on one line' => ["{k='a b'}\npara\n", 'k="a b"'],
        ];
    }

    /**
     * The shapes that are NOT a block-attribute block, each for its own reason.
     *
     * Every row asserts the LITERAL fallback rather than the absence of a class
     * attribute: a block that silently dropped its attributes would satisfy
     * "no class" and is a different, also wrong, answer.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function literalProvider(): array
    {
        return [
            // PART 15 A5: a blank line inside the braces ends the attempt.
            'a blank line inside' => ["{.a\n\n.b}\npara\n", '{.a'],
            'a line of spaces inside' => ["{.a\n   \n.b}\npara\n", '{.a'],
            'a line of tabs inside' => ["{.a\n\t\t\n.b}\npara\n", '{.a'],
            // A5 IS UNCONDITIONAL, INCLUDING INSIDE A QUOTED VALUE. The quoted
            // value is exempt from the per-line ATTRIBUTE rule, not from the
            // blank-line rule: "A BLANK line inside the braces ends the
            // attempt" names no exception, and the executable spec checks
            // blankness outside its quote scan (`scripts/spec/layout.mjs`,
            // `if (li >= lines.length || isBlank(lines[li])) return null`).
            // This engine used to answer the two spellings differently - an
            // EMPTY line ended a quoted value's block and a SPACES-ONLY line
            // did not - which is the inconsistency A5 exists to remove.
            'an empty line inside a quoted value' => ["{title=\"a\n\nb\"}\npara\n", '{title='],
            'a line of spaces inside a quoted value' => ["{title=\"a\n   \nb\"}\npara\n", '{title='],
            // A6: not an attribute list -> not an attribute line. One invalid
            // name invalidates the whole block, however many lines it spans.
            'an invalid name on a continuation line' => ["{.a\n.1\n.c}\npara\n", '{.a'],
            'an invalid name on the closing line' => ["{.a\n.b\n.1}\npara\n", '{.a'],
            // No closing brace at all.
            'never closed' => ["{.a\n.b\npara\n", '{.a'],
            // A QUOTED VALUE STOPS AT THE NEWLINE (PART 4, and
            // markup-carve/carve#888). `block_attributes` reads the same
            // `quoted_value`, whose two alternatives both exclude a newline, so
            // a break inside the quotes is neither content nor a separator: it
            // ends the production and the block is unrecognized. This engine
            // used to accept the shape and COLLAPSE the newline to a space,
            // which no production in either normative file describes
            // (carve-php#986).
            'a break inside a quoted value' => ["{k=\"a\nb\"}\n\nparagraph\n", '{k='],
            'a break inside a quoted value, three lines' => [
                "{title=\"a\n  https://x\n  done\"}\npara\n",
                '{title=',
            ],
            // Both quote characters open a value, so both refuse the break.
            //
            // THE INTERIOR LINES HAVE TO LOOK LIKE ATTRIBUTES for these two
            // rows to test the quote rule at all. `https://x` is not a valid
            // attribute list on its own, so the per-line rule refuses that
            // block whether or not the quote is tracked - which is why these
            // spell the value `a` / `b` / `c`, every line of which IS a valid
            // boolean attribute. Track only the double quote and the
            // single-quoted row is accepted as three attributes.
            'a break inside a single-quoted value' => ["{k='a\nb\nc'}\npara\n", '{k='],
            'a break inside a double-quoted value, valid-looking interior' => [
                "{k=\"a\nb\nc\"}\npara\n",
                '{k=',
            ],
            // A BACKSLASH-ESCAPED QUOTE DOES NOT CLOSE THE VALUE, so the value
            // is still open at the line break and the block is refused. Read
            // the escape as an ordinary closing quote and the payload looks
            // balanced, and the block would be accepted across the break.
            'an escaped quote leaves the value open at the break' => [
                "{t=\"a\\\" b\nz\"}\npara\n",
                '{t=',
            ],
        ];
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    #[DataProvider('multiLineProvider')]
    public function testABlockAttributeBlockSpansItsLines(string $source, string $marker): void
    {
        $this->assertStringContainsString($marker, $this->html($source));
    }

    #[DataProvider('literalProvider')]
    public function testAShapeOutsideTheProductionStaysLiteral(string $source, string $literal): void
    {
        $out = $this->html($source);

        $this->assertStringNotContainsString('class="a', $out);
        $this->assertStringNotContainsString('id="i"', $out);
        $this->assertStringNotContainsString('title="a', $out);
        $this->assertStringNotContainsString('<p k=', $out);
        $this->assertStringNotContainsString('<p t=', $out);
        // The braces are still on the page, which is what "literal" means.
        $this->assertStringContainsString($literal, $out);
    }

    /**
     * The attributes reach the paragraph BELOW, not the one they sit in.
     *
     * PART 15 A2: the block floats to the next visible block. A multi-line
     * block that attached to the wrong target would still show a `class`
     * somewhere and pass the rows above.
     */
    public function testTheAttributesLandOnTheFollowingBlock(): void
    {
        $this->assertSame(
            "<p class=\"a b c\">para</p>\n",
            $this->html("{.a\n.b\n.c}\npara\n"),
        );
    }

    /**
     * The widened scan stays linear.
     *
     * A `{` line with no closing brace now scans forward to the next blank line
     * or to the end of the block's lines. Without a bound, a document of
     * `{`-opening block starts re-walks that run once per start, which is the
     * O(n^2) shape this repository has fixed three times in the inline
     * scanners. Measured before the bound was added: 6.4 seconds at 4,000
     * openers against 0.3 before the widening.
     *
     * ONE bound keeps it linear: a continuation line that is not a valid
     * attribute list on its own can never become part of a valid block, so the
     * scan stops there rather than at a distant closing brace. A `{` line is
     * not a valid attribute list either, so a scan always halts at the next
     * block start and no run can be re-walked once per start. A line INSIDE a
     * quoted value is exempt from the rule, and that exemption was measured
     * too: an opener that leaves a quote open (`{.a'`) is closed by the next
     * one, so the scan still halts within a few lines.
     *
     * A second bound - a memo of ranges already known to hold no closing line -
     * was written first and then removed: with the per-line bound in place
     * nothing could reach it, and no mutation of it could be made to fail.
     * Both shapes below are kept anyway, because they fail for DIFFERENT
     * reasons and only one of them was quadratic before the bound.
     *
     * This mirrors `AttributeScanTest`, which guards the same class of defect
     * for the INLINE attribute scanner.
     */
    public function testTheMultiLineScanStaysLinear(): void
    {
        $shapes = [
            // A block start per pair, no closing brace anywhere: the recorded
            // range is what keeps this off the quadratic path.
            'openers with no closing brace' => "{.a\n# h\n",
            // A block start per pair sharing ONE distant closing brace: the
            // per-line validity bound is what keeps this off it.
            'openers sharing a distant closing brace' => "{.a\n# h\n",
        ];
        $suffixes = ['openers with no closing brace' => '', 'openers sharing a distant closing brace' => ".z}\n"];

        foreach ($shapes as $label => $fragment) {
            $small = str_repeat($fragment, 1000) . $suffixes[$label];
            $large = str_repeat($fragment, 4000) . $suffixes[$label];

            $converter = new CarveConverter();
            $converter->convert($small);

            $smallStart = microtime(true);
            $converter->convert($small);
            $smallPerByte = (microtime(true) - $smallStart) / strlen($small);

            $largeStart = microtime(true);
            $converter->convert($large);
            $largePerByte = (microtime(true) - $largeStart) / strlen($large);

            // Per BYTE, not total: linear measures ~1 whatever the size
            // multiple, and quadratic measures the multiple itself (4). The
            // threshold and the reasoning are ScalingGuardTrait's; this shape
            // uses a smaller sample because a quadratic reading here would take
            // minutes at that trait's 50,000 repeats.
            $this->assertLessThan(
                2.0,
                $largePerByte / $smallPerByte,
                "{$label}: per-byte cost grew with input size",
            );
        }
    }

    public function testEveryShapeIsStillCovered(): void
    {
        // A row silently dropped from a provider would take its reason with it.
        $this->assertCount(12, self::multiLineProvider());
        $this->assertCount(13, self::literalProvider());
    }
}
