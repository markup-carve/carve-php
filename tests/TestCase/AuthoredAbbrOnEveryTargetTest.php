<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * An authored `abbr` outranks the document definition on EVERY target.
 *
 * markup-carve/carve#1127 ruled that a resolved abbreviation inside such a span
 * contributes only its visible text, and a renderer must not emit the nested
 * expansion. The HTML renderer honoured it; Markdown and ANSI emitted the
 * DEFINITION's text, and the plain target dropped the authored value entirely
 * (markup-carve/carve#1176).
 *
 * Nothing caught it because `45-inline-extensions-11` had a `.html` fixture and
 * no `.md`, `.ansi` or `.txt` sidecar, so three of five targets were unpinned.
 */
class AuthoredAbbrOnEveryTargetTest extends TestCase
{
    /**
     * The corpus shape: a document definition AND an authored value on the span.
     *
     * @var string
     */
    private const WITH_DEFINITION = "*[HTML]: Hyper Text Markup Language\n\n[HTML]{abbr=\"Custom\"}\n";

    /**
     * No definition line at all, so nothing but the span can carry the value.
     *
     * @var string
     */
    private const AUTHORED_ONLY = "[HTML]{abbr=\"Custom\"} only.\n";

    private function ansi(string $source): string
    {
        $converter = new CarveConverter();

        return (new AnsiRenderer(useColors: false))->render($converter->parse($source));
    }

    private function markdown(string $source): string
    {
        $converter = new CarveConverter();

        return (new MarkdownRenderer())->render($converter->parse($source));
    }

    private function plain(string $source): string
    {
        $converter = new CarveConverter();

        return (new PlainTextRenderer())->render($converter->parse($source));
    }

    public function testTheAuthoredValueWinsOnHtml(): void
    {
        $this->assertStringContainsString(
            '<abbr title="Custom">HTML</abbr>',
            (new CarveConverter())->convert(self::WITH_DEFINITION),
        );
    }

    public function testTheAuthoredValueWinsOnMarkdown(): void
    {
        // This emitted `title="Hyper Text Markup Language"` - the definition,
        // taking the override route carve#1127 forbids.
        $this->assertStringContainsString(
            '<abbr title="Custom">HTML</abbr>',
            $this->markdown(self::WITH_DEFINITION),
        );
        $this->assertStringNotContainsString('Hyper Text Markup Language">', $this->markdown(self::WITH_DEFINITION));
    }

    public function testTheAuthoredValueWinsOnAnsi(): void
    {
        $this->assertStringContainsString('HTML (Custom)', $this->ansi(self::WITH_DEFINITION));
        $this->assertStringNotContainsString('(Hyper Text Markup Language)', $this->ansi(self::WITH_DEFINITION));
    }

    /**
     * The target the ticket did not name, and the worst of the three: the value
     * vanished with nothing else carrying it.
     */
    public function testThePlainTargetPrintsAnAuthoredExpansion(): void
    {
        $this->assertStringContainsString('HTML (Custom) only.', $this->plain(self::AUTHORED_ONLY));
    }

    /**
     * The asymmetry this test used to state is gone, and PART 11 §10f is what
     * removed it.
     *
     * An automatic expansion needed no parenthetical here for exactly one
     * reason: the `*[TERM]: expansion` line was emitted verbatim, so the mapping
     * survived once at the definition rather than at every occurrence. §10f
     * takes that line away wherever the expansion is emitted, so this target now
     * writes the same `TERM (expansion)` the terminal does, and an authored
     * value and an automatic one differ only in where the words came from.
     */
    public function testThePlainTargetPrintsAnAutomaticExpansionAndDropsTheLine(): void
    {
        $this->assertSame(
            "The HTML (Long Form) key.\n",
            $this->plain("*[HTML]: Long Form\n\nThe HTML key.\n"),
        );
    }

    /**
     * `{abbr=""}` is the spelling for "mark this, expand nothing" - the HTML
     * target emits a bare `<abbr>`. Collapsing it into the non-empty case would
     * take a distinction away from the author.
     */
    public function testAnEmptyAuthoredAbbrPrintsNoExpansion(): void
    {
        $this->assertSame("HTML\n", $this->plain("[HTML]{abbr=\"\"}\n"));
        $this->assertStringContainsString('<abbr>HTML</abbr>', (new CarveConverter())->convert("[HTML]{abbr=\"\"}\n"));
    }
}
