<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\Extension\SvgSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Faithful port of carve-js `test/svg-sanitize.test.ts`.
 */
class SvgSanitizerTest extends TestCase
{
    /**
     * Wrap presentational inner markup in a valid <svg> root.
     */
    protected function wrap(string $inner): string
    {
        return '<svg viewBox="0 0 10 10">' . $inner . '</svg>';
    }

    /**
     * @param string $source
     * @param array<string, bool> $opts
     */
    protected function svg(string $source, array $opts = []): string
    {
        return SvgSanitizer::sanitize($source, $opts)['svg'];
    }

    /**
     * Count the occurrences of a regex (mirrors JS `str.match(/re/g).length`).
     */
    protected function countMatches(string $pattern, string $subject): int
    {
        return (int)preg_match_all($pattern, $subject);
    }

    // -- element filtering ---------------------------------------------------

    public function testKeepsACleanPresentationalSvg(): void
    {
        $src = $this->wrap('<path d="M0 0L10 10" fill="currentColor"/>');
        $result = SvgSanitizer::sanitize($src);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('<path d="M0 0L10 10" fill="currentColor"', $result['svg']);
        $this->assertStringContainsString('</svg>', $result['svg']);
    }

    public function testDropsScriptAndItsContent(): void
    {
        $result = SvgSanitizer::sanitize($this->wrap('<script>alert(1)</script><circle r="5"/>'));
        $this->assertTrue($result['ok']);
        $this->assertStringNotContainsString('script', $result['svg']);
        $this->assertStringNotContainsString('alert', $result['svg']);
        $this->assertStringContainsString('<circle r="5"', $result['svg']);
    }

    public function testDropsForeignObjectSubtree(): void
    {
        $svg = $this->svg($this->wrap('<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><img src=x onerror=alert(1)></body></foreignObject>'));
        $this->assertStringNotContainsString('foreignObject', $svg);
        $this->assertStringNotContainsString('onerror', $svg);
        $this->assertStringNotContainsString('<img', $svg);
    }

    public function testDropsSmilAnimationByDefault(): void
    {
        $svg = $this->svg($this->wrap('<rect width="10" height="10"><animate onbegin="alert(1)" attributeName="x"/></rect>'));
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringNotContainsString('animate', $svg);
        $this->assertStringNotContainsString('onbegin', $svg);
    }

    public function testDropsCommentsCdataPiAndDoctype(): void
    {
        $src = '<!DOCTYPE svg><?xml-stylesheet href="x"?>' . $this->wrap('<!-- c --><![CDATA[ x ]]><path d="M0 0"/>');
        $svg = $this->svg($src);
        $this->assertStringNotContainsString('<!--', $svg);
        $this->assertStringNotContainsString('CDATA', $svg);
        $this->assertStringNotContainsString('DOCTYPE', $svg);
        $this->assertStringNotContainsString('xml-stylesheet', $svg);
        $this->assertStringContainsString('<path d="M0 0"', $svg);
    }

    public function testKeepsNestedAllowedTags(): void
    {
        $inner =
            '<defs><linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient>'
            . '<filter id="f"><feGaussianBlur stdDeviation="2"/></filter></defs>'
            . '<g transform="translate(1,1)"><rect width="8" height="8" fill="url(#g)"/></g>';
        $result = SvgSanitizer::sanitize($this->wrap($inner));
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('<linearGradient', $result['svg']);
        $this->assertStringContainsString('<feGaussianBlur', $result['svg']);
        $this->assertStringContainsString('<g transform="translate(1,1)"', $result['svg']);
    }

    // -- attribute filtering -------------------------------------------------

    public function testStripsEveryOnHandler(): void
    {
        $svg = $this->svg($this->wrap('<circle r="5" onclick="x()" onload="y()"/>'));
        $this->assertStringContainsString('<circle r="5"', $svg);
        $this->assertStringNotContainsString('onclick', $svg);
        $this->assertStringNotContainsString('onload', $svg);
    }

    public function testBlocksEntityEncodedSchemesInHref(): void
    {
        $num = $this->svg($this->wrap('<a href="jav&#x61;script:alert(1)"><rect width="1" height="1"/></a>'), ['allowLinks' => true]);
        $this->assertStringNotContainsString('href=', $num);
        $named = $this->svg($this->wrap('<a href="javascript&colon;alert(1)"><rect width="1" height="1"/></a>'), ['allowLinks' => true]);
        $this->assertStringNotContainsString('href=', $named);
    }

