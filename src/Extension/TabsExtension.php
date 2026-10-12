<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Render `tabs` containers as selectable panels. CSS mode uses radio controls;
 * ARIA mode emits tab roles and needs a client script for interaction.
 * See `docs/extensions.md` for markup and integration requirements.
 */
class TabsExtension implements ResettableExtensionInterface, StaticRenderExtensionInterface
{
    use ExtensionAttributesTrait;
    use SingleSelectionTrait;

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
     * Counter for generating unique tab set IDs
     */
    protected int $tabSetCounter = 0;

    /**
     * Counter for generating fallback tab labels
     */
    protected int $labelCounter = 0;

    /**
     * @param string $mode Output mode: 'css' (default) or 'aria'
     * @param string $wrapperClass CSS class for the tabs container
     * @param string $tabClass CSS class for individual tab panels
     * @param string $labelClass CSS class for tab labels/buttons
     * @param string $radioClass CSS class for radio inputs (CSS mode only)
     * @param string $idPrefix Prefix for generated IDs
     * @param string|null $groupLabel Accessible name for the tab set AS A WHOLE; null takes the render's `labels` map under `tabsGroup`
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        protected string $mode = self::MODE_CSS,
        protected string $wrapperClass = 'tabs',
        protected string $tabClass = 'tabs-panel',
        protected string $labelClass = 'tabs-label',
        protected string $radioClass = 'tabs-radio',
        protected string $idPrefix = 'tabset',
        protected ?string $groupLabel = null,
    ) {
        // AN UNKNOWN MODE IS REFUSED, NOT GUESSED (Extensions §13.1). The
        // dispatch below was `mode === MODE_ARIA ? aria : css`, so `arai` -
        // or `'ARIA'` - rendered the CSS mode silently and the host had no way
        // to find out. §2.5 makes the same call for render modes: a guess turns
        // a typo into different output nobody asked for.
        if ($mode !== self::MODE_CSS && $mode !== self::MODE_ARIA) {
            throw new InvalidArgumentException(
                'TabsExtension mode must be "' . self::MODE_CSS . '" or "'
                . self::MODE_ARIA . '", got "' . $mode . '"',
            );
        }
    }

    public function register(CarveConverter $converter): void
    {
        // Only applies to HTML output - other renderers render tab divs normally
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        // Use the renderer directly for nested content to avoid calling
        // $converter->render() which clears all extensions including TOC.
        // See: https://github.com/php-collective/djot-php/issues/139
        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            if (!$node->hasClassEntry('tabs')) {
                return;
            }

            $tabs = $this->collectTabs($node, $renderer);
            if ($tabs === []) {
                return;
            }

            $html = $this->mode === self::MODE_ARIA
                ? $this->renderAriaTabs($node, $tabs, $renderer)
                : $this->renderCssTabs($node, $tabs, $renderer);

            $event->setHtml($renderer->guardGeneratedWrapperInterior($html));
        });
    }

    public function clear(): void
    {
        $this->tabSetCounter = 0;
        $this->labelCounter = 0;
    }

    /**
     * Static render: flatten the tab set into a sequence of `<section>`s, each
     * headed by its `[label]` (the tab header in interactive mode). No click,
     * but every panel and its distinguishing label survives - the graceful
     * degradation rule for tabs / code-group.
     */
    public function renderStaticHtml(RenderEvent $event, HtmlRenderer $renderer): bool
    {
        $node = $event->getNode();
        if (!$node instanceof Div) {
            return false;
        }
        if (!$node->hasClassEntry('tabs')) {
            return false;
        }

        $tabs = $this->collectTabs($node, $renderer);
        if ($tabs === []) {
            return false;
        }

        $attrs = $this->buildWrapperAttributes($node, $renderer);
        $html = '<div' . $attrs . ">\n";
        foreach ($tabs as $tab) {
            $body = rtrim($tab['content'], "\n");
            $panelAttrs = $renderer->isRoundTripMode()
                ? $this->panelAttributes($tab['node'], $renderer, []) . ($tab['selected'] ? ' data-carve-selected' : '')
                : ' class="' . StringUtil::escapeHtml($this->tabClass) . '"';
            $html .= '  <section' . $panelAttrs . ">\n"
                . '  <h3 class="' . StringUtil::escapeHtml($this->labelClass) . '">'
                . $renderer->escapeText($tab['label']) . "</h3>\n"
                . ($body !== '' ? $body . "\n" : '')
                . "  </section>\n";
        }
        $html .= "</div>\n";

        $event->setHtml($html);

        return true;
    }

