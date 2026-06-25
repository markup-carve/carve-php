<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\SafeMode;
use PHPUnit\Framework\TestCase;

/**
 * Tests for safe mode XSS protection
 */
class SafeModeTest extends TestCase
{
    // ==================== URL Sanitization ====================

    public function testJavascriptUrlInLinkIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[click me](javascript:alert(1))';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('href=""', $result);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testJavascriptUrlInImageIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '![alt](javascript:alert(1))';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('src=""', $result);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testDataUrlIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '![img](data:text/html,<script>alert(1)</script>)';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('src=""', $result);
        $this->assertStringNotContainsString('data:', $result);
    }

    public function testVbscriptUrlIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[click](vbscript:msgbox(1))';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('href=""', $result);
        $this->assertStringNotContainsString('vbscript:', $result);
    }

    public function testFileUrlIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[secret](file:///etc/passwd)';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('href=""', $result);
        $this->assertStringNotContainsString('file:', $result);
    }

    public function testHttpUrlIsAllowed(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[link](https://example.com)';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('href="https://example.com"', $result);
    }

    public function testAllowedLinkUrlIsEscapedInAttributeContext(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[link](https://example.com " onclick="alert)';
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('href=', $result);
        $this->assertStringNotContainsString('" onclick="alert"', $result);
    }

    public function testRelativeUrlIsAllowed(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[link](/path/to/page)';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('href="/path/to/page"', $result);
    }

    public function testMailtoUrlIsAllowed(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[email](mailto:test@example.com)';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('href="mailto:test@example.com"', $result);
    }

    // ==================== Attribute Filtering ====================

    public function testOnclickAttributeIsFiltered(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[text]{onclick="alert(1)"}';
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('onclick', $result);
    }

    public function testOnloadAttributeIsFiltered(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '![img](image.png){onload="alert(1)"}';
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('onload', $result);
    }

    public function testOnerrorAttributeIsFiltered(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '![img](x){onerror="alert(1)"}';
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('onerror', $result);
    }

    public function testOnmouseoverAttributeIsFiltered(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[hover me]{onmouseover="alert(1)"}';
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('onmouseover', $result);
    }

    public function testClassAttributeIsAllowed(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[text]{.highlight}';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('class="highlight"', $result);
    }

    public function testIdAttributeIsAllowed(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[text]{#myid}';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('id="myid"', $result);
    }

    public function testDataAttributeIsAllowed(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[text]{data-value="123"}';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('data-value="123"', $result);
    }

    public function testImageAttributesAreEscapedInAttributeContext(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '![alt "quoted"](image.png){title="title\" onerror=\"alert(1)"}';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('alt="alt “quoted”"', $result);
        $this->assertStringContainsString('title="title&quot; onerror=&quot;alert(1)"', $result);
        $this->assertStringNotContainsString('" onerror="alert(1)"', $result);
    }

    // ==================== Raw HTML Handling ====================

    public function testRawHtmlIsEscapedByDefault(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '`<script>alert(1)</script>`{=html}';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testRawHtmlBlockIsEscapedByDefault(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = "``` =html\n<script>alert(1)</script>\n```";
        $result = $converter->convert($djot);

        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testRawHtmlIsStrippedInStrictMode(): void
    {
        $converter = new CarveConverter(safeMode: SafeMode::strict());
        $djot = '`<script>alert(1)</script>`{=html}';
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('script', $result);
    }

    public function testStyleAttributeIsBlockedInStrictMode(): void
    {
        $converter = new CarveConverter(safeMode: SafeMode::strict());
        $djot = '[text]{style="background:expression(alert(1))"}';
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('style', $result);
        $this->assertStringNotContainsString('expression', $result);
    }

    public function testStyleAttributeAllowedInDefaultSafeMode(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[text]{style="color:red"}';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('style="color:red"', $result);
    }

    public function testRawHtmlAllowedWhenConfigured(): void
    {
        $safeMode = SafeMode::defaults()->setRawHtmlMode(SafeMode::RAW_HTML_ALLOW);
        $converter = new CarveConverter(safeMode: $safeMode);
        $djot = '`<b>bold</b>`{=html}';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('<b>bold</b>', $result);
    }

    // ==================== Safe Mode Configuration ====================

    public function testDangerousUrlBlankedEvenWithoutSafeMode(): void
    {
        $converter = new CarveConverter();
        $djot = '[click](javascript:alert(1))';
        $result = $converter->convert($djot);

        // URL scheme hardening is an always-on baseline (independent of safe
        // mode): a javascript: href is blanked, not emitted.
        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('href=""', $result);
    }

    public function testSafeModeCanBeEnabledAfterConstruction(): void
    {
        $converter = new CarveConverter();
        $converter->setSafeMode(true);

        $djot = '[click](javascript:alert(1))';
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testSafeModeCanBeDisabledAfterConstruction(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $converter->setSafeMode(false);

        // Disabling safe mode turns off its STRICTER policy, but the always-on
        // URL-scheme baseline still blanks a dangerous javascript: href.
        $djot = '[click](javascript:alert(1))';
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testCustomSafeModeConfiguration(): void
    {
        // Create safe mode that also blocks mailto:
        $safeMode = SafeMode::defaults()->addDangerousScheme('mailto');
        $converter = new CarveConverter(safeMode: $safeMode);

        $djot = '[email](mailto:test@example.com)';
        $result = $converter->convert($djot);

        $this->assertStringContainsString('href=""', $result);
    }

    public function testAllowedSchemesWhitelist(): void
    {
        // Only allow https
        $safeMode = SafeMode::defaults()->setAllowedSchemes(['https']);
        $converter = new CarveConverter(safeMode: $safeMode);

        $djot1 = '[link](https://example.com)';
        $result1 = $converter->convert($djot1);
        $this->assertStringContainsString('href="https://example.com"', $result1);

        $djot2 = '[link](http://example.com)';
        $result2 = $converter->convert($djot2);
        $this->assertStringContainsString('href=""', $result2);
    }

    public function testAllowedSchemesWhitelistNormalizesConfiguredCasing(): void
    {
        $safeMode = SafeMode::defaults()->setAllowedSchemes(['HTTPS']);
        $converter = new CarveConverter(safeMode: $safeMode);

        $result = $converter->convert('[link](https://example.com)');

        $this->assertStringContainsString('href="https://example.com"', $result);
    }

    public function testDangerousSchemesNormalizeConfiguredCasing(): void
    {
        $safeMode = SafeMode::defaults()->setDangerousSchemes(['JAVASCRIPT']);

        $this->assertFalse($safeMode->isUrlSafe('javascript:alert(1)'));
    }

    // ==================== SafeMode Class Tests ====================

    public function testSafeModeIsUrlSafe(): void
    {
        $safeMode = SafeMode::defaults();

        $this->assertTrue($safeMode->isUrlSafe('https://example.com'));
        $this->assertTrue($safeMode->isUrlSafe('/relative/path'));
        $this->assertTrue($safeMode->isUrlSafe('mailto:test@example.com'));
        $this->assertFalse($safeMode->isUrlSafe('javascript:alert(1)'));
        $this->assertFalse($safeMode->isUrlSafe('data:text/html,<script>'));
    }

    public function testSafeModeIsAttributeSafe(): void
    {
        $safeMode = SafeMode::defaults();

        $this->assertTrue($safeMode->isAttributeSafe('class'));
        $this->assertTrue($safeMode->isAttributeSafe('id'));
        $this->assertTrue($safeMode->isAttributeSafe('data-value'));
        $this->assertFalse($safeMode->isAttributeSafe('onclick'));
        $this->assertFalse($safeMode->isAttributeSafe('onload'));
        $this->assertFalse($safeMode->isAttributeSafe('ONCLICK')); // Case insensitive
    }

    public function testSafeModeFilterAttributes(): void
    {
        $safeMode = SafeMode::defaults();

        $attrs = [
            'class' => 'highlight',
            'onclick' => 'alert(1)',
            'id' => 'myid',
            'onmouseover' => 'hack()',
        ];

        $filtered = $safeMode->filterAttributes($attrs);

        $this->assertArrayHasKey('class', $filtered);
        $this->assertArrayHasKey('id', $filtered);
        $this->assertArrayNotHasKey('onclick', $filtered);
        $this->assertArrayNotHasKey('onmouseover', $filtered);
    }

    // ==================== Whitespace Bypass Prevention ====================

    public function testJavascriptUrlWithTabIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = "[click](java\tscript:alert(1))";
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('href=', $result);
        $this->assertStringNotContainsString('javascript', $result);
    }

    public function testJavascriptUrlWithVerticalTabIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = "[click](java\x0bscript:alert(1))";
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('href=', $result);
    }

    public function testJavascriptUrlWithFormFeedIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = "[click](java\x0cscript:alert(1))";
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('href=', $result);
    }

    public function testNullByteInSchemeMakesUrlInertNotDangerous(): void
    {
        // A NUL is normalized to U+FFFD at the parse entry, so `java\x00script:`
        // becomes `java\u{FFFD}script:`. U+FFFD is invalid in a URL scheme, so
        // browsers treat the whole thing as a (harmless) relative URL -- no
        // script runs. It is therefore passed through, NOT a `javascript:`
        // scheme to block. Matches carve-js / carve-rs. (A genuine
        // `javascript:` is still blocked; see the tests above.)
        $converter = new CarveConverter(safeMode: true);
        $djot = "[click](java\x00script:alert)";
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('href=""', $result);
        $this->assertStringContainsString("java\u{FFFD}script:alert", $result);
    }

    public function testJavascriptUrlWithSpaceBeforeColonIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = '[click](javascript :alert(1))';
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('href=', $result);
    }

    public function testJavascriptUrlWithCarriageReturnIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = "[click](java\rscript:alert(1))";
        $result = $converter->convert($djot);

        // A carriage return is whitespace, so it ENDS the link destination
        // (grammar.ebnf link_destination, decision B): the `(` run reaches the
        // line end without a closing `)`, so this is NOT a link and stays
        // literal -- no anchor is emitted at all, which is at least as safe as
        // a blanked href.
        $this->assertStringNotContainsString('<a ', $result);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testDataUrlWithWhitespaceIsBlocked(): void
    {
        $converter = new CarveConverter(safeMode: true);
        $djot = "[click](da\tta:text/html,<script>)";
        $result = $converter->convert($djot);

        $this->assertStringNotContainsString('href=', $result);
    }

    public function testSafeModeUrlWhitespaceBypasses(): void
    {
        $safeMode = SafeMode::defaults();

        // All these should be detected as unsafe
        $this->assertFalse($safeMode->isUrlSafe("java\tscript:alert(1)"), 'Tab in scheme');
        $this->assertFalse($safeMode->isUrlSafe("java\x0bscript:alert(1)"), 'Vertical tab in scheme');
        $this->assertFalse($safeMode->isUrlSafe("java\x0cscript:alert(1)"), 'Form feed in scheme');
        $this->assertFalse($safeMode->isUrlSafe("java\x00script:alert(1)"), 'Null byte in scheme');
        $this->assertFalse($safeMode->isUrlSafe('javascript :alert(1)'), 'Space before colon');
        $this->assertFalse($safeMode->isUrlSafe("java\rscript:alert(1)"), 'CR in scheme');
        $this->assertFalse($safeMode->isUrlSafe("java\nscript:alert(1)"), 'LF in scheme');
        $this->assertFalse($safeMode->isUrlSafe("da\tta:text/html,<script>"), 'Tab in data scheme');
        $this->assertFalse($safeMode->isUrlSafe("vb\x0bscript:alert(1)"), 'VT in vbscript scheme');
    }
}
