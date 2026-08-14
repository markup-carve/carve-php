<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §10f: a REFERENCED abbreviation definition splits by target.
 *
 * §10a rules the definition NOTHING references - Markdown, plain text and the
 * terminal all emit it, because those targets do not get to drop content the
 * author wrote. It said nothing about the definition that IS referenced, and
 * each of the three got that one wrong in its own way: two emitted the same
 * words twice, and plain put a line of Carve source in a plain-text document
 * while never printing the expansion at all.
 *
 * T1 MARKDOWN KEEPS THE LINE and the expansion beside it. `*[TERM]: expansion`
 * is PHP Markdown Extra's own spelling, so there the line is CONTENT rather than
 * leaked source, and it is what lets the export round-trip.
 *
 * T2 PLAIN TEXT AND THE TERMINAL DROP THE LINE and emit only the expansion, in
 * the `TERM (expansion)` shape, at every occurrence.
 *
 * THE TEST IS WHETHER THIS DEFINITION'S EXPANSION IS EMITTED, not whether its
 * term appears. Three shapes have a term that appears while this definition's
 * expansion reaches no target, and each keeps its line on every target.
 *
 * EVERY EXPECTATION HERE IS A WHOLE RENDERED STRING. A containment assertion
 * would pass on output where the definition line merely moved or got glued to
 * the neighboring text, which is the failure mode this clause is about.
 */
class ReferencedAbbreviationDefinitionSplitsByTargetTest extends TestCase
{
    /**
     * SGR 2, built from an escape rather than pasted.
     *
     * A literal control character in a fixture survives an author but not
     * necessarily a formatter, and a test asserting on the wrong bytes still
     * passes if the renderer is compared against the same wrong bytes.
     *
     * @var string
     */
    private const DIM = "\033[2m";

    /**
     * SGR 0.
     *
     * @var string
     */
    private const RESET = "\033[0m";

    private function plain(string $source): string
    {
        return CarveConverter::plainText()->convert($source);
    }

    private function ansi(string $source): string
    {
        return CarveConverter::ansi()->convert($source);
    }

    private function markdown(string $source): string
    {
        return CarveConverter::markdown()->convert($source);
    }

    private function carve(string $source): string
    {
        return CarveConverter::create()->toCarve($source);
    }

    /**
     * The ruled example, on the target that changes on both halves.
     */
    public function testThePlainTargetDropsTheLineAndPrintsTheExpansion(): void
    {
        $this->assertSame(
            "HTML (Hyper Text)\n",
            $this->plain("*[HTML]: Hyper Text\n\nHTML\n"),
        );
    }

    /**
     * The same words, with the expansion dim, and the line gone.
     */
    public function testTheTerminalDropsTheLineAndKeepsItsExpansion(): void
    {
        $this->assertSame(
            'HTML' . self::DIM . ' (Hyper Text)' . self::RESET . "\n",
            $this->ansi("*[HTML]: Hyper Text\n\nHTML\n"),
        );
    }

    /**
     * T1. The duplication is the price of the round trip and it is paid here.
     */
    public function testTheMarkdownTargetKeepsTheLineAndTheExpansion(): void
    {
        $this->assertSame(
            "*[HTML]: Hyper Text\n\n<abbr title=\"Hyper Text\">HTML</abbr>\n",
            $this->markdown("*[HTML]: Hyper Text\n\nHTML\n"),
        );
    }

    /**
     * The canonical writer, where PART 11 §1's `parse(fmt(x)) == parse(x)`
     * requires the line whatever became of the term.
     */
    public function testTheCanonicalWriterKeepsTheLine(): void
    {
        $source = "*[HTML]: Hyper Text\n\nHTML\n";
        $once = $this->carve($source);

        $this->assertSame($source, $once);
        $this->assertSame($once, $this->carve($once));
    }

    /**
     * At EVERY occurrence, not only the first - the line that carried the
     * mapping once is gone, so a single expansion would leave the second
     * occurrence unexplained.
     */
    public function testEveryOccurrenceCarriesTheExpansion(): void
    {
        $source = "*[HTML]: Hyper Text\n\nHTML and HTML again.\n";

        $this->assertSame(
            "HTML (Hyper Text) and HTML (Hyper Text) again.\n",
            $this->plain($source),
        );
        $this->assertSame(
            'HTML' . self::DIM . ' (Hyper Text)' . self::RESET
                . ' and HTML' . self::DIM . ' (Hyper Text)' . self::RESET . " again.\n",
            $this->ansi($source),
        );
    }

