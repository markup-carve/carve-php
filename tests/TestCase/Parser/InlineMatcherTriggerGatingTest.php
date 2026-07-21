<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\AutolinkExtension;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Extension\WikilinksExtension;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end checks that the trigger-byte gate for extension inline matchers is
 * a pure optimization: each extension still fires on inputs that DO contain its
 * trigger byte, and leaves inputs that merely contain the trigger byte in a
 * non-matching context (or no trigger at all) untouched.
 */
class InlineMatcherTriggerGatingTest extends TestCase
{
    public function testWikilinkFiresOnDoubleBracketTrigger(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new WikilinksExtension());

        $html = $converter->convert('See [[Home Page]] now.');

        $this->assertStringContainsString('<a href="', $html);
        $this->assertStringContainsString('Home Page', $html);
    }

    public function testWikilinkLeavesPlainBracketLinkAlone(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new WikilinksExtension());

        // A `[` that is a normal inline link, not a wikilink.
        $html = $converter->convert('A [label](https://example.com) link.');

        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('>label<', $html);
    }

    public function testAutolinkFiresOnUrl(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new AutolinkExtension());

        $html = $converter->convert('Visit https://example.com today.');

        $this->assertStringContainsString('<a href="https://example.com">', $html);
    }

    public function testAutolinkIgnoresHMidWord(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new AutolinkExtension());

        // Plenty of `h`/`f`/`m` bytes (autolink triggers) but no URL.
        $html = $converter->convert('The fish him from fame.');

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('The fish him from fame.', $html);
    }

    public function testCitationFiresOnBracketAtSign(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension());

        $document = $converter->parse('As shown [@smith2020].');
        $paragraph = $document->getChildren()[0];

        $types = array_map(
            static fn ($node): string => $node->getType(),
            $paragraph->getChildren(),
        );
        $this->assertContains('citation_group', $types);
    }

    public function testCitationLeavesPlainBracketRunAlone(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension());

        // A `[` that is not a citation (no @key) must stay literal text.
        $document = $converter->parse('Just [some bracketed text] here.');
        $paragraph = $document->getChildren()[0];

        $types = array_map(
            static fn ($node): string => $node->getType(),
            $paragraph->getChildren(),
        );
        $this->assertNotContains('citation_group', $types);

        $html = $converter->convert('Just [some bracketed text] here.');
        $this->assertStringContainsString('[some bracketed text]', $html);
    }

    public function testAllThreeExtensionsTogetherProduceCorrectOutput(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new WikilinksExtension());
        $converter->addExtension(new AutolinkExtension());
        $converter->addExtension(new CitationsExtension());

        $html = $converter->convert(
            'See [[Wiki Page]], visit https://example.com, cite [@smith2020], '
            . "and a plain [label](https://example.org) link with the letter h in words.\n\n"
            . '[@smith2020]: A cited work.',
        );

        $this->assertStringContainsString('Wiki Page', $html);
        $this->assertStringContainsString('<a href="https://example.com">', $html);
        $this->assertStringContainsString('href="#ref-smith2020"', $html);
        $this->assertStringContainsString('href="https://example.org"', $html);
    }

    /**
     * With all three trigger-gated extensions enabled, trigger-free prose must
     * still render byte-identically to the minimal converter (the gate skips
     * every matcher, the fast path skips every char).
     */
    public function testTriggerFreeProseMatchesMinimalConverter(): void
    {
        $prose = 'Plain prose with no special syntax, just words and a period.';

        $minimal = (new CarveConverter())->convert($prose);

        $full = new CarveConverter();
        $full->addExtension(new WikilinksExtension());
        $full->addExtension(new AutolinkExtension());
        $full->addExtension(new CitationsExtension());

        $this->assertSame($minimal, $full->convert($prose));
    }
}