    public function testAcceptsLeadingWhitespaceAfterDroppedDeclaration(): void
    {
        $src = '<?xml version="1.0"?>' . "\n" . '<!DOCTYPE svg>' . "\n" . '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>';
        $result = SvgSanitizer::sanitize($src);
        $this->assertTrue($result['ok']);
        $this->assertMatchesRegularExpression('/^<svg/', $result['svg']);
        $this->assertStringContainsString('<rect width="1" height="1"', $result['svg']);
    }

    public function testDropsJavascriptAndExternalHrefKeepsLocalFragment(): void
    {
        $bad = $this->svg($this->wrap('<use href="javascript:alert(1)"/>'));
        $this->assertStringNotContainsString('javascript', $bad);
        $ext = $this->svg($this->wrap('<use href="https://evil.example/x.svg#a"/>'));
        $this->assertStringNotContainsString('https://evil', $ext);
        $local = $this->svg($this->wrap('<use href="#icon"/>'));
        $this->assertStringContainsString('href="#icon"', $local);
    }

    public function testDropsStyleByDefault(): void
    {
        $svg = $this->svg($this->wrap('<rect style="fill:red" width="10" height="10"/>'));
        $this->assertStringNotContainsString('style', $svg);
    }

    public function testAllowStyleKeepsBenignButScrubsUrlAndExpression(): void
    {
        $ok = $this->svg($this->wrap('<rect style="fill:red" width="1" height="1"/>'), ['allowStyle' => true]);
        $this->assertStringContainsString('style="fill:red"', $ok);
        $bad = $this->svg($this->wrap('<rect style="background:url(javascript:alert(1))" width="1" height="1"/>'), ['allowStyle' => true]);
        $this->assertStringNotContainsString('url(', $bad);
        $this->assertStringNotContainsString('javascript', $bad);
    }

    public function testAlwaysDropsStyleElementEvenWithAllowStyle(): void
    {
        $src = $this->wrap('<style>@import url(https://attacker.example/x.css)</style><rect width="1" height="1"/>');
        $svg = $this->svg($src, ['allowStyle' => true]);
        $this->assertStringNotContainsString('style', $svg);
        $this->assertStringNotContainsString('@import', $svg);
        $this->assertStringNotContainsString('attacker', $svg);
        $this->assertStringContainsString('<rect width="1" height="1"', $svg);
    }

    public function testAllowLinksDoesNotWidenExternalHrefOntoFetchCapableElements(): void
    {
        $src = $this->wrap('<use href="https://evil.example/x.svg#a"/>');
        $on = $this->svg($src, ['allowLinks' => true]);
        $this->assertStringNotContainsString('https://evil', $on);
        // local ref still fine
        $this->assertStringContainsString('href="#i"', $this->svg($this->wrap('<use href="#i"/>'), ['allowLinks' => true]));
        // …but an actual <a> link is kept under allowLinks
        $link = $this->svg($this->wrap('<a href="https://ok.example/"><rect width="1" height="1"/></a>'), ['allowLinks' => true]);
        $this->assertStringContainsString('href="https://ok.example/"', $link);
    }

    public function testBlocksOsProtocolHandlerSchemesOnLinksEvenWithAllowLinks(): void
    {
        foreach (['ms-msdt:x', 'shell:x', 'vscode:x', 'jar:x', 'search-ms:x'] as $scheme) {
            $svg = $this->svg($this->wrap('<a href="' . $scheme . '"><rect width="1" height="1"/></a>'), ['allowLinks' => true]);
            $this->assertStringNotContainsString('href=', $svg);
        }
    }

    public function testAllowExternalImagesKeepsImageHrefWithoutAllowLinks(): void
    {
        $src = $this->wrap('<image href="https://cdn.example/logo.png" width="10" height="10"/>');
        $off = $this->svg($src);
        $this->assertStringNotContainsString('<image', $off);
        $on = $this->svg($src, ['allowExternalImages' => true]);
        $this->assertStringContainsString('<image', $on);
        $this->assertStringContainsString('href="https://cdn.example/logo.png"', $on);
        // still scheme-checked
        $bad = $this->svg($this->wrap('<image href="javascript:alert(1)" width="1" height="1"/>'), ['allowExternalImages' => true]);
        $this->assertStringNotContainsString('javascript', $bad);
    }

