<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\Node\Block\Paragraph;
use Carve\Node\Document;
use Carve\Renderer\AnsiRenderer;
use Carve\Renderer\HtmlRenderer;
use Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Severity-1 robustness: adversarial / programmatic input must not crash.
 */
class Sev1RobustnessTest extends TestCase
{
    public function testHugeFenceRunDoesNotThrowPcreQuantifierError(): void
    {
        // A fence run > 65535 chars used to be interpolated into a `{N,}`
        // quantifier, which PCRE rejects ("number too big in {} quantifier").
        $converter = new CarveConverter();
        $out = $converter->convert(str_repeat('`', 70000) . "\n");
        $this->assertIsString($out);

        // closers still match correctly at normal lengths
        $this->assertStringContainsString(
            '<pre><code class="language-php">',
            $converter->convert("````php\nx\n````\n"),
        );
    }

    public function testProgrammaticDigitKeyAttributeDoesNotThrowTypeError(): void
    {
        // PHP coerces an all-digit array key to int; renderAttributeArray must
        // cast it back to string before escape() instead of throwing TypeError.
        $doc = new Document();
        $p = new Paragraph();
        $p->setAttributes(['123' => 'v']);
        $doc->appendChild($p);
        $out = (new HtmlRenderer())->render($doc);
        $this->assertIsString($out);
    }

    /**
     * A huge abbreviation definition occurring many times used to re-emit the
     * full expansion per occurrence (output = expansion_len * occurrence_count),
     * a RAM-exhaustion DoS (hundreds of MB allocated). The render now bounds the
     * cumulative expansion bytes to max(1_000_000, 8 * sourceBytes); beyond that,
     * each occurrence degrades to its plain key text.
     */
    public function testAbbreviationExpansionIsBoundedAndDoesNotExhaustMemory(): void
    {
        $expansion = str_repeat('A', 50000);
        $occurrences = 2000;
        $source = "*[HT]: {$expansion}\n\n" . str_repeat('HT ', $occurrences) . "\n";

        // Budget is the floor here (source ~56 KB; 8 * 56 KB < 1 MB).
        $budget = max(1000000, 8 * strlen($source));

        $converter = new CarveConverter();
        $document = $converter->parse($source);

        // Naive amplification would be ~100 MB (50_000 * 2_000); all three
        // display targets must instead stay within a small multiple of budget.
        $html = $converter->render($document);
        $this->assertLessThan($budget + 100000, strlen($html));

        $markdown = (new MarkdownRenderer())->render($document);
        $this->assertLessThan($budget + 100000, strlen($markdown));

        $ansi = (new AnsiRenderer())->render($document);
        $this->assertLessThan($budget + 100000, strlen($ansi));

        // The first occurrences still expand (the budget is generous), but not
        // all of them - degradation kicked in well before the 2_000th.
        $emitted = substr_count($html, '<abbr ');
        $this->assertGreaterThan(0, $emitted);
        $this->assertLessThan($occurrences, $emitted);
    }

    /**
     * Regression: an ordinary, in-budget abbreviation must keep emitting the
     * full <abbr title="..."> wrapper for every occurrence - the DoS guard only
     * engages once the cumulative expansion would exceed the budget.
     */
    public function testNormalAbbreviationRendersFullyUnderBudget(): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert(
            "*[HTML]: Hyper Text Markup Language\n\nThe HTML spec, and more HTML.\n",
        );

        $this->assertSame(
            '<p>The <abbr title="Hyper Text Markup Language">HTML</abbr> spec, '
            . 'and more <abbr title="Hyper Text Markup Language">HTML</abbr>.</p>',
            trim($html),
        );
    }

    /**
     * The abbreviation budget must not leak across independent renders on the
     * same renderer instance: after a full render exhausts the budget, a later
     * top-level fragment render starts fresh, so a normal abbreviation still
     * emits its <abbr> wrapper rather than degrading to plain text.
     */
    public function testAbbreviationBudgetDoesNotLeakIntoFragmentRender(): void
    {
        $converter = new CarveConverter();
        $renderer = $converter->getHtmlRenderer();

        // First render exhausts the budget.
        $heavy = $converter->parse(
            '*[HT]: ' . str_repeat('A', 60000) . "\n\n" . str_repeat('HT ', 2000) . "\n",
        );
        $renderer->render($heavy);

        // A fresh, in-budget document rendered via the fragment API must not
        // inherit the exhausted counter.
        $small = $converter->parse(
            "*[HTML]: Hyper Text Markup Language\n\nThe HTML spec.\n",
        );
        $fragment = $renderer->renderDocumentFragment($small);

        $this->assertStringContainsString(
            '<abbr title="Hyper Text Markup Language">HTML</abbr>',
            $fragment,
        );
    }

}
