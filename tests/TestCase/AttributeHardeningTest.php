<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
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
}
