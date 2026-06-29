<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\MathBlockExtension;
use PHPUnit\Framework\TestCase;

class MathBlockExtensionTest extends TestCase
{
    public function testRendersMathBlockAsDisplayDiv(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MathBlockExtension());

        $djot = <<<'DJOT'
``` math
\int_0^1 x^2 \, dx
```
DJOT;

        $html = $converter->convert($djot);

        // Byte-parity with carve-js mathBlock(): block-level <div class="math
        // display"> with the LaTeX body wrapped in \[ … \].
        $this->assertStringContainsString(
            '<div class="math display">\[\int_0^1 x^2 \, dx\]</div>',
            $html,
        );
    }

    public function testEscapesAmpersandLessThanAndGreaterThan(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MathBlockExtension());

        $djot = <<<'DJOT'
``` math
a < b & c > d
```
DJOT;

        $html = $converter->convert($djot);

        // Unlike Mermaid, the math body escapes `>` too (core math escaping).
        $this->assertStringContainsString(
            '<div class="math display">\[a &lt; b &amp; c &gt; d\]</div>',
            $html,
        );
    }

    public function testNonMathCodeBlockDefersToCore(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MathBlockExtension());

        $djot = <<<'DJOT'
``` js
const x = 1
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="language-js"', $html);
        $this->assertStringContainsString('const x = 1', $html);
        $this->assertStringNotContainsString('math display', $html);
    }

    public function testInertWithoutExtension(): void
    {
        $converter = new CarveConverter();

        $djot = <<<'DJOT'
``` math
x^2
```
DJOT;

        $html = $converter->convert($djot);

        // Without the extension, a math block stays an ordinary code block.
        $this->assertStringContainsString('<pre><code class="language-math">x^2', $html);
        $this->assertStringNotContainsString('math display', $html);
    }

    public function testMergesAuthorClassesAndCopiesAttributes(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MathBlockExtension());

        $djot = <<<'DJOT'
{#eq1 .numbered data-ref="x"}
``` math
E = mc^2
```
DJOT;

        $html = $converter->convert($djot);

        // Author classes merge after the `math display` base; id and other
        // attributes follow in source order, like core inline / display math.
        $this->assertStringContainsString(
            '<div class="math display numbered" id="eq1" data-ref="x">\[E = mc^2\]</div>',
            $html,
        );
    }

    public function testStripsEventHandlerAlwaysOnEvenWithoutSafeMode(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MathBlockExtension());

        $djot = <<<'DJOT'
{#eq1 .numbered onclick="alert(1)"}
``` math
E = mc^2
```
DJOT;

        $html = $converter->convert($djot);

        // Always-on attribute hardening strips event handlers regardless of safe
        // mode, while safe author attributes (id, classes) survive.
        $this->assertStringContainsString('<div class="math display numbered" id="eq1">\[E = mc^2\]</div>', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testCustomLanguageTag(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MathBlockExtension(language: 'latex'));

        $djot = <<<'DJOT'
``` latex
x^2
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<div class="math display">\[x^2\]</div>', $html);
    }
}
