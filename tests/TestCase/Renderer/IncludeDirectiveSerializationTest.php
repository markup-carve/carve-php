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
     * @return iterable<string, array{string}>
     */
    public static function literalProvider(): iterable
    {
        yield 'unterminated' => ["{{ oops\n"];
        yield 'unknown option' => ["{{ child.crv @bogus:1 }}\n"];
        yield 'malformed option' => ["{{ child.crv notanoption }}\n"];
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
            . "`{{ literal.crv }}`\n\n{{ oops\n";

        $once = $converter->render($converter->parse($source));
        $twice = $converter->render($converter->parse($once));

        $this->assertSame($once, $twice);
    }

    /**
     * The INTENT of the escaping fix, which the formatter's own round-trip
     * invariant cannot see: the escaped form rendered to the same literal text,
     * so every suite stayed green while the feature was destroyed. What matters
     * is that EXPANDING the formatted document still does the same thing.
     */
    public function testExpandingAFormattedDocumentMatchesExpandingTheOriginal(): void
    {
        $source = "Intro.\n\n{{ chapter.crv @shift:2 }}\n\nSee {{ \"my file.crv\" }} inline.\n";

        $formatter = CarveConverter::carve();
        $formatted = $formatter->render($formatter->parse($source));

        [$originalHtml, $originalDeps] = $this->expand($source);
        [$formattedHtml, $formattedDeps] = $this->expand($formatted);

        $this->assertSame($originalHtml, $formattedHtml);
        $this->assertSame($originalDeps, $formattedDeps);
        // Guard the guard: a resolver that never ran would make the assertions
        // above pass trivially.
        $this->assertStringContainsString('<h3>Chapter</h3>', $originalHtml);
        $this->assertSame(['chapter.crv', 'my file.crv'], $originalDeps);
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
                    default => throw new RuntimeException('missing'),
                };
            }
        };
    }
}