    public function testDropsPresentationAttrsWithExternalUrlRefKeepsLocal(): void
    {
        $ext = $this->svg($this->wrap('<rect width="1" height="1" fill="url(https://attacker.example/p.svg#x)"/>'));
        $this->assertStringNotContainsString('attacker', $ext);
        $this->assertStringNotContainsString('url(http', $ext);
        $filt = $this->svg($this->wrap('<rect width="1" height="1" filter="url(//evil/x)"/>'));
        $this->assertStringNotContainsString('filter=', $filt);
        $local = $this->svg($this->wrap('<rect width="1" height="1" fill="url(#grad)"/>'));
        $this->assertStringContainsString('fill="url(#grad)"', $local);
    }

    public function testRejectsAQuotedUrlWhoseTargetContainsAParen(): void
    {
        $svg = $this->svg($this->wrap('<rect width="1" height="1" fill=\'url("https://attacker.example/a)b.svg#x")\'/>'));
        $this->assertStringNotContainsString('attacker', $svg);
        $this->assertStringNotContainsString('fill=', $svg);
    }

    public function testValidatesEachSmilValuesEntry(): void
    {
        $src = $this->wrap('<use href="#i"><animate attributeName="href" values="#i;https://attacker.example/x.svg#j"/></use>');
        $svg = $this->svg($src, ['allowAnimation' => true]);
        $this->assertStringNotContainsString('attacker', $svg);
        $this->assertStringNotContainsString('values=', $svg);
        // protocol-relative and absolute-path refs are also blocked
        $rel = $this->svg($this->wrap('<rect width="1" height="1"><animate attributeName="fill" values="#i;//evil.example/x.svg#j"/></rect>'), ['allowAnimation' => true]);
        $this->assertStringNotContainsString('evil', $rel);
        $this->assertStringNotContainsString('values=', $rel);
        // a clean local-only values list is kept
        $clean = $this->svg($this->wrap('<rect width="1" height="1"><animate attributeName="fill" values="#a;#b"/></rect>'), ['allowAnimation' => true]);
        $this->assertStringContainsString('values="#a;#b"', $clean);
    }

    public function testAllowStyleRejectsCssEscapedUrl(): void
    {
        $svg = $this->svg($this->wrap('<rect width="1" height="1" style="fill:u\72l(https://attacker.example/x.svg#p)"/>'), ['allowStyle' => true]);
        $this->assertStringNotContainsString('style', $svg);
        $this->assertStringNotContainsString('attacker', $svg);
    }

    public function testDropsUnknownAttributesNotOnTheAllowlist(): void
    {
        $svg = $this->svg($this->wrap('<path d="M0 0" formaction="x" srcdoc="y"/>'));
        $this->assertStringContainsString('d="M0 0"', $svg);
        $this->assertStringNotContainsString('formaction', $svg);
        $this->assertStringNotContainsString('srcdoc', $svg);
    }

    public function testEscapesSpecialCharsInKeptAttributeValues(): void
    {
        $svg = $this->svg($this->wrap('<title>a &amp; b &lt; c</title>'));
        $this->assertDoesNotMatchRegularExpression('~<title>.*<.*</title>~', $svg); // no raw < inside text
    }

    public function testPreservesExistingXmlEntitiesWithoutDoubleEscaping(): void
    {
        $t = $this->svg($this->wrap('<text>A &amp; B</text>'));
        $this->assertStringContainsString('A &amp; B', $t);
        $this->assertStringNotContainsString('&amp;amp;', $t);
        $a = $this->svg($this->wrap('<text aria-label="A &quot; B">x</text>'));
        $this->assertStringContainsString('aria-label="A &quot; B"', $a);
        $this->assertStringNotContainsString('&amp;quot;', $a);
        // a bare & is still escaped
        $this->assertStringContainsString('a &amp; b', $this->svg($this->wrap('<text>a & b</text>')));
    }

    // -- root guard + xmlns --------------------------------------------------

    public function testRejectsANonSvgRoot(): void
    {
        $this->assertFalse(SvgSanitizer::sanitize('<div>not svg</div>')['ok']);
        $this->assertFalse(SvgSanitizer::sanitize('hello')['ok']);
    }

    public function testRejectsWhenTheSvgRootIsUnclosedOrMalformed(): void
    {
        $this->assertFalse(SvgSanitizer::sanitize('<svg><path d="M0 0"')['ok']);
    }

    public function testRejectsNonWhitespaceTextBeforeTheRoot(): void
    {
        $this->assertFalse(SvgSanitizer::sanitize('caption<svg><rect/></svg>')['ok']);
        // pure whitespace before the root is still fine
        $this->assertTrue(SvgSanitizer::sanitize('  ' . "\n" . '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>')['ok']);
    }

