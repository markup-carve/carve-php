<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The Carve serializer must emit a well-formed include directive verbatim.
 *
 * The core never parses a directive as a node of its own, so it reaches the
 * serializer as ordinary text and used to be escaped like any other
 * punctuation-bearing text: `{{ chapter.crv }}` became `\{\{ chapter\.crv \}\}`
 * and every include in a formatted document silently stopped working.
 */
class IncludeDirectiveSerializationTest extends TestCase
{
    /**
     * @param string $source
     * @param string $expected
     */
    #[DataProvider('directiveProvider')]
    public function testWellFormedDirectivesSurviveFormatting(string $source, string $expected): void
    {
        $converter = CarveConverter::carve();

        $this->assertSame($expected, $converter->render($converter->parse($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directiveProvider(): iterable
    {
        yield 'bare path' => ["{{ chapter.crv }}\n", "{{ chapter.crv }}\n"];
        yield 'quoted path with spaces' => ["{{ \"my file.crv\" }}\n", "{{ \"my file.crv\" }}\n"];
        yield 'section' => ["{{ child.crv #intro }}\n", "{{ child.crv #intro }}\n"];
        yield 'line range' => ["{{ child.crv @lines:2-3 }}\n", "{{ child.crv @lines:2-3 }}\n"];
        yield 'shift literal' => ["{{ child.crv @shift:2 }}\n", "{{ child.crv @shift:2 }}\n"];
        yield 'shift negative' => ["{{ child.crv @shift:-1 }}\n", "{{ child.crv @shift:-1 }}\n"];
        yield 'shift auto' => ["{{ child.crv @shift:auto }}\n", "{{ child.crv @shift:auto }}\n"];
        yield 'section and shift' => ["{{ child.crv #a @shift:2 }}\n", "{{ child.crv #a @shift:2 }}\n"];
        yield 'inline within a sentence' => ["See {{ chapter.crv }} here\\.\n", "See {{ chapter.crv }} here\\.\n"];
    }

    /**
     * Preservation is decided on SHAPE, not validity. Escaping a run whose
     * shape is right but whose option is wrong would convert a fixable typo
     * into permanent literal text, and would also destroy the expansion-time
     * warning that explains the typo - the author would be left with neither
     * the directive nor the diagnostic.
     *
     * @param string $source
     * @param string $expected
     */
    #[DataProvider('shapeValidButInvalidProvider')]
    public function testShapeWellFormedDirectivesSurviveEvenWhenTheirOptionsAreInvalid(
        string $source,
        string $expected,
    ): void {
        $converter = CarveConverter::carve();

        $this->assertSame($expected, $converter->render($converter->parse($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapeValidButInvalidProvider(): iterable
    {
        yield 'unknown option' => ["{{ child.crv @bogus:1 }}\n", "{{ child.crv @bogus:1 }}\n"];
        yield 'malformed option' => ["{{ child.crv notanoption }}\n", "{{ child.crv notanoption }}\n"];
        yield 'missing section' => ["{{ child.crv #nope }}\n", "{{ child.crv #nope }}\n"];
        yield 'unknown option keeps quoted path plain' => [
            "{{ \"my file.crv\" @bogus:1 }}\n",
            "{{ \"my file.crv\" @bogus:1 }}\n",
        ];
    }

    /**
     * The point of preserving an invalid run: the warning that explains it must
     * still be there after a formatting pass. Escaping the run silenced it,
     * because escaped text is no longer directive-shaped for the expander.
     *
     * @param string $source
     * @param string $warning
     */
    #[DataProvider('warningAfterFormattingProvider')]
    public function testAnInvalidDirectiveStillWarnsAfterFormatting(string $source, string $warning): void
    {
        $formatter = CarveConverter::carve();
        $formatted = $formatter->render($formatter->parse($source));

        $converter = new CarveConverter();
        $expander = new IncludeExpander($this->resolver());
        $converter->transform($converter->parse($formatted), $expander);

        $messages = array_map(
            static fn ($item): string => $item->getMessage(),
            $expander->getWarnings(),
        );
        $this->assertNotSame([], $messages);
        $this->assertStringContainsString($warning, $messages[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function warningAfterFormattingProvider(): iterable
    {
        yield 'unknown option' => ["{{ chapter.crv @bogus:1 }}\n", "Unknown include option '@bogus:1'"];
        yield 'missing section' => ["{{ chapter.crv #nope }}\n", "no section '#nope'"];
    }

    /**
     * Prose does not split a paragraph into separate nodes, so several
     * directives with text between them arrive as ONE text-like run. The
     * serializer used to preserve the first span and escape the remainder
     * wholesale, destroying every later directive even though all of them were
     * perfectly valid.
     *
     * @param string $source
     * @param string $expected
     */
    #[DataProvider('multiDirectiveProvider')]
    public function testEveryDirectiveInARunSurvivesNotJustTheFirst(string $source, string $expected): void
    {
        $converter = CarveConverter::carve();

        $this->assertSame($expected, $converter->render($converter->parse($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function multiDirectiveProvider(): iterable
    {
        yield 'two separated by prose' => [
            "a {{ x.crv }} b {{ y.crv }} c\n",
            "a {{ x.crv }} b {{ y.crv }} c\n",
        ];

        yield 'three separated by prose' => [
            "a {{ x.crv }} b {{ y.crv }} c {{ z.crv }} d\n",
            "a {{ x.crv }} b {{ y.crv }} c {{ z.crv }} d\n",
        ];

        yield 'five separated by spaces' => [
            "{{ a.crv }} {{ b.crv }} {{ c.crv }} {{ d.crv }} {{ e.crv }}\n",
            "{{ a.crv }} {{ b.crv }} {{ c.crv }} {{ d.crv }} {{ e.crv }}\n",
        ];

        yield 'adjacent with nothing between' => [
            "{{ x.crv }}{{ y.crv }}\n",
            "{{ x.crv }}{{ y.crv }}\n",
        ];

        yield 'one at the very start and one at the very end' => [
            "{{ x.crv }} middle {{ y.crv }}\n",
            "{{ x.crv }} middle {{ y.crv }}\n",
        ];

        // Sections and options parse into extra Tag / Mention nodes, so these
        // runs are the ones a naive rescan is most likely to lose.
        yield 'sections and options' => [
            "a {{ x.crv #intro }} b {{ y.crv @shift:2 }} c {{ z.crv #a @lines:1-2 }} d\n",
            "a {{ x.crv #intro }} b {{ y.crv @shift:2 }} c {{ z.crv #a @lines:1-2 }} d\n",
        ];

        yield 'auto shift twice' => [
            "{{ a.crv @shift:auto }} {{ b.crv @shift:auto }}\n",
            "{{ a.crv @shift:auto }} {{ b.crv @shift:auto }}\n",
        ];

        yield 'quoted paths twice' => [
            "{{ \"my file.crv\" }} and {{ \"other file.crv\" }}\n",
            "{{ \"my file.crv\" }} and {{ \"other file.crv\" }}\n",
        ];
    }

    /**
     * A span that is not shape-well-formed is prose, not a reason to stop
     * scanning. Bailing on it took every valid directive after it down too -
     * and, when it came first, the entire run.
     *
     * @param string $source
     * @param string $expected
     */
    #[DataProvider('mixedValidityProvider')]
    public function testAnInvalidRunDoesNotSuppressTheValidDirectivesAroundIt(
        string $source,
        string $expected,
    ): void {
        $converter = CarveConverter::carve();

        $this->assertSame($expected, $converter->render($converter->parse($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mixedValidityProvider(): iterable
    {
        yield 'invalid first, valid after' => [
            "{{ #intro }} and {{ ok.crv }}\n",
            "\\{\\{ \\#intro \\}\\} and {{ ok.crv }}\n",
        ];

        yield 'invalid between two valid' => [
            "{{ a.crv }} x {{ }} y {{ b.crv }}\n",
            "{{ a.crv }} x \\{\\{ \\}\\} y {{ b.crv }}\n",
        ];

        yield 'unterminated first, valid after' => [
            "{{ oops and {{ b.crv }}\n",
            "\\{\\{ oops and {{ b.crv }}\n",
        ];

        // Shape-valid but with a bad option: preserved, and it must not disturb
        // the ordinary directive next to it.
        yield 'bad option beside a valid directive' => [
            "{{ a.crv @bogus:1 }} and {{ b.crv }}\n",
            "{{ a.crv @bogus:1 }} and {{ b.crv }}\n",
        ];
    }

    /**
     * A quoted path arrives with typographic quotes because the core's
     * smart-quotes pass has already rewritten it, so echoing the run verbatim
     * would emit a path no resolver can read.
     */
    public function testQuotedPathIsEmittedWithPlainQuotes(): void
    {
        $converter = CarveConverter::carve();

        $carve = $converter->render($converter->parse("{{ \"my file.crv\" }}\n"));

        $this->assertStringNotContainsString("\u{201c}", $carve);
        $this->assertStringNotContainsString("\u{201d}", $carve);
        $this->assertSame("{{ \"my file.crv\" }}\n", $carve);
    }

    /**
     * @param string $source
     */
    #[DataProvider('literalProvider')]
    public function testTextThatIsNotAWellFormedDirectiveIsStillEscaped(string $source): void
    {
        $converter = CarveConverter::carve();

        $carve = $converter->render($converter->parse($source));

        $this->assertStringContainsString('\\{\\{', $carve);
    }

    /**
     * Only a run that is not SHAPE-well-formed stays prose: no closing `}}`, or
     * no path token to include.
     *
     * @return iterable<string, array{string}>
     */
    public static function literalProvider(): iterable
    {
        yield 'unterminated' => ["{{ oops\n"];
        yield 'empty' => ["{{ }}\n"];
        yield 'empty with padding' => ["{{  }}\n"];
        yield 'no path token' => ["{{ #intro }}\n"];
    }

    /**
     * Verbatim contexts are the escape hatch for an authored literal, so what
     * the core produces there must come back untouched (spec I9).
     *
     * @param string $source
     * @param string $expected
     */
    #[DataProvider('verbatimProvider')]
    public function testDirectivesInVerbatimContextsAreUnchanged(string $source, string $expected): void
    {
        $converter = CarveConverter::carve();

        $this->assertSame($expected, $converter->render($converter->parse($source)));
    }

    /**
     * The fence-with-info case differs from its input only in the canonical
     * space after the fence, which is ordinary serializer behavior; the
     * directive inside it is untouched either way.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function verbatimProvider(): iterable
    {
        yield 'code span' => ["`{{ child.crv }}`\n", "`{{ child.crv }}`\n"];
        yield 'fence' => ["```\n{{ child.crv }}\n```\n", "```\n{{ child.crv }}\n```\n"];
        yield 'fence with info string' => ["```js\n{{ child.crv }}\n```\n", "``` js\n{{ child.crv }}\n```\n"];
        yield 'raw block' => ["```=html\n{{ child.crv }}\n```\n", "```=html\n{{ child.crv }}\n```\n"];
    }

    public function testFormattingIsIdempotent(): void
    {
        $converter = CarveConverter::carve();
        $source = "Intro.\n\n{{ chapter.crv @shift:2 }}\n\nSee {{ \"my file.crv\" }} inline.\n\n"
            . "`{{ literal.crv }}`\n\n{{ oops\n\n{{ child.crv @bogus:1 }}\n\n"
            // An unrecognized token is re-emitted after the options the
            // serializer knows how to order, so this run moves once and then
            // must stand still.
            . "{{ child.crv @bogus:1 #intro }}\n\n"
            . "Many {{ a.crv }} in {{ b.crv }} one {{ c.crv }} paragraph.\n";

        $once = $converter->render($converter->parse($source));
        $twice = $converter->render($converter->parse($once));

        $this->assertSame($once, $twice);
        $this->assertStringContainsString('{{ child.crv #intro @bogus:1 }}', $once);
    }

    /**
     * The INTENT of the escaping fix, which the formatter's own round-trip
     * invariant cannot see: the escaped form rendered to the same literal text,
     * so every suite stayed green while the feature was destroyed. What matters
     * is that EXPANDING the formatted document still does the same thing.
     *
     * It exercises a paragraph holding SEVERAL directives on purpose. While
     * every fixture here used one directive per run, a serializer that handled
     * only the first one satisfied this assertion completely, and the whole
     * multi-directive failure class stayed invisible.
     */
    public function testExpandingAFormattedDocumentMatchesExpandingTheOriginal(): void
    {
        $source = "Intro.\n\n{{ chapter.crv @shift:2 }}\n\nSee {{ \"my file.crv\" }} inline.\n\n"
            . "One {{ note.crv }} two {{ aside.crv }} three {{ note.crv }} four.\n";

        $formatter = CarveConverter::carve();
        $formatted = $formatter->render($formatter->parse($source));

        [$originalHtml, $originalDeps] = $this->expand($source);
        [$formattedHtml, $formattedDeps] = $this->expand($formatted);

        $this->assertSame($originalHtml, $formattedHtml);
        $this->assertSame($originalDeps, $formattedDeps);
        // Guard the guard: a resolver that never ran would make the assertions
        // above pass trivially.
        $this->assertStringContainsString('<h3>Chapter</h3>', $originalHtml);
        $this->assertSame(['chapter.crv', 'my file.crv', 'note.crv', 'aside.crv'], $originalDeps);
        // Every directive in the multi-directive paragraph expanded, so a
        // serializer that dropped the later ones cannot pass.
        $this->assertStringContainsString('One Noted. two Aside. three Noted. four.', $originalHtml);
    }

    /**
     * @return array{string, list<string>}
     */
    private function expand(string $source): array
    {
        $converter = new CarveConverter();
        $expander = new IncludeExpander($this->resolver());
        $html = $converter->render($converter->transform($converter->parse($source), $expander));
        $deps = array_map(
            static fn ($dependency): string => $dependency->getTarget(),
            $expander->getDependencies(),
        );

        return [$html, $deps];
    }

    private function resolver(): IncludeResolverInterface
    {
        return new class implements IncludeResolverInterface {
            /**
             * @throws \RuntimeException
             */
            public function resolve(string $path, IncludeContext $context): string
            {
                return match ($path) {
                    'chapter.crv' => "# Chapter\n\nBody.\n",
                    'my file.crv' => "Spaced.\n",
                    'note.crv' => "Noted.\n",
                    'aside.crv' => "Aside.\n",
                    default => throw new RuntimeException('missing'),
                };
            }
        };
    }
}
