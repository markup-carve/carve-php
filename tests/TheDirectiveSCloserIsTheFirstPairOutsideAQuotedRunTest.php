<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeDirectiveSyntax;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * carve#2013 / carve-php#1964.
 *
 * THE DIRECTIVE'S CLOSER IS THE FIRST `}}` OUTSIDE ANY QUOTED RUN. A quoted
 * include path and a quoted option value both exclude only their own quote
 * character, the backslash and the newline, so a `}}` between the quotes
 * belongs to the run and does not end the directive. An UNTERMINATED quote
 * opens no run, so it falls back to the unquoted reading and the closer is the
 * first `}}` again - which is what leaves a malformed directive as the literal
 * text section 19 says it is.
 *
 * This engine got BOTH halves wrong. Its scanner excluded `}` from the whole
 * body, so it could not reach past one at all, and the lazy quantifier closed
 * at the first pair regardless.
 *
 * The shared corpus cannot see any of this: with no resolver both readings
 * render the same literal text, and `tests/include-conformance` generates its
 * goldens by driving carve-js, which the ruling makes non-conformant. The
 * observation therefore has to be made here, against a resolver.
 */
class TheDirectiveSCloserIsTheFirstPairOutsideAQuotedRunTest extends TestCase
{
    public function testAQuotedPathMayHoldThePairAndTheRestIsText(): void
    {
        $this->assertSame(
            "<p>R&lt;a }} more&gt; end</p>\n",
            $this->expand('{{ "a }} more" }} end'),
        );
    }

    public function testAQuotedPathHoldingThePairKeepsItsOptionSlot(): void
    {
        // The run reaches past the path's pair, so `@shift:1` is read as the
        // option it is rather than as prose after a truncated directive.
        $this->assertSame(
            "<p>R&lt;a }} more&gt; end</p>\n",
            $this->expand('{{ "a }} more" @shift:1 }} end'),
        );
    }

    public function testAQuotedValueMayHoldThePair(): void
    {
        // `@label` is not an option this engine knows, so the directive is
        // refused - but it is refused on the WHOLE option, which is only
        // reachable once the run extends past the value's pair.
        $this->assertSame(
            ['Unknown include option \'@label:"a }} more"\''],
            $this->warnings('{{ ch.crv @label:"a }} more" }} end'),
        );
    }

    public function testARefusedQuotedValueStillLeavesTheWholeRunAsText(): void
    {
        // A CONTROL, and one that cannot discriminate: the directive is
        // refused under either reading, so the literal output is the same
        // before and after. It is here to say that widening the run does not
        // start expanding something the engine has no option for.
        $this->assertSame(
            "<p>{{ ch.crv <span class=\"mention\"><strong>@label</strong></span>:\u{201C}a }} more\u{201D} }} end</p>\n",
            $this->expand('{{ ch.crv @label:"a }} more" }} end'),
        );
    }

    public function testAWholeParagraphDirectiveIsWarnedOnceNotTwice(): void
    {
        // The loose whole-paragraph shape is a SECOND spelling of the bounds
        // rule, and it guards against reporting the same run twice. Left
        // excluding `}`, it stopped recognizing exactly the runs this ruling
        // widens, and the inline scan reported them a second time.
        $this->assertCount(1, $this->warnings('{{ ch.crv @label:"a }} more" }}'));
    }

    public function testAQuotedValueKeepsItsOwnHashOutOfTheSectionSlot(): void
    {
        // Splitting the option slot on whitespace tore the value into pieces,
        // so its `#sec` arrived in the section slot and the diagnostic named a
        // token that is nowhere in the source.
        $this->assertSame(
            ['Unknown include option \'@label:"a #sec b"\''],
            $this->warnings('{{ ch.crv @label:"a #sec b" }} end'),
        );
    }

    public function testTwoQuotedPathsHoldingThePairBothClose(): void
    {
        $this->assertSame(
            "<p>R&lt;a }} b&gt; mid R&lt;c }} d&gt; end</p>\n",
            $this->expand('{{ "a }} b" }} mid {{ "c }} d" }} end'),
        );
    }

    public function testAnUnterminatedQuoteStillClosesAtTheFirstPair(): void
    {
        // No closing quote on the line, so no run is opened and the first pair
        // closes. The path is then `"a` - not a path - and the whole thing is
        // the literal text it should be.
        $this->assertSame(
            "<p>{{ \u{201C}a more }} end</p>\n",
            $this->expand('{{ "a more }} end'),
        );
        $this->assertSame([], $this->warnings('{{ "a more }} end'));
    }

    public function testAQuoteAfterTheCloserDoesNotDragTheCloserAlong(): void
    {
        // The run closes at the pair that follows its own quoted path. A quote
        // sitting in the prose after that is prose.
        $this->assertSame(
            "<p>R&lt;a more&gt; b\u{201D} c }} end</p>\n",
            $this->expand('{{ "a more" }} b" c }} end'),
        );
    }

