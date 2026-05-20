<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\HeadingPermalinksExtension;
use Carve\Extension\HeadingReferenceExtension;
use Carve\Extension\WikilinksExtension;
use LogicException;
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

        $this->assertStringContainsString('href="#getting-started"', $html);
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

        $this->assertStringContainsString('href="#getting-started"', $html);
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
        $this->assertStringContainsString('[[<a class="tag" href="/tags/installation">#installation</a>]]', $html);
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

        $this->assertStringContainsString('href="#summary"', $html);
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

        $this->assertStringContainsString('href="#getting-started"', $html);
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

        $this->assertStringContainsString('href="#one"', $html);
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

        $this->assertStringContainsString('href="#say-hello"', $html);
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

        $this->assertStringContainsString('href="#say-hello"', $html);
    }

    public function testHeadingWithApostropheResolvesCorrectly(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Bob's Guide]].

# Bob's Guide
DJOT);

        // Smart-punctuation turns the straight apostrophe into U+2019, then
        // ASCII transliteration folds it back to a straight `'`, which the
        // normalize step drops along with other CSS-unsafe punctuation:
        // `Bob's Guide` -> `bobs-guide`. The href must match the heading id.
        $this->assertStringContainsString('id="bobs-guide"', $html);
        $this->assertStringContainsString('href="#bobs-guide"', $html);
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
        $this->assertStringContainsString('href="#real-heading"', $html);
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
        $this->assertStringContainsString('href="#test"', $html);
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
