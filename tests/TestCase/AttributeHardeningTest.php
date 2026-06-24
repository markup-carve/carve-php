<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\Node\Block\Paragraph;
use Carve\Node\Document;
use Carve\Node\Inline\Span;
use Carve\Node\Inline\Text;
use Carve\Renderer\HtmlRenderer;
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
        // A leading NBSP (U+00A0) must not smuggle a javascript: scheme past the
        // denylist: the probe strips Unicode whitespace before scheme matching.
        $nbsp = "\u{00A0}";
        $this->assertStringNotContainsString('javascript:', $this->render('[x](' . $nbsp . 'javascript:alert(1))'));
        $this->assertStringNotContainsString(
            'javascript:',
            $this->render('![i](' . $nbsp . 'javascript:alert(1))'),
        );
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
}