    /**
     * Collect tab data from child divs
     *
     * @return array<array{label: string, content: string, selected: bool, id: string|null, node: \MarkupCarve\Carve\Node\Block\Div}>
     */
    protected function collectTabs(Div $wrapper, HtmlRenderer $renderer): array
    {
        $tabs = [];

        foreach ($wrapper->getChildren() as $child) {
            if (!$child instanceof Div || !$child->hasClassEntry('tab')) {
                continue;
            }

            $label = $this->extractLabel($child, $renderer);
            $selected = $child->hasAttribute('selected');
            $id = $child->hasAttribute('id') ? (string)$child->getAttribute('id') : null;

            // Render the tab content using the renderer directly
            $content = $this->renderTabContent($child, $renderer);

            $tabs[] = [
                'label' => $label,
                'content' => $content,
                'selected' => $selected,
                'id' => $id,
                'node' => $child, // Store original node for round-trip reconstruction
            ];
        }

        // EXACTLY ONE TAB IS SELECTED (Extensions §13.5): the first one the
        // document marks, or the first tab where it marks none. Both branches
        // are the same statement, which is why they are one.
        $winner = $this->resolveSelectedIndex(array_column($tabs, 'selected'));
        foreach (array_keys($tabs) as $index) {
            $tabs[$index]['selected'] = $index === $winner;
        }

        return $tabs;
    }

    /**
     * Extract tab label from heading or attribute.
     *
     * The heading arm reads its text through HeadingIdTracker, which owns the
     * INLINE LEAF RULES. This used to be a second walk with arms for `Text` and
     * `SmartPunctuation` alone, recursing into everything else - and a `Code`,
     * `Math` or `LiteralInline` node has no children, so its content lived on
     * the node and the walk contributed nothing for it. A tab headed
     * `` ### `code()` and *bold* `` produced the label ` and bold`, with the
     * code span's text gone and its leading space stranded. The heading is
     * consumed by the label, so nothing else in the output carried the text
     * (markup-carve/carve-php#1075).
     *
     * The tracker's docblock says the leaf rules live there and nowhere else,
     * precisely so a second walk cannot answer them differently. This was that
     * second walk.
     */
    protected function extractLabel(Div $tab, HtmlRenderer $renderer): string
    {
        // Priority 1: opener `[label]` (canonical, inert node metadata)
        $label = $tab->getLabel();
        if ($label !== null && $label !== '') {
            return $label;
        }

        // Priority 2: `label` attribute from a preceding `{label="..."}` line
        // (deprecated in favor of the `[label]` opener token, still honored)
        if ($tab->hasAttribute('label')) {
            return (string)$tab->getAttribute('label');
        }

        // Priority 3: First heading
        foreach ($tab->getChildren() as $child) {
            if ($child instanceof Heading) {
                // TRIMMED, and the reason is not cosmetic. The leaf reader
                // contributes '' for a `section-number` span, so a heading the
                // HeadingNumbers extension has numbered leaves the SEPARATOR
                // behind and the label would read ` Title`. Whether a derived
                // label should carry the number at all is a question for the
                // leaf rules, not for this extension - carve-js keeps it - and
                // is recorded on carve-php#1075 rather than answered here.
                // Trimming a plain-text panel NAME is this caller's own
                // business either way.
                return trim($renderer->getHeadingIdTracker()->getDisplayText($child, $renderer->getSmartTypography()));
            }
        }

        // Fallback: generic label
        return 'Tab ' . ++$this->labelCounter;
    }

    /**
     * Render tab content, removing the label heading if present
     *
     * Uses the renderer directly instead of $converter->render() to avoid
     * clearing extension state (which would break TOC, etc.)
     */
    protected function renderTabContent(Div $tab, HtmlRenderer $renderer): string
    {
        // The first heading is consumed as the label ONLY when no explicit
        // label was given. An opener `[label]` or a `{label="..."}` attribute
        // takes priority (see extractLabel), so a real content heading must be
        // preserved in those cases.
        $hasExplicitLabel = ($tab->getLabel() ?? '') !== '' || $tab->hasAttribute('label');
        $skipFirstHeading = !$hasExplicitLabel;
        $skippedHeading = false;
        $html = '';

        // The quoted opener title is CONTENT, not the tab name (that is the
        // [label]'s job): it stays inside the panel as the same
        // admonition-title line core would emit. Without this the title
        // vanished from the output.
        $header = $tab->getHeader();
        if ($header !== null) {
            $html .= '<p class="admonition-title">' . $renderer->renderInlineNodesFragment($tab->getHeaderNodes()) . "</p>\n";
        }

        foreach ($tab->getChildren() as $child) {
            // Skip the first heading if we're using it as the label
            if ($skipFirstHeading && !$skippedHeading && $child instanceof Heading) {
                $skippedHeading = true;
                if ($renderer->isRoundTripMode()) {
                    $renderer->attributesBesideCarriers($child, ['data-carve-tab-title']);
                    $headingNode = clone $child;
                    foreach (array_keys($headingNode->getAttributeEntries()) as $name) {
                        if (in_array(strtolower((string)$name), ['data-carve-tab-title'], true)) {
                            $headingNode->removeAttribute((string)$name);
                        }
                    }
                    $heading = $renderer->renderNodeFragment($headingNode);
                    $marker = $child->hasAttribute('hidden') ? ' data-carve-tab-title="authored-hidden"' : ' hidden data-carve-tab-title';
                    $html .= preg_replace('/^(<h[1-6])/', '$1' . $marker, $heading);
                }

                continue;
            }

            $html .= $renderer->renderNodeFragment($child);
        }

        return $html;
    }