    public function testDeduplicatesRepeatedAttributesKeepsFirst(): void
    {
        $result = SvgSanitizer::sanitize('<svg viewBox="0 0 1 1" viewBox="0 0 2 2"><rect id="a" id="b" width="1" height="1"/></svg>');
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $this->countMatches('/viewBox=/', $result['svg']));
        $this->assertStringContainsString('viewBox="0 0 1 1"', $result['svg']);
        $this->assertSame(1, preg_match('/<rect[^>]*>/', $result['svg'], $rectMatch));
        $rect = $rectMatch[0];
        $this->assertSame(1, $this->countMatches('/\bid=/', $rect));
        $this->assertStringContainsString('id="a"', $rect);
    }

    public function testEscapesNonXmlNamedEntitiesSoDataUriSvgStaysWellFormed(): void
    {
        $svg = $this->svg($this->wrap('<text>a&nbsp;b &copy; c</text>'));
        $this->assertStringContainsString('&amp;nbsp;', $svg);
        $this->assertStringContainsString('&amp;copy;', $svg);
        // XML-predefined + numeric refs are still preserved
        $keep = $this->svg($this->wrap('<text>a &amp; b &#160; c</text>'));
        $this->assertStringContainsString('&amp; ', $keep);
        $this->assertStringContainsString('&#160;', $keep);
    }

    public function testRejectsMultipleTopLevelSvgRoots(): void
    {
        $this->assertFalse(SvgSanitizer::sanitize('<svg></svg><svg></svg>')['ok']);
        $this->assertFalse(SvgSanitizer::sanitize($this->wrap('<rect width="1" height="1"/>') . '<svg></svg>')['ok']);
    }

    public function testRejectsMismatchedClosingTags(): void
    {
        $this->assertFalse(SvgSanitizer::sanitize('<svg><path></rect></svg>')['ok']);
        $this->assertFalse(SvgSanitizer::sanitize('<svg><g></svg>')['ok']); // stray/early close
    }

    public function testRejectsCaseMismatchedTagNames(): void
    {
        $this->assertFalse(SvgSanitizer::sanitize('<svg><g></G></svg>')['ok']);
        $this->assertFalse(SvgSanitizer::sanitize('<SVG><rect/></SVG>')['ok']); // non-lowercase root
    }

    public function testDoesNotExitADroppedSubtreeOnAMismatchedClose(): void
    {
        // <script> is dropped; the </svg> must not be mistaken for closing it.
        $this->assertFalse(SvgSanitizer::sanitize('<svg><script></svg><rect width="1" height="1"/></svg>')['ok']);
    }

    public function testDropsAWellFormedDisallowedSubtreeAndKeepsSiblings(): void
    {
        $result = SvgSanitizer::sanitize('<svg><script>x</script><rect width="1" height="1"/></svg>');
        $this->assertTrue($result['ok']);
        $this->assertStringNotContainsString('script', $result['svg']);
        $this->assertStringContainsString('<rect width="1" height="1"', $result['svg']);
    }

    public function testInjectsXmlnsOnTheRootWhenMissing(): void
    {
        $svg = $this->svg('<svg viewBox="0 0 1 1"><path d="M0 0"/></svg>');
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
    }

    public function testForcesCanonicalXmlnsEvenWhenAuthorXmlnsIsWrongOrDangerous(): void
    {
        $wrong = $this->svg('<svg xmlns="https://example.com" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>');
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $wrong);
        $this->assertStringNotContainsString('example.com', $wrong);
        $this->assertSame(1, $this->countMatches('/\bxmlns=/', $wrong));
        $danger = $this->svg('<svg xmlns="javascript:x" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>');
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $danger);
        $this->assertStringNotContainsString('javascript', $danger);
    }

    public function testAcceptsASelfClosingEmptySvgRoot(): void
    {
        $result = SvgSanitizer::sanitize('<svg viewBox="0 0 1 1"/>');
        $this->assertTrue($result['ok']);
        $this->assertMatchesRegularExpression('~^<svg[^>]*/>$~', $result['svg']);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $result['svg']);
    }

    // -- idempotence ---------------------------------------------------------

    public function testSanitizeSanitizeXEqualsSanitizeX(): void
    {
        $src = $this->wrap('<script>x</script><g><rect style="fill:red" width="5" height="5" onclick="e"/></g>');
        $once = $this->svg($src);
        $twice = $this->svg($once);
        $this->assertSame($once, $twice);
    }
}