    /**
     * Exception 1: the term never appears. §10a, untouched.
     */
    public function testAnUnreferencedDefinitionKeepsItsLineOnEveryTarget(): void
    {
        $source = "*[HTML]: Hyper Text\n\nnothing here\n";

        $this->assertSame("*[HTML]: Hyper Text\n\nnothing here\n", $this->plain($source));
        $this->assertSame(
            self::DIM . '*[HTML]: Hyper Text' . self::RESET . "\n\nnothing here\n",
            $this->ansi($source),
        );
        $this->assertSame("*[HTML]: Hyper Text\n\nnothing here\n", $this->markdown($source));
    }

    /**
     * Exception 2: an authored `abbr` outranks the definition (PART 9 §9), so
     * the definition's own expansion reaches no target and its line is the only
     * copy of those words. `HTML (Custom)`, with the line above it, is exactly
     * what carve#1178 pinned and this clause leaves alone.
     */
    public function testAnAuthoredAbbrLeavesTheDefinitionItsLine(): void
    {
        $source = "*[HTML]: Hyper Text Markup Language\n\n[HTML]{abbr=\"Custom\"}\n";

        $this->assertSame(
            "*[HTML]: Hyper Text Markup Language\n\nHTML (Custom)\n",
            $this->plain($source),
        );
        $this->assertSame(
            self::DIM . '*[HTML]: Hyper Text Markup Language' . self::RESET
                . "\n\nHTML" . self::DIM . ' (Custom)' . self::RESET . "\n",
            $this->ansi($source),
        );
    }

    /**
     * `{abbr=""}` is "mark this, expand nothing". The automatic expansion is
     * suppressed the same way, so the definition's words reach no target and its
     * line stays - the empty value is authored, not absent.
     */
    public function testAnEmptyAuthoredAbbrAlsoLeavesTheDefinitionItsLine(): void
    {
        $source = "*[HTML]: Hyper Text\n\n[HTML]{abbr=\"\"}\n";

        $this->assertSame("*[HTML]: Hyper Text\n\nHTML\n", $this->plain($source));
        $this->assertSame(
            self::DIM . '*[HTML]: Hyper Text' . self::RESET . "\n\nHTML\n",
            $this->ansi($source),
        );
    }

    /**
     * Exception 3: a later definition of the same term won (PART 9R R3), so only
     * `b` is ever emitted. `*[A]: b` goes and `*[A]: a` stays. Dropping both was
     * considered and rejected upstream: it deletes the string `a` outright.
     */
    public function testAShadowedDefinitionKeepsItsLineAndTheWinnerLosesIts(): void
    {
        $source = "*[A]: a\n*[A]: b\n\nA here.\n";

        $this->assertSame("*[A]: a\n\nA (b) here.\n", $this->plain($source));
        $this->assertSame(
            self::DIM . '*[A]: a' . self::RESET
                . "\n\nA" . self::DIM . ' (b)' . self::RESET . " here.\n",
            $this->ansi($source),
        );
        $this->assertSame(
            "*[A]: a\n\n*[A]: b\n\n<abbr title=\"b\">A</abbr> here.\n",
            $this->markdown($source),
        );
        $this->assertSame("*[A]: a\n\n*[A]: b\n\nA here.\n", $this->carve($source));
    }

    /**
     * Two definitions, one consumed and one not, in one document: the decision
     * is per definition rather than per document.
     */
    public function testOnlyTheConsumedDefinitionLosesItsLine(): void
    {
        $source = "*[HTML]: Hyper Text\n*[CSS]: Cascading\n\nHTML only.\n";

        $this->assertSame("*[CSS]: Cascading\n\nHTML (Hyper Text) only.\n", $this->plain($source));
        $this->assertSame(
            self::DIM . '*[CSS]: Cascading' . self::RESET
                . "\n\nHTML" . self::DIM . ' (Hyper Text)' . self::RESET . " only.\n",
            $this->ansi($source),
        );
    }