    public function testQuotesPairLeftToRightAndTheOddOneOpensNoRun(): void
    {
        // Three quotes: the first two are a run, and the third has no partner
        // left on the line. Pairing the LAST two instead would move the closer
        // past the pair at `}} b`, so this is the row that says the walk is
        // left to right and that the odd quote opens nothing.
        $this->assertSame(
            "<p>{{ x\u{201D} \u{201C}a }} b\u{201D} }} end</p>\n",
            $this->expand('{{ x" "a }} b" }} end'),
        );
    }

    public function testAnUnterminatedQuoteThatSwallowsThePairLeavesProse(): void
    {
        // A terminated run really does swallow the pair, so a sentence whose
        // quotes happen to straddle one carries no directive at all. Both
        // readings render this as the prose it is, which is why the shared
        // HTML corpus cannot arbitrate the question.
        $this->assertSame(
            "<p>{{ \u{201C}a }} end. He said \u{201C}hi\u{201D}.</p>\n",
            $this->expand('{{ "a }} end. He said "hi".'),
        );
    }

    public function testAWellFormedDirectiveIsUntouched(): void
    {
        $this->assertSame("<p>R&lt;ch.crv&gt; end</p>\n", $this->expand('{{ ch.crv }} end'));
        $this->assertSame("<p>R&lt;my chapter.crv&gt;</p>\n", $this->expand('{{ "my chapter.crv" }}'));
        $this->assertSame(
            "<p>a R&lt;x.crv&gt; b R&lt;y.crv&gt; c</p>\n",
            $this->expand('a {{ x.crv }} b {{ y.crv }} c'),
        );
        $this->assertSame([], $this->warnings('a {{ x.crv }} b {{ y.crv }} c'));
    }

    public function testALoneBraceInsideAQuotedValueScans(): void
    {
        // A lone `}` inside a quoted run is part of the run like any other
        // character. The old scanner excluded `}` from the body outright, so
        // this never reached the parser and the author got no diagnostic at
        // all for an option that does not exist.
        $this->assertSame(
            ['Unknown include option \'@label:"a } more"\''],
            $this->warnings('{{ ch.crv @label:"a } more" }} end'),
        );
    }

    public function testALoneBraceOutsideAQuotedRunStillEndsNothing(): void
    {
        // The ruling widens the body for QUOTED runs only. A bare path cannot
        // carry a `}`, so this stays the prose it has always been.
        $this->assertSame("<p>{{ a} }} end</p>\n", $this->expand('{{ a} }} end'));
        $this->assertSame([], $this->warnings('{{ a} }} end'));
    }

    public function testTheCarveWriterPreservesADirectiveWhoseQuotedRunHoldsThePair(): void
    {
        // A REGRESSION GUARD, not a discriminator: it is green with the
        // writer's own copy of the scanner reverted too. The writer builds the
        // prose around a span from the nodes' own source forms, so for these
        // shapes preserving the span and re-emitting it as prose produce the
        // same bytes - a 264-source sweep found none where they differ. The
        // writer was moved onto the shared pattern because a second spelling
        // of the bounds rule is drift waiting to happen, not because this row
        // could catch it.
        foreach (
            [
                '{{ "a }} more" }} end',
                '{{ ch.crv @label:"a }} more" }} end',
                '{{ "a more }} end',
            ] as $source
        ) {
            $this->assertSame($source . "\n", CarveConverter::carve()->convert($source));
        }
    }

    /**
     * A `"` is the one character the body walk could take two ways, and the
     * scan ends on a fixed-width lookbehind, so a formulation that merely
     * looks linear re-enters at every quote. Possessive quantifiers are what
     * stop it: the same pattern without them exhausts PCRE's backtrack limit
     * on this input and returns NO MATCH, which would make a directive vanish
     * rather than merely run slowly.
     */
    #[Group('scaling')]
    public function testTheScanDoesNotBacktrackOverAQuoteRun(): void
    {
        // Ends on a `}}` that is NOT preceded by whitespace, so the last step
        // fails and every choice the engine kept would have to be revisited.
        $source = '{{ ' . str_repeat('"x ', 4000) . 'x}}';

        $start = hrtime(true);
        $count = preg_match_all(IncludeDirectiveSyntax::SCAN, $source, $matches);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertSame(0, $count);
        $this->assertSame(PREG_NO_ERROR, preg_last_error(), preg_last_error_msg());
        $this->assertLessThan(0.5, $elapsed, "the directive scan took {$elapsed}s (backtracking regression?)");
    }

    private function expand(string $source): string
    {
        $converter = new CarveConverter();
        $document = $converter->parse($source);

        return $converter->render($converter->transform($document, new IncludeExpander($this->resolver())));
    }

    /**
     * @return list<string>
     */
    private function warnings(string $source): array
    {
        $converter = new CarveConverter();
        $document = $converter->parse($source);
        $expander = new IncludeExpander($this->resolver());
        $converter->render($converter->transform($document, $expander));

        return array_map(
            static fn ($warning): string => $warning->getMessage(),
            $expander->getWarnings(),
        );
    }

    private function resolver(): IncludeResolverInterface
    {
        return new class implements IncludeResolverInterface {
            public function resolve(string $path, IncludeContext $context): string
            {
                return 'R<' . $path . '>';
            }
        };
    }
}
