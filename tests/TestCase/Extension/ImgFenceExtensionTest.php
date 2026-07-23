<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\ImgFenceExtension;
use PHPUnit\Framework\TestCase;

/**
 * Faithful port of carve-js `test/img-fence.test.ts`.
 *
 * Flipped default: SANDBOX is the default, inline is gated behind BOTH the
 * `allowInline` constructor flag AND an `{inline}` fence attribute.
 */
class ImgFenceExtensionTest extends TestCase
{
    /**
     * Convert with an img-fence extension registered, trimmed for exact compare.
     */
    protected function render(string $carve, ImgFenceExtension $ext): string
    {
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        return trim($converter->convert($carve));
    }

    protected function ext(): ImgFenceExtension
    {
        return new ImgFenceExtension(); // sandbox by default
    }

    protected function extInline(): ImgFenceExtension
    {
        return new ImgFenceExtension(allowInline: true); // host permits inline
    }

    /**
     * A code fence carries no inline attributes (spec §"code fence"): any `{…}`
     * goes on the PRECEDING block-attribute line, which lands in `code.attrs`.
     */
    protected function fence(string $attrs, string $body): string
    {
        return ($attrs !== '' ? trim($attrs) . "\n" : '') . "```img\n" . $body . "\n```";
    }

    /**
     * Extract and percent-decode the sanitized SVG from a data-URI <img>.
     */
    protected function dataUri(string $out): string
    {
        $this->assertSame(1, preg_match('/src="data:image\/svg\+xml,([^"]*)"/', $out, $m));

        return rawurldecode($m[1]);
    }

    // -- sandbox mode (default) ---------------------------------------------

