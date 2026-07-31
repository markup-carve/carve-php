<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Always-on attribute XSS hardening (independent of safe mode).
 *
 * Dangerous attribute names and values are stripped from every rendered
 * element, with NO safe-mode opt-in, because there is no legitimate use of
 * event handlers or script URLs in a content-markup document.
 */
class AttributeHardeningTest extends TestCase
{
    protected function render(string $djot): string
    {
        // No safe mode configured: hardening must still apply.
        return trim((new CarveConverter())->convert($djot));
    }

    public function testStripsEventHandlerAttributes(): void
    {
        $this->assertSame('<p><span>x</span></p>', $this->render('[x]{onclick="alert(1)"}'));
        $this->assertSame(
            '<p><span class="c">x</span></p>',
            $this->render('[x]{onmouseover="x" class="c"}'),
        );
    }

    public function testStripsSrcdocAndFormaction(): void
    {
        $this->assertSame(
            '<p><span title="ok">x</span></p>',
            $this->render('[x]{srcdoc="<script>" formaction="y" title="ok"}'),
        );
    }

    public function testBlanksDangerousSchemeValues(): void
    {
        $this->assertSame(
            '<p><span background="">x</span></p>',
            $this->render('[x]{background="javascript:alert(1)"}'),
        );
    }

    public function testDefeatsSchemeObfuscation(): void
    {
        $this->assertSame(
            '<p><span background="">x</span></p>',
            $this->render("[x]{background=\"java\tscript:alert(1)\"}"),
        );
    }

    public function testBlanksCssExpressionButKeepsPlainStyle(): void
    {
        $this->assertSame(
            '<p><span style="">x</span></p>',
            $this->render('[x]{style="x:expression(alert(1))"}'),
        );
        $this->assertSame(
            '<p><span style="color:red">x</span></p>',
            $this->render('[x]{style="color:red"}'),
        );
    }

    public function testKeepsSafeAttributes(): void
    {
        $this->assertSame(
            '<p><span title="hello" data-id="42" class="a b">x</span></p>',
            $this->render('[x]{title="hello" data-id="42" class="a b"}'),
        );
    }

    public function testUrlSchemeDenylistAlwaysOn(): void
    {
        // Dangerous schemes blanked on href/src without any safe mode.
        $this->assertStringContainsString('href=""', $this->render('[x](javascript:alert(1))'));
        $this->assertStringContainsString('src=""', $this->render('![i](javascript:alert(1))'));
        $this->assertStringContainsString('href=""', $this->render('[x](data:text/html,foo)'));
    }

    public function testLinkAndImageAttributeBlocksCannotOverrideDestination(): void
    {
        $this->assertSame(
            '<p><a href="https://example.com">safe</a></p>',
            $this->render('[safe](https://example.com){href="javascript:steal"}'),
        );
        $this->assertSame(
            '<img src="https://example.com/x.png" alt="logo">',
            $this->render('![logo](https://example.com/x.png){src="javascript:steal"}'),
        );
    }

    public function testOrdinaryUrlSchemesPassUnderDenylist(): void
    {
        $this->assertStringContainsString('href="https://e.com"', $this->render('[x](https://e.com)'));
        $this->assertStringContainsString('href="tel:+1"', $this->render('[c](tel:+1)'));
        $this->assertStringContainsString('href="/p"', $this->render('[r](/p)'));
    }

    public function testLeadingUnicodeWhitespaceCannotHideDangerousScheme(): void
    {
        // A leading NBSP (U+00A0) must not smuggle a javascript: scheme into an
        // href or src. It no longer can for a stronger reason than the denylist:
        // Unicode whitespace ends a destination (PART 9 link_destination), so
        // the construct is not a link or an image at all and there is no
        // attribute to smuggle anything into (carve#404).
        //
        // The assertion is about the ATTRIBUTE, not the string. It used to read
        // "the output does not contain `javascript:`", which passed while the
        // link formed and its href was blanked. That is now the wrong shape of
        // question: the text survives as inert, escaped prose, with no anchor
        // around it, and asserting on the substring would fail a document that
        // is safe.
        $nbsp = "\u{00A0}";

        foreach (['[x](' . $nbsp . 'javascript:alert(1))', '![i](' . $nbsp . 'javascript:alert(1))'] as $source) {
            $out = $this->render($source);

            $this->assertStringNotContainsString('href=', $out);
            $this->assertStringNotContainsString('src=', $out);
            $this->assertStringNotContainsString('<a', $out);
            $this->assertStringNotContainsString('<img', $out);
        }
    }