    /**
     * A definition with no source line of its own - the API path the AST codec
     * and the ProseMirror bridge use - is decided by the same test, and it is
     * decided on the (TERM, EXPANSION) PAIR.
     *
     * The second assertion is the one that pins the pair: the same term expands
     * to something else at the occurrence, so THIS definition's words reach no
     * target and its line survives. Keying on the term alone would drop it.
     */
    public function testTheApiPathIsDecidedByTheSameTest(): void
    {
        $this->assertSame(
            "intro\n\nHTML (Hyper Text)\n",
            (new PlainTextRenderer())->render($this->apiDocument('Hyper Text')),
        );
        $this->assertSame(
            'intro' . "\n\nHTML" . self::DIM . ' (Hyper Text)' . self::RESET . "\n",
            (new AnsiRenderer())->render($this->apiDocument('Hyper Text')),
        );
        $this->assertSame(
            "intro\n\nHTML (Different)\n\n*[HTML]: Hyper Text\n",
            (new PlainTextRenderer())->render($this->apiDocument('Different')),
        );
    }

    /**
     * THE DEGRADED CASE. Once the PART 9 §25 expansion budget is spent, every
     * later occurrence falls back to its bare key - so that definition's
     * expansion is NOT emitted, and its line is the only copy of those words
     * left. Dropping it would delete the author's text outright, which is the
     * content loss this whole clause moves things to avoid.
     *
     * The first definition, whose occurrences DID expand before the budget ran
     * out, still loses its line. Both halves in one document, because a rule
     * that answered "keep" for everything would pass half of this on its own.
     */
    public function testABudgetDegradedOccurrenceLeavesItsDefinitionTheLine(): void
    {
        $wide = str_repeat('x', 50000);
        $source = '*[QQ]: ' . $wide . "\n*[ZZ]: zzz\n\n"
            . str_repeat('QQ ', 25) . "\n\nZZ at the end.\n";

        foreach ([$this->plain($source), $this->ansi($source)] as $out) {
            $this->assertStringContainsString('*[ZZ]: zzz', $out);
            $this->assertStringNotContainsString('*[QQ]: ' . $wide, $out);
            $this->assertStringNotContainsString('ZZ (zzz)', $out);
        }
    }

    /**
     * The other degraded case, on the target that dispatches render events: a
     * listener that prevents the default takes the expansion with it, so the
     * definition's words again reach no output and its line stays.
     */
    public function testAListenerThatSuppressesTheOccurrenceLeavesTheLine(): void
    {
        $renderer = new PlainTextRenderer();
        $renderer->on('render.abbreviation', static function (RenderEvent $event): void {
            $event->setHtml('HTML');
            $event->preventDefault();
        });

        $document = CarveConverter::create()->parse("*[HTML]: Hyper Text\n\nHTML\n");

        $this->assertSame("*[HTML]: Hyper Text\n\nHTML\n", $renderer->render($document));
    }

    /**
     * The deferred line is written through a placeholder, and a placeholder that
     * survived into the output would be a private-use code point sitting in a
     * plain-text document. A definition inside a container defines nothing
     * (markup-carve/carve#601), so its line is kept - through a container that
     * rewrites the start of every line it holds.
     */
    public function testADefinitionInsideAContainerKeepsItsLineIntact(): void
    {
        $this->assertSame(
            "\"*[HTML]: Hyper Text\"\n\nHTML\n",
            $this->plain("> *[HTML]: Hyper Text\n\nHTML\n"),
        );
    }

    /**
     * A document holding its definition only as a map entry, plus an occurrence
     * built as the ingest path builds one.
     */
    private function apiDocument(string $emittedExpansion): Document
    {
        $document = CarveConverter::create()->parse("intro\n");
        $document->setAbbreviations(['HTML' => 'Hyper Text']);

        $paragraph = new Paragraph();
        $abbreviation = new Abbreviation($emittedExpansion);
        $abbreviation->appendChild(new Text('HTML'));
        $paragraph->appendChild($abbreviation);
        $document->appendChild($paragraph);

        return $document;
    }
}
