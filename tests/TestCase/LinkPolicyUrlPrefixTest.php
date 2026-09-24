<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\LinkPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The profile policy classifies URL prefixes the way a WHATWG URL parser does.
 */
class LinkPolicyUrlPrefixTest extends TestCase
{
    #[DataProvider('backslashAuthorityProvider')]
    public function testBackslashAuthoritySpellingsAreExternal(string $url): void
    {
        $this->assertFalse(LinkPolicy::internalOnly()->isUrlAllowed($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function backslashAuthorityProvider(): array
    {
        return [
            'backslash then two slashes' => ['\\//evil.com/x'],
            'two backslashes' => ['\\\\evil.com/x'],
            'slash then backslash' => ['/\\evil.com/x'],
            'backslash then slash' => ['\\/evil.com/x'],
        ];
    }

    public function testLeadingAsciiC0IsIgnoredBeforeAuthorityClassification(): void
    {
        for ($codepoint = 0; $codepoint <= 0x20; ++$codepoint) {
            $this->assertFalse(
                LinkPolicy::internalOnly()->isUrlAllowed(chr($codepoint) . '//evil.com/x'),
                sprintf('U+%04X must be ignored by prefix classification', $codepoint),
            );
        }
    }

    public function testUrlSignificantPrefixesRemainRelativeContent(): void
    {
        foreach (["\x7f", "\xc2\x80", "\xc2\x9f", "\xc2\xa0", "\xef\xbb\xbf"] as $prefix) {
            $this->assertTrue(LinkPolicy::internalOnly()->isUrlAllowed($prefix . '//evil.com/x'));
        }
    }

    #[DataProvider('browserHostProvider')]
    public function testHostChecksReadTheHostABrowserReads(string $url): void
    {
        $this->assertFalse(LinkPolicy::unrestricted()->setDeniedDomains(['evil.example'])->isUrlAllowed($url));
        $this->assertFalse(LinkPolicy::allowlist(['good.example'])->isUrlAllowed($url));
        $this->assertFalse(LinkPolicy::internalOnly()->isUrlAllowed($url, 'good.example'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function browserHostProvider(): array
    {
        return [
            'two backslashes after the scheme' => ['https:\\\\evil.example/x'],
            'one backslash after the scheme' => ['https:\\evil.example/x'],
            'slash then backslash after the scheme' => ['HTTPS:/\\evil.example/x'],
            'no slashes after the scheme' => ['https:evil.example/x'],
            'three slashes' => ['https:///evil.example/x'],
            'backslash ends the authority' => ['https://evil.example\\@good.example/x'],
            'tab inside the authority' => ["https://evil.exa\tmple/x"],
            'newline inside the scheme' => ["ht\ntps://evil.example/x"],
            'tab hides a protocol-relative prefix' => ["/\t/evil.example/x"],
            'percent-encoded dot' => ['https://evil%2Eexample/x'],
            'trailing dot' => ['https://evil.example./x'],
            'uppercase host' => ['https://EVIL.Example/x'],
            'ideographic full stop' => ["https://evil\u{3002}example/x"],
            'userinfo naming the good host' => ['https://good.example@evil.example/x'],
            'port' => ['https://evil.example:8443/x'],
        ];
    }

    public function testTheGoodHostStillPasses(): void
    {
        foreach (['https://good.example/x', 'https://evil.example@good.example/x', 'https://GOOD.example./x', 'https://good.example:8443/x'] as $url) {
            $this->assertTrue(LinkPolicy::allowlist(['good.example'])->isUrlAllowed($url), $url);
            $this->assertTrue(LinkPolicy::internalOnly()->isUrlAllowed($url, 'good.example'), $url);
            $this->assertTrue(LinkPolicy::unrestricted()->setDeniedDomains(['evil.example'])->isUrlAllowed($url), $url);
        }
    }

    public function testConfiguredDomainsAreNormalizedLikeTheUrlHost(): void
    {
        $this->assertFalse(LinkPolicy::unrestricted()->setDeniedDomains(['EVIL.example.'])->isUrlAllowed('https://evil.example./x'));
        $this->assertFalse(LinkPolicy::unrestricted()->setDeniedDomains(['evil.example.'])->isUrlAllowed('https://evil.example/x'));
        $this->assertTrue(LinkPolicy::allowlist(['good.example.'])->isUrlAllowed('https://good.example/x'));
        $this->assertTrue(LinkPolicy::internalOnly()->isUrlAllowed('https://good.example/x', 'Good.Example.'));
    }

    public function testAHostlessHttpUrlFailsOnlyAHostRule(): void
    {
        $this->assertTrue(LinkPolicy::unrestricted()->isUrlAllowed('https:'));
        $this->assertFalse(LinkPolicy::unrestricted()->setDeniedDomains(['evil.example'])->isUrlAllowed('https:'));
        $this->assertFalse(LinkPolicy::allowlist(['good.example'])->isUrlAllowed('https:///'));
    }

    public function testASplitSchemeIsDeniedButNeverNewlyAllowed(): void
    {
        $denyFtp = LinkPolicy::unrestricted()->setDeniedSchemes(['ftp']);
        $this->assertFalse($denyFtp->isUrlAllowed("f\x7ftp://files.example/x"));
        $this->assertFalse($denyFtp->isUrlAllowed("f\u{00A0}tp://files.example/x"));

        $httpsOnly = LinkPolicy::unrestricted()->setAllowedSchemes(['https']);
        $this->assertFalse($httpsOnly->isUrlAllowed("htt\x7fps://good.example/x"));
    }

    public function testOrdinaryControlsRemainHonest(): void
    {
        $policy = LinkPolicy::internalOnly();
        $this->assertTrue($policy->isUrlAllowed('/local/x'));
        $this->assertTrue($policy->isUrlAllowed('#frag'));
        $this->assertTrue($policy->isUrlAllowed('page.crv'));
        $this->assertFalse($policy->isUrlAllowed('//evil.com/x'));
        $this->assertFalse($policy->isUrlAllowed('https://evil.com/x'));
    }
}
