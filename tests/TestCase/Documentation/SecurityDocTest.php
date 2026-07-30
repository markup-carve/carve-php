<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Documentation;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\LinkPolicy;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\SafeMode;
use PHPUnit\Framework\TestCase;

/**
 * Pins the claims made in docs/security.md.
 *
 * That page tells people how to render untrusted input; if any behavior it
 * describes changes, the docs become a security bug rather than a stale file.
 * Every assertion here corresponds to a statement or table row on that page.
 */
class SecurityDocTest extends TestCase
{
    /**
     * @var string
     */
    private const RAW_BLOCK = "```=html\n<b onclick=\"steal()\">x</b>\n```";

    /**
     * @var string
     */
    private const RAW_INLINE = 'text `<b>x</b>`{=html} tail';

    public function testRawPassthroughIsLiveWithoutSafeMode(): void
    {
        // The "What is NOT safe by default" section rests on this.
        $html = (new CarveConverter())->convert(self::RAW_BLOCK);

        $this->assertStringContainsString('<b onclick="steal()">', $html);
    }

    public function testRawBlockIsEscapedUnderSafeModeAndStrippedUnderStrict(): void
    {
        $escaped = (new CarveConverter(safeMode: true))->convert(self::RAW_BLOCK);
        $stripped = (new CarveConverter(safeMode: SafeMode::strict()))->convert(self::RAW_BLOCK);

        $this->assertStringNotContainsString('<b onclick', $escaped);
        $this->assertStringContainsString('&lt;b onclick', $escaped);

        $this->assertStringNotContainsString('<b onclick', $stripped);
        $this->assertStringNotContainsString('&lt;b onclick', $stripped);
    }

    public function testInlineRawFollowsTheSameThreeStates(): void
    {
        $live = (new CarveConverter())->convert(self::RAW_INLINE);
        $escaped = (new CarveConverter(safeMode: true))->convert(self::RAW_INLINE);
        $stripped = (new CarveConverter(safeMode: SafeMode::strict()))->convert(self::RAW_INLINE);

        $this->assertStringContainsString('<b>x</b>', $live);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $escaped);
        $this->assertStringNotContainsString('&lt;b&gt;', $stripped);
    }

    public function testScriptTextAndJavascriptSchemeAreHandledWithoutOptingIn(): void
    {
        // The page promises these two need no configuration.
        $html = (new CarveConverter())->convert('Hi <script>alert(1)</script> [x](javascript:alert(1))');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function testStrictAlsoBlocksTheStyleAttribute(): void
    {
        $strict = new CarveConverter(safeMode: SafeMode::strict());

        $this->assertStringNotContainsString('style=', $strict->convert('[x]{style="color:red"}'));
    }

    public function testCommentProfileDeniesHeadingsAndExplainsWhy(): void
    {
        $converter = new CarveConverter();
        $converter->setProfile(Profile::comment());

        $html = $converter->convert("# Heading\n\ntext");
        $violations = $converter->getProfileViolations();

        $this->assertStringNotContainsString('<h1', $html);
        $this->assertNotSame([], $violations, 'a denied construct must be reported');
        $this->assertStringContainsString('heading', $violations[0]->getMessage());
    }

    public function testProfilePresetsExist(): void
    {
        // The preset table lists exactly these four.
        foreach (['full', 'article', 'comment', 'minimal'] as $preset) {
            $this->assertInstanceOf(Profile::class, Profile::{$preset}());
        }
    }

    public function testLinkPolicyChecksUrlsTheSameWayTheRendererDoes(): void
    {
        $policy = (new LinkPolicy())
            ->setAllowedSchemes(['https', 'mailto'])
            ->setDeniedDomains(['spam.example']);

        $this->assertTrue($policy->isUrlAllowed('https://ok.example/page'));
        $this->assertFalse($policy->isUrlAllowed('http://ok.example/page'));
        $this->assertFalse($policy->isUrlAllowed('https://spam.example/page'));
    }

    public function testSafeModeDefaultsMatchTheDocumentedTable(): void
    {
        $defaults = SafeMode::defaults();

        $this->assertSame(SafeMode::RAW_HTML_ESCAPE, $defaults->getRawHtmlMode());
        $this->assertSame(['javascript', 'vbscript', 'data', 'file'], $defaults->getDangerousSchemes());
        $this->assertNull($defaults->getAllowedSchemes());

        $this->assertSame(SafeMode::RAW_HTML_STRIP, SafeMode::strict()->getRawHtmlMode());
    }
}
