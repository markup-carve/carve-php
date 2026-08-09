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