    public function testRendersACleanSvgAsADataUriImgNotInline(): void
    {
        $out = $this->render(
            $this->fence('', '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'),
            $this->ext(),
        );
        $this->assertStringContainsString('<img', $out);
        $this->assertDoesNotMatchRegularExpression('/<svg[\s>]/', $out); // not inline
        $decoded = $this->dataUri($out);
        $this->assertStringContainsString('<rect width="1" height="1"', $decoded);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $decoded);
    }

    public function testSanitizesInjectedScriptBeforeEncoding(): void
    {
        $out = $this->render(
            $this->fence('', '<svg viewBox="0 0 1 1"><script>alert(1)</script><rect width="1" height="1"/></svg>'),
            $this->ext(),
        );
        $decoded = $this->dataUri($out);
        $this->assertStringNotContainsString('script', $decoded);
        $this->assertStringContainsString('<rect width="1" height="1"', $decoded);
    }

    public function testSetsAltFromAttrAndDoesNotLeakTheFlag(): void
    {
        $out = $this->render(
            $this->fence(' {alt="a map"}', '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'),
            $this->ext(),
        );
        $this->assertStringContainsString('alt="a map"', $out);
        $this->assertStringNotContainsString('alt=""', $out);
    }

    public function testStripsSrcSrcsetOverrides(): void
    {
        $out = $this->render(
            $this->fence(' {srcset="https://attacker.example/x.svg 1x"}', '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'),
            $this->ext(),
        );
        $this->assertStringContainsString('src="data:image/svg+xml,', $out);
        $this->assertStringNotContainsString('srcset', $out);
        $this->assertStringNotContainsString('attacker', $out);
        $this->assertSame(1, preg_match('/<img[^>]*>/', $out, $img));
        $this->assertSame(1, (int)preg_match_all('/\bsrc=/', $img[0]));
    }

    public function testSwallowsARedundantSandboxMarker(): void
    {
        $out = $this->render(
            $this->fence(' {sandbox}', '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'),
            $this->ext(),
        );
        $this->assertStringContainsString('src="data:image/svg+xml,', $out);
        $this->assertStringNotContainsString('sandbox', $out);
    }

    public function testClaimsTheImageAliasToo(): void
    {
        $out = $this->render(
            "```image\n<svg viewBox=\"0 0 1 1\"><rect width=\"1\" height=\"1\"/></svg>\n```",
            $this->ext(),
        );
        $this->assertStringContainsString('src="data:image/svg+xml,', $out);
    }

    // -- {inline} is gated by allowInline (security) ------------------------

    public function testIgnoresInlineWhenTheHostDidNotOptIn(): void
    {
        $out = $this->render(
            $this->fence(' {inline}', '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'),
            $this->ext(), // allowInline not set
        );
        $this->assertStringContainsString('src="data:image/svg+xml,', $out); // still sandboxed
        $this->assertDoesNotMatchRegularExpression('/<svg[\s>]/', $out);
    }

    public function testRendersInlineSvgOnlyWhenAllowInlineAndInline(): void
    {
        $out = $this->render(
            $this->fence(' {inline}', '<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="currentColor"/></svg>'),
            $this->extInline(),
        );
        $this->assertMatchesRegularExpression('/<svg[\s>]/', $out);
        $this->assertStringContainsString('<circle cx="5" cy="5" r="4" fill="currentColor"', $out);
        $this->assertStringNotContainsString('data:image/svg+xml', $out);
    }

    public function testWithAllowInlineButNoInlineFenceStillDefaultsToSandbox(): void
    {
        $out = $this->render(
            $this->fence('', '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'),
            $this->extInline(),
        );
        $this->assertStringContainsString('src="data:image/svg+xml,', $out);
        $this->assertDoesNotMatchRegularExpression('/<svg[\s>]/', $out);
    }

    public function testSanitizesInjectedScriptInInlineModeToo(): void
    {
        $out = $this->render(
            $this->fence(' {inline}', '<svg viewBox="0 0 1 1"><script>alert(1)</script><rect width="1" height="1"/></svg>'),
            $this->extInline(),
        );
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringContainsString('<rect width="1" height="1"', $out);
    }

    // -- inline attribute merge (allowInline) -------------------------------

    public function testMergesFenceIdAndClassOntoTheRootSvg(): void
    {
        $out = $this->render(
            $this->fence(' {inline #logo .icon}', '<svg viewBox="0 0 1 1"><path d="M0 0"/></svg>'),
            $this->extInline(),
        );
        $this->assertMatchesRegularExpression('/<svg[^>]*\bid="logo"/', $out);
        $this->assertMatchesRegularExpression('/<svg[^>]*\bclass="icon"/', $out);
    }

    public function testMergesOntoARootWhoseAttrValueContainsAQuotedGreaterThan(): void
    {
        $out = $this->render(
            $this->fence(' {inline #x}', '<svg aria-label="1&gt;2" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'),
            $this->extInline(),
        );
        $this->assertSame(1, preg_match('/<svg[\s\S]*?>/', $out, $m));
        $svgTag = $m[0];
        $this->assertStringContainsString('id="x"', $svgTag);
        $this->assertStringContainsString('aria-label="1&gt;2"', $svgTag);
        $this->assertStringContainsString('<rect width="1" height="1"', $out);
    }

    public function testScrubsADangerousFencePresentationAttrMergedOntoTheRoot(): void
    {
        $out = $this->render(
            $this->fence(' {inline fill="url(https://attacker.example/p.svg#x)"}', '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'),
            $this->extInline(),
        );
        $this->assertStringNotContainsString('attacker', $out);
        $this->assertDoesNotMatchRegularExpression('/<svg[^>]*fill=/', $out);
        $this->assertStringContainsString('<rect width="1" height="1"', $out);
    }

    public function testFenceAttrsOverrideTheRootSvgAttrsWithoutDuplicatingThem(): void
    {
        $out = $this->render(
            $this->fence(' {inline #outer .fence}', '<svg id="inner" class="orig" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'),
            $this->extInline(),
        );
        $this->assertSame(1, preg_match('/<svg[^>]*>/', $out, $m));
        $svgTag = $m[0];
        $this->assertStringContainsString('id="outer"', $svgTag);
        $this->assertStringNotContainsString('id="inner"', $svgTag);
        $this->assertStringNotContainsString('class="orig"', $svgTag);
        $this->assertSame(1, (int)preg_match_all('/\bid=/', $svgTag));
        $this->assertSame(1, (int)preg_match_all('/\bclass=/', $svgTag));
        $this->assertStringContainsString('viewBox="0 0 1 1"', $svgTag);
    }

    // -- fallback + off-by-default ------------------------------------------

    public function testNonSvgBodyDegradesToAnEscapedCodeBlockNeverRaw(): void
    {
        $out = $this->render($this->fence('', 'not an svg <b>x</b>'), $this->ext());
        $this->assertStringContainsString('<pre', $out);
        $this->assertStringContainsString('&lt;b&gt;', $out);
        $this->assertStringNotContainsString('<b>x</b>', $out);
    }

    public function testIsOffUnlessRegisteredPlainImgFenceStaysACodeBlock(): void
    {
        $converter = new CarveConverter();
        $out = trim($converter->convert($this->fence('', '<svg><rect/></svg>')));
        $this->assertStringContainsString('<pre', $out);
        $this->assertStringContainsString('<code', $out);
    }

    // -- constructor guard ---------------------------------------------------

    public function testEmptyLanguageThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ImgFenceExtension(language: '');
    }
}