    public function testCssStyleHardeningBlanksFetchAndScriptConstructs(): void
    {
        $this->assertStringContainsString('style=""', $this->render('[x]{style="background:url(javascript:1)"}'));
        $this->assertStringContainsString('style=""', $this->render('[x]{style="@import url(evil.css)"}'));
        $this->assertStringContainsString('style=""', $this->render('[x]{style="behavior:url(x.htc)"}'));
        $this->assertStringContainsString('style="color:red"', $this->render('[x]{style="color:red"}'));
    }

    public function testDropsInvalidProgrammaticAttributeNames(): void
    {
        $doc = new Document();
        $paragraph = new Paragraph();
        $span = new Span();
        $span->appendChild(new Text('x'));
        $span->setAttributes([
            'data-ok' => '1',
            'bad"name' => 'x',
        ]);
        $paragraph->appendChild($span);
        $doc->appendChild($paragraph);

        $html = trim((new HtmlRenderer())->render($doc));

        $this->assertSame('<p><span data-ok="1">x</span></p>', $html);
    }

    public function testCssEscapesAreNormalizedBeforeStyleHardening(): void
    {
        $this->assertSame(
            '<p><span style="">x</span></p>',
            $this->render('[x]{style="background:u\72l(http://e/p)"}'),
        );
    }

    /**
     * OS protocol-handler / command-execution schemes (the CVE-2026-20841
     * class) are blanked on href, image src, and autolinks, always on.
     *
     * @return array<string, array{0: string}>
     */
    public static function osHandlerSchemeProvider(): array
    {
        return [
            'ms-msdt' => ['ms-msdt:/id'],
            'ms-office payload' => ['ms-office:ofe|u|http://evil/x.docm'],
            'ms-word' => ['ms-word:ofe|u|http://evil/x.docx'],
            'ms-excel' => ['ms-excel:ofe|u|http://evil/x.xlsx'],
            'ms-powerpoint' => ['ms-powerpoint:ofe|u|http://evil/x.pptx'],
            'ms-access' => ['ms-access:x'],
            'ms-visio' => ['ms-visio:x'],
            'ms-project' => ['ms-project:x'],
            'ms-publisher' => ['ms-publisher:x'],
            'ms-infopath' => ['ms-infopath:x'],
            'ms-spd' => ['ms-spd:x'],
            'ms-search' => ['ms-search:x'],
            'search-ms' => ['search-ms:query=x'],
            'ms-cxh' => ['ms-cxh:x'],
            'ms-cxh-full' => ['ms-cxh-full:x'],
            'shell' => ['shell:Startup'],
            'vscode' => ['vscode:extension/x'],
            'vscode-insiders' => ['vscode-insiders:extension/x'],
            'jar' => ['jar:http://evil/x.jar!/'],
        ];
    }

    #[DataProvider('osHandlerSchemeProvider')]
    public function testOsHandlerSchemesBlankedOnLinkAndImage(string $url): void
    {
        $this->assertSame(
            '<p><a href="">a</a></p>',
            $this->render('[a](' . $url . ')'),
        );
        $this->assertSame(
            '<img src="" alt="i">',
            $this->render('![i](' . $url . ')'),
        );
    }

    public function testOsHandlerSchemeBlankedOnAutolink(): void
    {
        $this->assertSame(
            '<p><a href="">ms-msdt:/id</a></p>',
            $this->render('<ms-msdt:/id>'),
        );
    }

    public function testOsHandlerSchemeMatchingIsCaseInsensitive(): void
    {
        $this->assertSame(
            '<p><a href="">a</a></p>',
            $this->render('[a](MS-OFFICE:ofe|u|http://evil/x.docm)'),
        );
        $this->assertSame(
            '<p><a href="">b</a></p>',
            $this->render('[b](Shell:Startup)'),
        );
    }

    public function testOsHandlerSchemeBlankedOnAttributeOverride(): void
    {
        $this->assertSame(
            '<p><a href="">safe</a></p>',
            $this->render('[safe](ms-msdt:/id){href="ms-office:ofe|u|payload"}'),
        );
        $this->assertSame(
            '<p><span background="">x</span></p>',
            $this->render('[x]{background="search-ms:query=x"}'),
        );
    }

    public function testOrdinarySchemesStayAllowedAlongsideOsHandlerDenylist(): void
    {
        $this->assertStringContainsString('href="https://ok.com"', $this->render('[d](https://ok.com)'));
        $this->assertStringContainsString('href="http://ok.com"', $this->render('[d](http://ok.com)'));
        $this->assertStringContainsString('href="tel:+15551234"', $this->render('[e](tel:+15551234)'));
        $this->assertStringContainsString('href="mailto:a@b.c"', $this->render('[m](mailto:a@b.c)'));
        $this->assertStringContainsString('href="ftp://h/f"', $this->render('[f](ftp://h/f)'));
        $this->assertStringContainsString('href="sms:+1"', $this->render('[s](sms:+1)'));
    }
}
