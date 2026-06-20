<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\MathBlockExtension;
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

    public function testPreservesAttributesAndAuthorClasses(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MathBlockExtension());

        $djot = <<<'DJOT'
{#eq1 .numbered data-label="E=mc^2"}
``` math
E = mc^2
```
DJOT;

        $html = $converter->convert($djot);

        // Base classes lead, author class follows; id and key=value preserved.
        $this->assertStringContainsString('class="math display numbered"', $html);
        $this->assertStringContainsString('id="eq1"', $html);
        $this->assertStringContainsString('data-label="E=mc^2"', $html);
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