    /**
     * Render tabs using CSS-only approach with radio inputs
     *
     * @param \MarkupCarve\Carve\Node\Block\Div $wrapper
     * @param array<array{label: string, content: string, selected: bool, id: string|null, node: \MarkupCarve\Carve\Node\Block\Div}> $tabs
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     */
    protected function renderCssTabs(Div $wrapper, array $tabs, HtmlRenderer $renderer): string
    {
        $this->tabSetCounter++;
        $tracker = $renderer->getHeadingIdTracker();
        $setId = $tracker->uniqueId($this->idPrefix . '-' . $this->tabSetCounter);

        // Build wrapper attributes
        $attrs = $this->buildWrapperAttributes($wrapper, $renderer);

        $html = '<div' . $attrs . ">\n";

        // Render all radio inputs and labels first
        foreach ($tabs as $index => $tab) {
            $tabNum = $index + 1;
            $inputId = (!$renderer->isRoundTripMode() ? $tab['id'] : null) ?? $tracker->uniqueId($setId . '-tab-' . $tabNum);
            $checked = $tab['selected'] ? ' checked' : '';

            $html .= '<input type="radio" name="' . StringUtil::escapeHtml($setId) . '" ';
            $html .= 'id="' . StringUtil::escapeHtml($inputId) . '"';
            $html .= ' class="' . StringUtil::escapeHtml($this->radioClass) . '"' . $checked . ">\n";

            $html .= '<label for="' . StringUtil::escapeHtml($inputId) . '" ';
            $html .= 'class="' . StringUtil::escapeHtml($this->labelClass) . '">';
            $html .= $renderer->escapeText($tab['label']);
            $html .= "</label>\n";
        }

        // Render all tab panels, each NAMED BY ITS OWN TAB (Extensions §13.2).
        //
        // Under `css` there are no tab roles, so nothing binds a panel to the
        // control that reveals it: every radio and label is emitted before
        // every panel, and the panel itself is anonymous. `group` rather than
        // `tabpanel` because the control IS a radio, and a `<div>` rather than
        // a `<section>` because one landmark per panel is N landmarks per set.
        //
        // The name is DERIVED from the document - it is the tab's own label -
        // so per §1.5 it takes no `labels` key, exactly as an admonition title
        // does not: a translated document translates it once, in the document.
        foreach ($tabs as $tab) {
            $attrs = $renderer->isRoundTripMode()
                ? $this->panelAttributes($tab['node'], $renderer, ['role' => 'group', 'aria-label' => $tab['label']])
                : ' class="' . StringUtil::escapeHtml($this->tabClass) . '" role="group" aria-label="' . $renderer->escapeAttribute($tab['label']) . '"';
            $html .= '<div' . $attrs . '>';
            $html .= "\n" . $tab['content'];
            $html .= "</div>\n";
        }

        $html .= "</div>\n";

        return $html;
    }

