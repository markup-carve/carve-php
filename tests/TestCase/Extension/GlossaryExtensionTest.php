<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\GlossaryExtension;
use PHPUnit\Framework\TestCase;

class GlossaryExtensionTest extends TestCase
{
    /**
     * @var string
     */
    private const GLOSS = "::: glossary\n:: HTTP\n:  HyperText Transfer Protocol.\n:::";

    private function html(string $source): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new GlossaryExtension());

        return trim($converter->convert($source));
    }

    public function testRendersGlossaryBlockAsDlWithIds(): void
    {
        $out = $this->html("::: glossary\n:: HTTP\n:  HyperText Transfer Protocol.\n\n:: HTML\n:  HyperText Markup Language.\n:::");

        $this->assertStringContainsString('<dl class="glossary">', $out);
        $this->assertStringContainsString('<dt id="gloss-http">HTTP</dt>', $out);
        $this->assertStringContainsString('<dd>HyperText Transfer Protocol.</dd>', $out);
        $this->assertStringContainsString('<dt id="gloss-html">HTML</dt>', $out);
    }

    public function testLinksTermToDefinedEntry(): void
    {
        $out = $this->html("Use :term[HTTP].\n\n" . self::GLOSS);
        $this->assertStringContainsString('<a href="#gloss-http" class="term">HTTP</a>', $out);
    }

    public function testDegradesUndefinedTermToSpan(): void
    {
        $out = $this->html("Use :term[FTP].\n\n" . self::GLOSS);
        $this->assertStringContainsString('<span class="term">FTP</span>', $out);
        $this->assertStringNotContainsString('href="#gloss-ftp"', $out);
    }

    public function testRendersEntriesInSourceOrder(): void
    {
        $out = $this->html("::: glossary\n:: HTTP\n:  One.\n\n:: HTML\n:  Two.\n:::");
        $this->assertLessThan(strpos($out, 'gloss-html'), strpos($out, 'gloss-http'));
    }

    public function testDuplicateSlugOnlyFirstGetsId(): void
    {
        $out = $this->html("::: glossary\n:: HTTP\n:  One.\n\n:: HTTP\n:  Two.\n:::");
        $this->assertSame(1, substr_count($out, 'id="gloss-http"'));
        $this->assertStringContainsString('<dt>HTTP</dt>', $out);
    }

    public function testGenericFallbackWhenDisabled(): void
    {
        $out = trim((new CarveConverter())->convert('Use :term[HTTP].'));
        $this->assertStringContainsString('<span class="ext-term">HTTP</span>', $out);
    }

    public function testPreservesIntroProseAndSecondList(): void
    {
        $out = $this->html("::: glossary\nProtocols below.\n\n:: HTTP\n:  One.\n\n:: FTP\n:  Two.\n:::");
        $this->assertStringContainsString('Protocols below.', $out);
        $this->assertStringContainsString('<dt id="gloss-http">HTTP</dt>', $out);
        $this->assertStringContainsString('<dt id="gloss-ftp">FTP</dt>', $out);
    }

    public function testKeepsTrailingNoteInSourceOrder(): void
    {
        $out = $this->html("::: glossary\n:: HTTP\n:  One.\n\nSee the RFCs.\n:::");
        $this->assertLessThan(strpos($out, 'See the RFCs.'), strpos($out, 'gloss-http'));
    }

    public function testCarriesInlineAttributesOnTerm(): void
    {
        $out = $this->html("Use :term[HTTP]{.abbr #use}.\n\n" . self::GLOSS);
        $this->assertStringContainsString('href="#gloss-http"', $out);
        $this->assertStringContainsString('id="use"', $out);
        $this->assertStringContainsString('class="term abbr"', $out);
    }

    public function testDropsAuthorHref(): void
    {
        $out = $this->html("Use :term[HTTP]{href=\"#other\"}.\n\n" . self::GLOSS);
        $this->assertStringContainsString('<a href="#gloss-http" class="term">HTTP</a>', $out);
        $this->assertStringNotContainsString('#other', $out);
    }

    public function testDropsAuthorHrefCaseInsensitively(): void
    {
        $out = $this->html("Use :term[HTTP]{HREF=\"#other\"}.\n\n" . self::GLOSS);
        $this->assertStringNotContainsString('#other', $out);
        $this->assertSame(1, substr_count(strtolower($out), 'href='));
    }

    public function testPreservesAuthoredAttributesOnDl(): void
    {
        $out = $this->html("{#terms .wide}\n::: glossary\n:: HTTP\n:  One.\n:::");
        $this->assertStringContainsString('<dl id="terms" class="glossary wide">', $out);
    }

    public function testFindsGlossaryNestedInBlockquote(): void
    {
        $out = $this->html("Use :term[HTTP].\n\n> ::: glossary\n> :: HTTP\n> :  HyperText Transfer Protocol.\n> :::");
        $this->assertStringContainsString('<dt id="gloss-http">HTTP</dt>', $out);
        $this->assertStringContainsString('<a href="#gloss-http" class="term">HTTP</a>', $out);
    }

    public function testStableIdsAcrossTwoRendersOfSameInstance(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new GlossaryExtension());
        $converter->convert(self::GLOSS);
        $out = trim($converter->convert(self::GLOSS));
        $this->assertStringContainsString('<dt id="gloss-http">HTTP</dt>', $out);
    }
}
