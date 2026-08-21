<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Transforms code-group divs into tabbed code block interfaces
 *
 * This extension converts a div with class `code-group` containing multiple
 * code blocks into a tabbed interface, ideal for showing code examples in
 * multiple languages or variations.
 *
 * Labels are extracted from the language hint using `[Label]` suffix syntax,
 * falling back to the language name or "Code N".
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new CodeGroupExtension());
 *
 * // With custom syntax highlighter:
 * $converter->addExtension(new CodeGroupExtension(
 *     highlighter: fn(string $code, ?string $lang) => $highlighter->highlight($code, $lang),
 * ));
 * ```
 *
 * Input djot:
 * ```
 * ::: code-group
 * ``` php [Installation]
 * composer require php-collective/djot
 * ```
 *
 * ``` bash [NPM]
 * npm install @example/djot
 * ```
 * :::
 * ```
 *
 * Output HTML:
 * ```html
 * <div class="code-group">
 *   <input type="radio" name="codegroup-1" id="codegroup-1-tab-1" class="code-group-radio" checked>
 *   <label for="codegroup-1-tab-1" class="code-group-label">Installation</label>
 *   <input type="radio" name="codegroup-1" id="codegroup-1-tab-2" class="code-group-radio">
 *   <label for="codegroup-1-tab-2" class="code-group-label">NPM</label>
 *   <div class="code-group-panel">
 *     <pre><code class="language-php">composer require php-collective/djot</code></pre>
 *   </div>
 *   <div class="code-group-panel">
 *     <pre><code class="language-bash">npm install @example/djot</code></pre>
 *   </div>
 * </div>
 * ```
 *
 * The radio inputs are the control: they are what a keyboard reaches and what
 * a screen reader announces. So they are hidden visually and kept in the focus
 * order and the accessibility tree - `display: none` or `visibility: hidden`
 * would remove them from both and leave a code group that cannot be operated
 * without a mouse. Because the input itself is invisible, the focus ring is
 * drawn on the label it controls.
 *
 * Required CSS:
 * ```css
 * .code-group { display: flex; flex-wrap: wrap; }
 * .code-group-radio {
 *   position: absolute;
 *   width: 1px;
 *   height: 1px;
 *   overflow: hidden;
 *   clip-path: inset(50%);
 *   white-space: nowrap;
 * }
 * .code-group-label {
 *   padding: 0.5rem 1rem;
 *   cursor: pointer;
 *   border-bottom: 2px solid transparent;
 * }
 * .code-group-radio:focus-visible + .code-group-label {
 *   outline: 2px solid currentColor;
 *   outline-offset: 2px;
 * }
 * .code-group-radio:checked + .code-group-label {
 *   border-bottom-color: currentColor;
 *   font-weight: bold;
 * }
 * .code-group-panel {
 *   display: none;
 *   width: 100%;
 *   order: 1;
 * }
 * .code-group-radio:nth-of-type(1):checked ~ .code-group-panel:nth-of-type(1),
 * .code-group-radio:nth-of-type(2):checked ~ .code-group-panel:nth-of-type(2),
 * .code-group-radio:nth-of-type(3):checked ~ .code-group-panel:nth-of-type(3),
 * .code-group-radio:nth-of-type(4):checked ~ .code-group-panel:nth-of-type(4),
 * .code-group-radio:nth-of-type(5):checked ~ .code-group-panel:nth-of-type(5) {
 *   display: block;
 * }
 * ```
 *
 * ## Comparison with TabsExtension
 *
 * Use **CodeGroupExtension** when:
 * - You have multiple code blocks to display as tabs
 * - Labels come from language hints (`php [Label]`)
 * - You want syntax highlighting integration
 *
 * Use **TabsExtension** when:
 * - You have arbitrary content (not just code)
 * - Labels come from headings or attributes
 * - You need ARIA mode with full keyboard navigation
 */
class CodeGroupExtension implements ResettableExtensionInterface, StaticRenderExtensionInterface
{
    use ExtensionAttributesTrait;

    /**
     * Counter for generating unique group IDs
     */
    protected int $groupCounter = 0;

    /**
     * @param string $wrapperClass CSS class for the code-group container
     * @param string $panelClass CSS class for individual code panels
     * @param string $labelClass CSS class for tab labels
     * @param string $radioClass CSS class for radio inputs
     * @param string $idPrefix Prefix for generated IDs
     * @param \Closure|null $highlighter Optional syntax highlighter callback: fn(string $code, ?string $lang): string
     * @param string|null $groupLabel Accessible name for the code group AS A WHOLE; null takes the render's `labels` map under `codeGroup`
     */
    public function __construct(
        protected string $wrapperClass = 'code-group',
        protected string $panelClass = 'code-group-panel',
        protected string $labelClass = 'code-group-label',
        protected string $radioClass = 'code-group-radio',
        protected string $idPrefix = 'codegroup',
        protected ?Closure $highlighter = null,
        protected ?string $groupLabel = null,
    ) {
    }

    public function register(CarveConverter $converter): void
    {
        // Only applies to HTML output - other renderers render code blocks normally
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            if (!$node->hasClass('code-group')) {
                return;
            }

            $codeBlocks = $this->extractCodeBlocks($node);
            if ($codeBlocks === []) {
                return;
            }

            $html = $this->renderCodeGroup($node, $codeBlocks, $renderer);
            $event->setHtml($html);
        });
    }

    public function clear(): void
    {
        $this->groupCounter = 0;
    }

    /**
     * Static render: flatten the code group into a sequence of `<section>`s,
     * each headed by its `[label]` (the tab header in interactive mode). No
     * click, but every code panel and its language label survives - the
     * graceful degradation rule for tabs / code-group.
     */
    public function renderStaticHtml(RenderEvent $event, HtmlRenderer $renderer): bool
    {
        $node = $event->getNode();
        if (!$node instanceof Div) {
            return false;
        }
        if (!$node->hasClass('code-group')) {
            return false;
        }

        $codeBlocks = $this->extractCodeBlocks($node);
        if ($codeBlocks === []) {
            return false;
        }

        $attrs = $this->buildWrapperAttributes($node, $renderer);
        $html = '<div' . $attrs . ">\n";
        foreach ($codeBlocks as $item) {
            $html .= '<section class="' . StringUtil::escapeHtml($this->panelClass) . "\">\n"
                . '<p class="' . StringUtil::escapeHtml($this->labelClass) . '">'
                . StringUtil::escapeHtml($item['label']) . "</p>\n"
                . $this->renderCodeBlock($item['block'], $item['language'], $renderer)
                . "</section>\n";
        }
        $html .= "</div>\n";

        $event->setHtml($html);

        return true;
    }

    /**
     * Extract code blocks from the div
     *
     * @return array<array{block: \MarkupCarve\Carve\Node\Block\CodeBlock, language: string|null, label: string, selected: bool}>
     */
    protected function extractCodeBlocks(Div $node): array
    {
        $blocks = [];
        $position = 0;

        foreach ($node->getChildren() as $child) {
            if (!$child instanceof CodeBlock) {
                continue;
            }

            $position++;
            $metadata = $this->parseLanguageMetadata($child->getLanguage(), $child->getLabel(), $position);

            // Check for selected attribute on preceding paragraph (djot attribute syntax)
            $selected = $child->hasAttribute('selected');

            $blocks[] = [
                'block' => $child,
                'language' => $metadata['language'],
                'label' => $metadata['label'],
                'selected' => $selected,
            ];
        }

        // If no block is explicitly selected, select the first one
        if ($blocks !== [] && !in_array(true, array_column($blocks, 'selected'), true)) {
            $blocks[0]['selected'] = true;
        }

        return $blocks;
    }

    /**
     * Resolve the tab language + label from a code block's structured fields.
     *
     * The parser already separates the language token from the bracketed
     * [label] (```php [Installation] -> language "php", label "Installation"),
     * so this no longer string-splits the info. Label resolution:
     * - explicit [label] wins;
     * - else fall back to the language name;
     * - else "Code N".
     *
     * @return array{language: string|null, label: string}
     */
    protected function parseLanguageMetadata(?string $language, ?string $label, int $position): array
    {
        $resolvedLanguage = ($language !== null && $language !== '') ? $language : null;

        $resolvedLabel = ($label !== null && trim($label) !== '') ? trim($label) : null;
        if ($resolvedLabel === null) {
            $resolvedLabel = $resolvedLanguage ?? 'Code ' . $position;
        }

        return ['language' => $resolvedLanguage, 'label' => $resolvedLabel];
    }

    /**
     * Render the code group as tabbed interface
     *
     * @param \MarkupCarve\Carve\Node\Block\Div $wrapper
     * @param array<array{block: \MarkupCarve\Carve\Node\Block\CodeBlock, language: string|null, label: string, selected: bool}> $codeBlocks
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     */
    protected function renderCodeGroup(Div $wrapper, array $codeBlocks, HtmlRenderer $renderer): string
    {
        $this->groupCounter++;
        $tracker = $renderer->getHeadingIdTracker();
        $groupId = $tracker->uniqueId($this->idPrefix . '-' . $this->groupCounter);

        // Build wrapper attributes
        $attrs = $this->buildWrapperAttributes($wrapper, $renderer);

        // Add data-djot-src for round-trip support
        if ($renderer->isRoundTripMode()) {
            $djotSrc = $this->reconstructDjotSource($wrapper, $codeBlocks);
            $attrs .= ' data-djot-src="' . StringUtil::escapeHtml($djotSrc) . '"';
        }

        $html = '<div' . $attrs . ">\n";

        // Render all radio inputs and labels first
        foreach ($codeBlocks as $index => $item) {
            $tabNum = $index + 1;
            $inputId = $tracker->uniqueId($groupId . '-tab-' . $tabNum);
            $checked = $item['selected'] ? ' checked' : '';

            $html .= '<input type="radio" name="' . StringUtil::escapeHtml($groupId) . '" ';
            $html .= 'id="' . StringUtil::escapeHtml($inputId) . '" ';
            $html .= 'class="' . StringUtil::escapeHtml($this->radioClass) . '"' . $checked . ">\n";

            $html .= '<label for="' . StringUtil::escapeHtml($inputId) . '" ';
            $html .= 'class="' . StringUtil::escapeHtml($this->labelClass) . '">';
            $html .= StringUtil::escapeHtml($item['label']);
            $html .= "</label>\n";
        }

        // Render all code panels
        foreach ($codeBlocks as $item) {
            $html .= '<div class="' . StringUtil::escapeHtml($this->panelClass) . '">';
            $html .= $this->renderCodeBlock($item['block'], $item['language'], $renderer);
            $html .= "</div>\n";
        }

        $html .= "</div>\n";

        return $html;
    }

    /**
     * Render a single code block, using highlighter if available
     */
    protected function renderCodeBlock(CodeBlock $block, ?string $language, HtmlRenderer $renderer): string
    {
        $code = rtrim($block->getContent(), "\n");

        // Use custom highlighter if provided
        if ($this->highlighter !== null) {
            return ($this->highlighter)($code, $language);
        }

        $renderBlock = new CodeBlock($block->getContent(), $language);

        foreach ($block->getAttributes() as $name => $value) {
            if ($name === 'selected') {
                continue;
            }

            $renderBlock->setAttribute($name, $value);
        }

        return $renderer->renderNodeFragment($renderBlock);
    }

    /**
     * Reconstruct the original Djot source for round-trip support
     *
     * @param \MarkupCarve\Carve\Node\Block\Div $wrapper
     * @param array<array{block: \MarkupCarve\Carve\Node\Block\CodeBlock, language: string|null, label: string, selected: bool}> $codeBlocks
     */
    protected function reconstructDjotSource(Div $wrapper, array $codeBlocks): string
    {
        $djot = $this->renderDjotAttributeBlock($wrapper, skipClasses: ['code-group']);
        $djot .= "::: code-group\n";

        foreach ($codeBlocks as $item) {
            /** @var \MarkupCarve\Carve\Node\Block\CodeBlock $block */
            $block = $item['block'];
            $langHint = $block->getLanguage() ?? '';
            $label = $block->getLabel();

            $content = $block->getContent();
            $fence = StringUtil::findSafeCodeFence($content, 3);

            $djot .= $this->renderDjotAttributeBlock($block);
            $djot .= $fence;
            if ($langHint !== '') {
                $djot .= ' ' . $langHint;
            }
            // Label is stored separately from the language; re-emit it.
            if ($label !== null) {
                $djot .= ' [' . $label . ']';
            }
            $djot .= "\n";

            // Ensure content ends with newline before closing fence
            if (!str_ends_with($content, "\n")) {
                $content .= "\n";
            }
            $djot .= $content;
            $djot .= $fence . "\n\n";
        }

        // Remove trailing blank line
        $djot = rtrim($djot) . "\n";
        $djot .= ":::\n";

        return $djot;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Div|\MarkupCarve\Carve\Node\Block\CodeBlock $node
     * @param array<string> $skipAttrs
     * @param array<string> $skipClasses
     */
    protected function renderDjotAttributeBlock(Div|CodeBlock $node, array $skipAttrs = [], array $skipClasses = []): string
    {
        $parts = [];

        $id = $node->getAttribute('id');
        if ($id !== null && $id !== '' && !in_array('id', $skipAttrs, true)) {
            $parts[] = '#' . $id;
        }

        if (!in_array('class', $skipAttrs, true)) {
            foreach ($node->getClassList() as $class) {
                if (!in_array($class, $skipClasses, true)) {
                    $parts[] = '.' . $class;
                }
            }
        }

        foreach ($node->getAttributes() as $name => $value) {
            if ($name === 'id' || $name === 'class' || in_array($name, $skipAttrs, true)) {
                continue;
            }

            $parts[] = $value === ''
                ? $name
                : $name . '=' . $this->quoteDjotAttributeValue($value);
        }

        if ($parts === []) {
            return '';
        }

        return '{' . implode(' ', $parts) . "}\n";
    }

    protected function quoteDjotAttributeValue(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9._:-]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Build wrapper div attributes
     */
    protected function buildWrapperAttributes(Div $wrapper, HtmlRenderer $renderer): string
    {
        // A plain GROUP: there are no tab/panel roles here to associate, so
        // that is all the wrapper can honestly claim - and the name is the half
        // that was missing (markup-carve/carve#1468).
        return $this->renderExtensionAttributes(
            $wrapper,
            $renderer,
            [$this->wrapperClass],
            [],
            ['code-group'],
            $this->groupNameAttributes(
                $wrapper,
                'group',
                $this->groupLabel ?? $renderer->label('codeGroup'),
            ),
        );
    }
}