    /**
     * Render tabs using ARIA roles (requires JavaScript)
     *
     * @param \MarkupCarve\Carve\Node\Block\Div $wrapper
     * @param array<array{label: string, content: string, selected: bool, id: string|null, node: \MarkupCarve\Carve\Node\Block\Div}> $tabs
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     */
    protected function renderAriaTabs(Div $wrapper, array $tabs, HtmlRenderer $renderer): string
    {
        $this->tabSetCounter++;
        $tracker = $renderer->getHeadingIdTracker();
        $setId = $tracker->uniqueId($this->idPrefix . '-' . $this->tabSetCounter);

        // Build wrapper attributes with tablist role
        $attrs = $this->buildWrapperAttributes($wrapper, $renderer, 'tablist');

        $html = '<div' . $attrs . ">\n";

        $tabIds = [];
        foreach ($tabs as $index => $tab) {
            $tabNum = $index + 1;
            $tabIds[$index] = [
                'tab' => $tab['id'] ? StringUtil::escapeHtmlAttribute($tab['id']) . '-tab' : $tracker->uniqueId($setId . '-tab-' . $tabNum),
                'panel' => $tab['id'] ? StringUtil::escapeHtmlAttribute($tab['id']) . ($renderer->isRoundTripMode() ? '' : '-panel') : $tracker->uniqueId($setId . '-panel-' . $tabNum),
            ];
        }

        // Render all tab buttons first
        foreach ($tabs as $index => $tab) {
            $tabId = $tabIds[$index]['tab'];
            $panelId = $tabIds[$index]['panel'];
            $selected = $tab['selected'] ? 'true' : 'false';
            $tabindex = $tab['selected'] ? '' : ' tabindex="-1"';

            // `type="button"`, NOT the implicit `submit` (Extensions §13.3).
            // A bare `<button>` is a submit button, so a tab set inside a
            // `<form>` submitted the form instead of switching panels - the one
            // interaction the mode exists to provide, traded for the one thing
            // the page never asked for.
            $html .= '<button type="button" role="tab" id="' . $tabId . '" ';
            $html .= 'aria-selected="' . $selected . '" ';
            $html .= 'aria-controls="' . $panelId . '" ';
            $html .= 'class="' . StringUtil::escapeHtml($this->labelClass) . '"' . $tabindex . '>';
            $html .= $renderer->escapeText($tab['label']);
            $html .= "</button>\n";
        }

        // Render all tab panels. NEITHER `role="group"` NOR A NAME here
        // (Extensions §13.3): the association already exists, so naming the
        // panel as well would give one element two accessible names and pull it
        // out of the `tablist` relationship that is the only reason to be in
        // this mode. §13.2 is a `css`-mode rule, not "every panel gets a name".
        foreach ($tabs as $index => $tab) {
            $tabId = $tabIds[$index]['tab'];
            $panelId = $tabIds[$index]['panel'];
            $hidden = $tab['selected'] ? '' : ' hidden';

            if ($renderer->isRoundTripMode()) {
                $attrs = $this->panelAttributes($tab['node'], $renderer, ['role' => 'tabpanel', 'id' => html_entity_decode($panelId, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'aria-labelledby' => html_entity_decode($tabId, ENT_QUOTES | ENT_HTML5, 'UTF-8')] + ($tab['selected'] ? [] : ['hidden' => '']));
                $html .= '<div' . $attrs . '>';
            } else {
                $html .= '<div role="tabpanel" id="' . $panelId . '" ';
                $html .= 'aria-labelledby="' . $tabId . '" ';
                $html .= 'class="' . StringUtil::escapeHtml($this->tabClass) . '"' . $hidden . '>';
            }
            $html .= "\n" . $tab['content'];
            $html .= "</div>\n";
        }

        $html .= "</div>\n";

        return $html;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Div $tab
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     * @param array<string, string> $defaults
     */
    protected function panelAttributes(Div $tab, HtmlRenderer $renderer, array $defaults): string
    {
        $attrs = $this->renderExtensionAttributes($tab, $renderer, [$this->tabClass], array_merge(['selected'], $tab->getLabel() !== null ? ['data-carve-label'] : [], $tab->hasAttribute('id') ? ['data-carve-explicit-id'] : []), ['tab'], $defaults, 'div', true);
        if ($tab->hasAttribute('id')) {
            $attrs .= ' data-carve-explicit-id="1"';
        }

        return $attrs . $this->panelCarriers($tab, $renderer);
    }

    protected function panelCarriers(Div $tab, HtmlRenderer $renderer): string
    {
        $label = $tab->getLabel();
        if (!$renderer->isRoundTripMode() || $label === null) {
            return '';
        }

        return ' data-carve-label="' . $renderer->escapeAttribute($label) . '"';
    }

    /**
     * Build wrapper div attributes
     */
    protected function buildWrapperAttributes(Div $wrapper, HtmlRenderer $renderer, ?string $role = null): string
    {
        // The CSS mode has no tab/panel roles to associate, so `group` - a
        // plain grouping - is all it can honestly claim. The ARIA mode passes
        // `tablist`. Either way the wrapper takes a NAME, which is the half
        // that was missing (markup-carve/carve#1468).
        return $this->renderExtensionAttributes(
            $wrapper,
            $renderer,
            [$this->wrapperClass],
            $renderer->isRoundTripMode() ? ['data-carve-ext'] : [],
            ['tabs'],
            $this->groupNameAttributes(
                $wrapper,
                $renderer,
                $role ?? 'group',
                $this->groupLabel ?? $renderer->label('tabsGroup'),
            ),
            'div',
            true,
        ) . ($renderer->isRoundTripMode() ? ' data-carve-ext="tabs"' : '');
    }
}
