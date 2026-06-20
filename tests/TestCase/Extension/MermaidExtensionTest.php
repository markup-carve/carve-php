<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\FencedRenderExtension;
use Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class MermaidExtensionTest extends TestCase
{
    public function testBasicFlowchart(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('graph TD;', $html);
        // Raw syntax preserved for Mermaid.js (not HTML-escaped)
        $this->assertStringContainsString('A-->B;', $html);
        $this->assertStringContainsString('</pre>', $html);
    }

    public function testSequenceDiagram(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` mermaid
sequenceDiagram
    Alice->>Bob: Hello Bob
    Bob-->>Alice: Hi Alice
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('sequenceDiagram', $html);
        $this->assertStringContainsString('Alice', $html);
    }

    public function testNonMermaidCodeBlockUnaffected(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` php
echo "Hello World";
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="language-php"', $html);
        $this->assertStringNotContainsString('class="mermaid"', $html);
    }

    public function testCodeBlockWithoutLanguageUnaffected(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
```
plain code
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringNotContainsString('class="mermaid"', $html);
        $this->assertStringContainsString('plain code', $html);
    }

    public function testCustomTag(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid(tag: 'div'));

        $djot = <<<'DJOT'
``` mermaid
graph LR;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<div class="mermaid">', $html);
        $this->assertStringContainsString('</div>', $html);
        $this->assertStringNotContainsString('<pre', $html);
    }

    public function testCustomCssClass(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid(cssClass: 'diagram'));

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="diagram"', $html);
        $this->assertStringNotContainsString('class="mermaid"', $html);
    }

    public function testWrapInFigure(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid(wrapInFigure: true));

        $djot = <<<'DJOT'
``` mermaid
pie title Pets
    "Dogs" : 45
    "Cats" : 30
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<figure class="mermaid-figure">', $html);
        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('</figure>', $html);
    }

    public function testCustomFigureClass(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid(
            wrapInFigure: true,
            figureClass: 'diagram-wrapper',
        ));

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<figure class="diagram-wrapper">', $html);
    }

    public function testPreservesAdditionalClasses(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
{.custom-diagram .large}
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('class="mermaid custom-diagram large"', $html);
    }

    public function testPreservesCustomAttributes(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
{#my-diagram data-theme="dark"}
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('id="my-diagram"', $html);
        $this->assertStringContainsString('data-theme="dark"', $html);
    }

    /**
     * Test that mermaid content is safely escaped while preserving arrow syntax.
     *
     * - `<` and `&` are escaped to prevent XSS
     * - `>` is preserved for Mermaid arrow syntax like `-->`
     */
    public function testEscapesHtmlWhilePreservingArrows(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A["<script>alert('xss')</script>"]-->B;
```
DJOT;

        $html = $converter->convert($djot);

        // < is escaped to &lt; to prevent XSS
        $this->assertStringContainsString('&lt;script>', $html);
        // > is preserved for Mermaid arrow syntax
        $this->assertStringContainsString('-->B;', $html);
        // Full expected output with proper escaping
        $this->assertStringContainsString("A[\"&lt;script>alert('xss')&lt;/script>\"]-->B;", $html);
    }

    public function testClassDiagram(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` mermaid
classDiagram
    Animal <|-- Duck
    Animal <|-- Fish
    Animal : +int age
    Animal : +String gender
    Animal: +isMammal()
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('classDiagram', $html);
        $this->assertStringContainsString('Animal', $html);
    }

    public function testGanttChart(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` mermaid
gantt
    title A Gantt Diagram
    dateFormat YYYY-MM-DD
    section Section
        A task          :a1, 2024-01-01, 30d
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('gantt', $html);
    }

    public function testErDiagram(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` mermaid
erDiagram
    CUSTOMER ||--o{ ORDER : places
    ORDER ||--|{ LINE-ITEM : contains
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('erDiagram', $html);
        $this->assertStringContainsString('CUSTOMER', $html);
    }

    public function testGitGraph(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` mermaid
gitGraph
    commit
    commit
    branch develop
    checkout develop
    commit
    commit
    checkout main
    merge develop
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('gitGraph', $html);
    }

    public function testStateDiagram(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` mermaid
stateDiagram-v2
    [*] --> Still
    Still --> [*]
    Still --> Moving
    Moving --> Still
    Moving --> Crash
    Crash --> [*]
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<pre class="mermaid">', $html);
        $this->assertStringContainsString('stateDiagram-v2', $html);
    }

    public function testMultipleMermaidBlocks(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
First diagram:

``` mermaid
graph TD;
    A-->B;
```

Second diagram:

``` mermaid
graph LR;
    C-->D;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertSame(2, substr_count($html, '<pre class="mermaid">'));
        // Raw syntax preserved for Mermaid.js
        $this->assertStringContainsString('A-->B;', $html);
        $this->assertStringContainsString('C-->D;', $html);
    }

    public function testDivTagWithFigure(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid(
            tag: 'div',
            wrapInFigure: true,
        ));

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<figure class="mermaid-figure">', $html);
        $this->assertStringContainsString('<div class="mermaid">', $html);
        $this->assertStringContainsString('</div>', $html);
        $this->assertStringContainsString('</figure>', $html);
    }

    public function testRoundTripModeAddsDjotSrc(): void
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $converter = CarveConverter::create(renderer: $renderer);
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('data-djot-src="', $html);
        $this->assertStringContainsString('``` mermaid', $html);
    }

    public function testNonRoundTripModeNoDjotSrc(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $djot = <<<'DJOT'
``` mermaid
graph TD;
    A-->B;
```
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringNotContainsString('data-djot-src=', $html);
    }
}
