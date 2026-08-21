<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use InvalidArgumentException;
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
 * ### ARIA mode
 *
 * Semantic roles with a button/tabpanel structure. Requires JavaScript: the
 * reveal is `hidden`, which is why `css` stays the default (Extensions §13.1 -
 * a page that registers this mode and ships no script loses every panel but the
 * first, while `css` with no stylesheet at all shows them all).
 *
 * ```html
 * <div class="code-group" role="tablist" aria-label="Code examples">
 *   <button role="tab" id="codegroup-1-tab-1" aria-selected="true"
 *           aria-controls="codegroup-1-panel-1" class="code-group-label">Install</button>
 *   <div role="tabpanel" id="codegroup-1-panel-1" aria-labelledby="codegroup-1-tab-1"
 *        class="code-group-panel"><pre><code class="language-php">...</code></pre></div>
 * </div>
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
 *
 * ARIA mode is no longer a reason to reach for Tabs: this extension carries the
 * same `mode` option, and sending a reader there for accessible output cost
 * them the language-hint labels and the highlighter integration that are the
 * reason to use a code group at all (Extensions §13).
 */
class CodeGroupExtension implements ResettableExtensionInterface, StaticRenderExtensionInterface
{
    use ExtensionAttributesTrait;

    /**
     * Output mode: 'css' for CSS-only, 'aria' for ARIA with JS
     *
     * @var string
     */
    public const MODE_CSS = 'css';

    /**
     * @var string
     */
    public const MODE_ARIA = 'aria';

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
     * @param string $mode Output mode: 'css' (default) or 'aria'
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        protected string $wrapperClass = 'code-group',
        protected string $panelClass = 'code-group-panel',
        protected string $labelClass = 'code-group-label',
        protected string $radioClass = 'code-group-radio',
        protected string $idPrefix = 'codegroup',
        protected ?Closure $highlighter = null,
        protected ?string $groupLabel = null,
        protected string $mode = self::MODE_CSS,
    ) {
        // `css` IS THE DEFAULT AND MUST STAY IT (Extensions §13.1). Not for
        // compatibility - for §2.5: content is never dropped, only interaction.
        // `aria` mode reveals with `hidden`, so a page that registers it and
        // ships no script loses every panel but the first, while `css` with no
        // stylesheet at all shows every panel. A default whose failure mode is
        // missing content is the wrong default.
        //
        // And an unknown value is REFUSED rather than guessed, for the reason
        // §2.5 gives about render modes: a guess turns a typo into silently
        // different output.
        if ($mode !== self::MODE_CSS && $mode !== self::MODE_ARIA) {
            throw new InvalidArgumentException(
                'CodeGroupExtension mode must be "' . self::MODE_CSS . '" or "'
                . self::MODE_ARIA . '", got "' . $mode . '"',
            );
        }
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

            $html = $this->mode === self::MODE_ARIA
                ? $this->renderAriaCodeGroup($node, $codeBlocks, $renderer)
                : $this->renderCodeGroup($node, $codeBlocks, $renderer);
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
                . $renderer->escapeText($item['label']) . "</p>\n"
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
            $html .= $renderer->escapeText($item['label']);
            $html .= "</label>\n";
        }

        // Render all code panels, each NAMED BY ITS OWN LABEL (Extensions
        // §13.2) - the tab name where one was written, otherwise the language
        // word. `group` rather than `tabpanel` because the control revealing it
        // IS a radio, and the name is derived from the document, so per §1.5 it
        // takes no `labels` key.
        foreach ($codeBlocks as $item) {
            $html .= '<div class="' . StringUtil::escapeHtml($this->panelClass) . '"'
                . ' role="group" aria-label="' . $renderer->escapeAttribute($item['label']) . '">';
            $html .= $this->renderCodeBlock($item['block'], $item['language'], $renderer);
            $html .= "</div>\n";
        }

        $html .= "</div>\n";

        return $html;
    }

    /**
     * ARIA mode: `<button role="tab">` controls and `role="tabpanel"` panels.
     *
     * Mirrors `TabsExtension::renderAriaTabs()`, because two constructs of the
     * same shape do not get different accessibility ceilings because one of
     * them was written second (Extensions §13, markup-carve/carve#1468).
     *
     * The panel is BOUND, NOT NAMED (§13.3): `aria-labelledby` points at the
     * button, so it takes neither `role="group"` nor an `aria-label` - a second
     * name would give one element two, and pull it out of the `tablist`
     * relationship that is the only reason to be in this mode.
     *
     * Requires a client script: the reveal is `hidden`, which is exactly why
     * §13.1 keeps `css` the default.
     *
     * @param \MarkupCarve\Carve\Node\Block\Div $wrapper
     * @param array<array{block: \MarkupCarve\Carve\Node\Block\CodeBlock, language: string|null, label: string, selected: bool}> $codeBlocks
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     */
    protected function renderAriaCodeGroup(Div $wrapper, array $codeBlocks, HtmlRenderer $renderer): string
    {
        $this->groupCounter++;
        $tracker = $renderer->getHeadingIdTracker();
        $groupId = $tracker->uniqueId($this->idPrefix . '-' . $this->groupCounter);

        // Each tab/panel id pair is computed ONCE and reused in both loops, so a
        // bumped generated id keeps the ARIA wiring consistent.
        $ids = [];
        foreach ($codeBlocks as $index => $item) {
            $num = $index + 1;
            $ids[$index] = [
                'tab' => $tracker->uniqueId($groupId . '-tab-' . $num),
                'panel' => $tracker->uniqueId($groupId . '-panel-' . $num),
            ];
        }

        $attrs = $this->buildWrapperAttributes($wrapper, $renderer, 'tablist');

        // Round-trip metadata, as both Tabs renderers and the CSS one here do.
        // Without it, switching only the mode silently broke the HTML -> Carve
        // round trip, which is the shape §13 exists to prevent: two renderers
        // of the same construct do not get different capabilities because one
        // was written second.
        if ($renderer->isRoundTripMode()) {
            $djotSrc = $this->reconstructDjotSource($wrapper, $codeBlocks);
            $attrs .= ' data-djot-src="' . StringUtil::escapeHtml($djotSrc) . '"';
        }

        $html = '<div' . $attrs . ">\n";

        foreach ($codeBlocks as $index => $item) {
            $selected = $item['selected'] ? 'true' : 'false';
            $tabindex = $item['selected'] ? '' : ' tabindex="-1"';

            $html .= '<button role="tab" id="' . StringUtil::escapeHtml($ids[$index]['tab']) . '" ';
            $html .= 'aria-selected="' . $selected . '" ';
            $html .= 'aria-controls="' . StringUtil::escapeHtml($ids[$index]['panel']) . '" ';
            $html .= 'class="' . StringUtil::escapeHtml($this->labelClass) . '"' . $tabindex . '>';
            $html .= $renderer->escapeText($item['label']);
            $html .= "</button>\n";
        }

        foreach ($codeBlocks as $index => $item) {
            $hidden = $item['selected'] ? '' : ' hidden';

            $html .= '<div role="tabpanel" id="' . StringUtil::escapeHtml($ids[$index]['panel']) . '" ';
            $html .= 'aria-labelledby="' . StringUtil::escapeHtml($ids[$index]['tab']) . '" ';
            $html .= 'class="' . StringUtil::escapeHtml($this->panelClass) . '"' . $hidden . '>';
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
    protected function buildWrapperAttributes(Div $wrapper, HtmlRenderer $renderer, ?string $role = null): string
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
                $renderer,
                $role ?? 'group',
                $this->groupLabel ?? $renderer->label('codeGroup'),
            ),
        );
    }
}
