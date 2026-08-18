<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use LogicException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\HeadingPermalinksExtension;
use MarkupCarve\Carve\Extension\HeadingReferenceExtension;
use MarkupCarve\Carve\Extension\WikilinksExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class HeadingReferenceExtensionTest extends TestCase
{
    public function testBasicHeadingReference(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Getting Started]].

# Getting Started
DJOT);

        $this->assertStringContainsString('href="#Getting-Started"', $html);
        $this->assertStringContainsString('class="heading-ref"', $html);
        $this->assertStringNotContainsString('data-heading-ref=', $html);
    }

    public function testReferenceUsesExplicitHeadingId(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Installation]].

{#install}
## Installation
DJOT);

        $this->assertStringContainsString('href="#install"', $html);
    }

    public function testCustomDisplayText(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Getting Started|the introduction]] for details.

# Getting Started
DJOT);

        $this->assertStringContainsString('href="#Getting-Started"', $html);
        $this->assertStringContainsString('>the introduction</a>', $html);
        $this->assertStringNotContainsString('data-heading-ref=', $html);
    }

    public function testCustomDisplayTextFallbackOnMissing(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert('See [[Missing|click here]].');

        // Falls back to literal syntax including display text
        $this->assertStringContainsString('[[Missing|click here]]', $html);
    }

    public function testDuplicateHeadingFallsBackToLiteralText(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Installation]].

## Installation

## Installation
DJOT);

        $this->assertStringContainsString('[[Installation]]', $html);
        $this->assertStringNotContainsString('data-heading-ref="Installation"', $html);
    }

    public function testMissingHeadingFallsBackToLiteralText(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert('See [[Missing Heading]].');

        $this->assertStringContainsString('[[Missing Heading]]', $html);
        $this->assertStringNotContainsString('data-heading-ref="Missing Heading"', $html);
    }

    public function testHashSyntaxIsNotConsumed(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert('See [[#installation]].');

        // Tags are core Carve syntax (on by default), so #installation
        // is a tag even inside [[ ]]; the brackets stay literal. Matches
        // the carve-js reference.
        $this->assertStringContainsString('[[<span class="tag"><strong>#installation</strong></span>]]', $html);
        $this->assertStringNotContainsString('href="#installation"', $html);
    }

    public function testWorksWithHeadingPermalinks(): void
    {
        $converter = new CarveConverter();
        $converter
            ->addExtension(new HeadingReferenceExtension())
            ->addExtension(new HeadingPermalinksExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Summary]].

## Summary
DJOT);

        $this->assertStringContainsString('href="#Summary"', $html);
        $this->assertStringContainsString('class="permalink"', $html);
    }

    public function testParseThenRenderAppliesOutputTransformer(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $document = $converter->parse(<<<'DJOT'
See [[Getting Started]].

# Getting Started
DJOT);

        $html = $converter->render($document);

        $this->assertStringContainsString('href="#Getting-Started"', $html);
        $this->assertStringNotContainsString('__djot_heading_ref_', $html);
    }

    public function testOlderParsedDocumentStillResolvesAfterParsingNewerDocument(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $first = $converter->parse(<<<'DJOT'
See [[One]].

# One
DJOT);

        $converter->parse(<<<'DJOT'
See [[Two]].

# Two
DJOT);

        $html = $converter->render($first);

        $this->assertStringContainsString('href="#One"', $html);
        $this->assertStringNotContainsString('__djot_heading_ref_', $html);
    }

    public function testHeadingWithSmartQuotesMatchesStraightQuoteReference(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        // The parser converts straight quotes to smart quotes in heading text,
        // but reference targets keep straight quotes. The extension normalizes
        // quotes for matching so this should resolve correctly.
        $html = $converter->convert(<<<'DJOT'
See [[Say "Hello"]].

# Say "Hello"
DJOT);

        // Smart typography is reversed to ASCII before the id is computed, so
        // the curly quotes do not leak into the id (`Say "Hello"` -> id
        // `Say-Hello`); the wiki reference still resolves on heading text.
        $this->assertStringContainsString('href="#Say-Hello"', $html);
        $this->assertStringNotContainsString('[[Say "Hello"]]', $html);
    }

    public function testHeadingWithFormattingMatchesPlainTextReference(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Say Hello]].

# Say _Hello_
DJOT);

        $this->assertStringContainsString('href="#Say-Hello"', $html);
    }

    public function testHeadingWithApostropheResolvesCorrectly(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Bob's Guide]].

# Bob's Guide
DJOT);

        // Smart-punctuation turns the straight apostrophe into U+2019, but it
        // is reversed to ASCII before the id is computed, so the curly quote
        // does not leak in: `Bob's Guide` -> `Bob-s-Guide`. The href must match.
        $this->assertStringContainsString('id="Bob-s-Guide"', $html);
        $this->assertStringContainsString('href="#Bob-s-Guide"', $html);
        $this->assertStringNotContainsString('data-heading-ref=', $html);
        $this->assertStringNotContainsString('[[Bob\'s Guide]]', $html);
    }

    public function testCustomCssClassWithMultipleSpaces(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension('foo  bar'));

        $html = $converter->convert(<<<'DJOT'
See [[Test]].

# Test
DJOT);

        // Multiple spaces should be handled, empty parts filtered out
        $this->assertStringContainsString('class="foo bar"', $html);
    }

    public function testHeadingWithNoTextIsIgnored(): void
    {
        // Headings with no plain text (like image-only headings) are skipped
        // and don't cause errors
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Real Heading]].

# ![image](logo.png)

# Real Heading
DJOT);

        // Reference resolves to the text heading, image heading is ignored
        $this->assertStringContainsString('href="#Real-Heading"', $html);
    }

    public function testUserAuthoredLinkWithMatchingPlaceholderIsNotRewritten(): void
    {
        $extension = new class ('heading-ref') extends HeadingReferenceExtension {
            protected function generatePlaceholderPrefix(): string
            {
                return 'collision-placeholder-';
            }
        };

        $converter = new CarveConverter();
        $converter->addExtension($extension);

        $html = $converter->convert(<<<'DJOT'
[outside](collision-placeholder-0__)

See [[Test]].

# Test
DJOT);

        $this->assertStringContainsString('<a href="collision-placeholder-0__">outside</a>', $html);
        $this->assertStringContainsString('href="#Test"', $html);
    }

    // A WALL-CLOCK BOUND, so it measures the runner as much as the code and
    // belongs on the one that runs alone. phpunit.xml.dist says why the group
    // exists: the measurement "is only meaningful on an unloaded runner".
    #[Group('scaling')]
    public function testManyReferencesResolveFast(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());
        $source = str_repeat('[[Target]] ', 1500) . "\n\n# Target";
        $start = microtime(true);

        $html = $converter->convert($source);

        $this->assertSame(1500, substr_count($html, 'href="#Target"'));
        $this->assertLessThan(1.5, microtime(true) - $start);
    }

    public function testConflictsWithWikilinksWhenAddedAfter(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new WikilinksExtension());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('HeadingReferenceExtension cannot be used together with WikilinksExtension');

        $converter->addExtension(new HeadingReferenceExtension());
    }

    public function testConflictsWithWikilinksWhenAddedBefore(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('HeadingReferenceExtension cannot be used together with WikilinksExtension');

        $converter->addExtension(new WikilinksExtension());
    }
}
