<?php

declare(strict_types=1);

namespace Carve\Extension;

/**
 * Transforms code blocks with language "mermaid" into Mermaid.js-compatible markup
 *
 * This extension converts fenced code blocks with the `mermaid` language identifier
 * into HTML that Mermaid.js can render as diagrams.
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new MermaidExtension());
 *
 * // Or with custom settings:
 * $converter->addExtension(new MermaidExtension(
 *     tag: 'pre',
 *     cssClass: 'mermaid',
 *     wrapInFigure: false,
 * ));
 * ```
 *
 * Input djot:
 * ```
 * ``` mermaid
 * graph TD;
 *     A-->B;
 *     A-->C;
 *     B-->D;
 *     C-->D;
 * ```
 * ```
 *
 * Output HTML (default):
 * ```html
 * <pre class="mermaid">graph TD;
 *     A-->B;
 *     A-->C;
 *     B-->D;
 *     C-->D;
 * </pre>
 * ```
 *
 * Output HTML (with wrapInFigure: true):
 * ```html
 * <figure class="mermaid-figure">
 *   <pre class="mermaid">graph TD;
 *       A-->B;
 *   </pre>
 * </figure>
 * ```
 *
 * ## Required JavaScript
 *
 * Include Mermaid.js in your page:
 *
 * ```html
 * <script type="module">
 *   import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
 *   mermaid.initialize({ startOnLoad: true });
 * </script>
 * ```
 *
 * Or via npm:
 *
 * ```javascript
 * import mermaid from 'mermaid';
 * mermaid.initialize({ startOnLoad: true });
 * ```
 *
 * ## Supported Diagram Types
 *
 * Mermaid supports many diagram types including:
 * - Flowcharts (`graph TD`, `graph LR`)
 * - Sequence diagrams (`sequenceDiagram`)
 * - Class diagrams (`classDiagram`)
 * - State diagrams (`stateDiagram-v2`)
 * - Entity Relationship diagrams (`erDiagram`)
 * - Gantt charts (`gantt`)
 * - Pie charts (`pie`)
 * - Git graphs (`gitGraph`)
 * - And more...
 *
 * See https://mermaid.js.org/ for full documentation.
 */
class MermaidExtension extends FencedRenderExtension
{
    /**
     * @param string $tag HTML tag to use ('pre' or 'div')
     * @param string $cssClass CSS class for Mermaid.js to detect
     * @param bool $wrapInFigure Whether to wrap in a figure element
     * @param string $figureClass CSS class for the figure element
     */
    public function __construct(
        string $tag = 'pre',
        string $cssClass = 'mermaid',
        bool $wrapInFigure = false,
        string $figureClass = 'mermaid-figure',
    ) {
        parent::__construct(
            language: 'mermaid',
            cssClass: $cssClass,
            tag: $tag,
            contentMode: self::MODE_TEXT,
            wrapInFigure: $wrapInFigure,
            figureClass: $figureClass,
        );
    }
}
