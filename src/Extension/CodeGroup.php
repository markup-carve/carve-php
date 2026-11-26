<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use InvalidArgumentException;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. `::: code-group` of code blocks as one switcher with a panel per block (`carve.codeGroup`).
 */
final class CodeGroup
{
    /**
     * @param string $wrapperClass
     * @param string $panelClass
     * @param string $labelClass
     * @param string $radioClass
     * @param string $idPrefix
     * @param string|null $groupLabel
     * @param (\Closure(string, string|null): string)|null $highlighter
     */
    private function __construct(
        private readonly string $wrapperClass,
        private readonly string $panelClass,
        private readonly string $labelClass,
        private readonly string $radioClass,
        private readonly string $idPrefix,
        private readonly ?string $groupLabel,
        private readonly ?Closure $highlighter,
    ) {
    }

    /**
     * @param string $mode `css`: radio inputs, no script. `aria`: tab roles, needs a script to switch panels.
     * @param string $wrapperClass
     * @param string $panelClass
     * @param string $labelClass
     * @param string $radioClass
     * @param string $idPrefix Prefix of the generated ids.
     * @param string|null $groupLabel Name of the group; default the `codeGroup` label (C2-H-125).
     * @param (\Closure(string, string|null): string)|null $highlighter Replaces the core code block HTML of a panel.
     *
     * @throws \InvalidArgumentException
     *
     * @return \MarkupCarve\Carve\Extension\Bundle
     */
    public static function bundle(
        string $mode = 'css',
        string $wrapperClass = 'code-group',
        string $panelClass = 'code-group-panel',
        string $labelClass = 'code-group-label',
        string $radioClass = 'code-group-radio',
        string $idPrefix = 'codegroup',
        ?string $groupLabel = null,
        ?Closure $highlighter = null,
    ): Bundle {
        if ($mode !== 'css' && $mode !== 'aria') {
            throw new InvalidArgumentException('codeGroup: unknown mode ' . $mode);
        }
        $group = new self($wrapperClass, $panelClass, $labelClass, $radioClass, $idPrefix, $groupLabel, $highlighter);

        return new Bundle([
            new ExtensionDefinition(
                'carve.codeGroup',
                '1',
                ['panels'],
                matcher: ['form' => 'div', 'name' => 'code-group'],
                transform: self::transform(...),
                renderer: $mode === 'aria' ? $group->renderAria(...) : $group->renderCss(...),
                buildTimeRenderer: $group->renderStatic(...),
            ),
        ]);
    }

    private function renderCss(Node $node, RenderHost $host): string
    {
        $group = ($host->nextId)($this->idPrefix);
        $controls = '';
        $bodies = '';
        foreach (self::panels($node, $host) as $at => $panel) {
            $id = self::attr($group . '-tab-' . ($at + 1));
            $controls .= '<input type="radio" name="' . self::attr($group) . '" id="' . $id . '" class="'
                . self::attr($this->radioClass) . '"' . ($panel['selected'] ? ' checked' : '') . '><label for="' . $id
                . '" class="' . self::attr($this->labelClass) . '">' . RenderHost::escapeText($panel['label']) . '</label>';
            $bodies .= '<div class="' . self::attr($this->panelClass) . '" role="group" aria-label="'
                . self::attr($panel['label']) . '">' . $this->code($panel, $host) . '</div>';
        }

        return $this->wrapper($node, $host, 'group') . $controls . $bodies . '</div>';
    }

    private function renderAria(Node $node, RenderHost $host): string
    {
        $group = ($host->nextId)($this->idPrefix);
        $controls = '';
        $bodies = '';
        foreach (self::panels($node, $host) as $at => $panel) {
            $tab = self::attr($group . '-tab-' . ($at + 1));
            $body = self::attr($group . '-panel-' . ($at + 1));
            $selected = $panel['selected'];
            // `type="button"`: a bare button inside a form would submit it.
            $controls .= '<button type="button" role="tab" id="' . $tab . '" aria-selected="' . ($selected ? 'true' : 'false')
                . '" aria-controls="' . $body . '" class="' . self::attr($this->labelClass) . '"'
                . ($selected ? '' : ' tabindex="-1"') . '>' . RenderHost::escapeText($panel['label']) . '</button>';
            $bodies .= '<div role="tabpanel" id="' . $body . '" aria-labelledby="' . $tab . '" class="'
                . self::attr($this->panelClass) . '"' . ($selected ? '' : ' hidden') . '>' . $this->code($panel, $host) . '</div>';
        }

        return $this->wrapper($node, $host, 'tablist') . $controls . $bodies . '</div>';
    }

    /**
     * C2-X-052: self-contained; one section per panel headed by its label, no controls and no generated ids.
     */
    private function renderStatic(Node $node, RenderHost $host): string
    {
        $sections = '';
        foreach (self::panels($node, $host) as $panel) {
            $sections .= '<section class="' . self::attr($this->panelClass) . '"><h3 class="' . self::attr($this->labelClass) . '">'
                . RenderHost::escapeText($panel['label']) . '</h3>' . $this->code($panel, $host) . '</section>';
        }

        return $this->wrapper($node, $host, 'group') . $sections . '</div>';
    }

    private function wrapper(Node $node, RenderHost $host, string $role): string
    {
        $attrs = $node->f['attrs'] ?? null;
        $attrs = $attrs instanceof Attrs ? $attrs : null;
        $name = $this->groupLabel ?? $host->label('codeGroup', 'Code examples');
        $pairs = [];
        if (!NodeAttributes::authored($attrs, 'role')) {
            $pairs[] = ['role', $role];
        }
        if ($name !== '' && !NodeAttributes::authored($attrs, 'aria-label') && !NodeAttributes::authored($attrs, 'aria-labelledby')) {
            $pairs[] = ['aria-label', $name];
        }

        return '<div' . $host->attributes(NodeAttributes::with($node, [$this->wrapperClass], $pairs, true)) . '>';
    }

    /**
     * @param array{block: \MarkupCarve\Carve\Node, node: \MarkupCarve\Carve\Node, label: string, selected: bool} $panel
     * @param \MarkupCarve\Carve\Extension\RenderHost $host
     */
    private function code(array $panel, RenderHost $host): string
    {
        if ($this->highlighter !== null) {
            $syntax = $panel['block']->f['syntax'] ?? null;

            $code = rtrim(PlainText::str($panel['block']->f['value'] ?? ''), "\n");

            return ($this->highlighter)($code, is_string($syntax) ? $syntax : null);
        }

        return ($host->blocks)([self::withoutSelected($panel['node'])]);
    }

    private static function attr(string $value): string
    {
        return RenderHost::escapeAttribute($value);
    }

    public static function transform(Claim $claim): ?Node
    {
        if ($claim->form !== 'div') {
            return null;
        }
        $body = $claim->node->children ?? [];
        $panels = array_values(array_filter($body, static fn (Node $n): bool => $n->type !== 'comment'));
        // Like tabs (C2-X-040): other blocks keep the div, so no authored content is dropped.
        if ($panels === [] || array_filter($panels, static fn (Node $n): bool => $n->type !== 'code_block') !== []) {
            return null;
        }
        $node = new Node('block_extension', []);
        $node->f['name'] = 'carve.codeGroup';
        $node->f['version'] = '1';
        $node->f['slots'] = ['panels' => $panels];
        $node->f['fallback'] = array_map(Extensions::copy(...), $body);

        return $node;
    }

    /**
     * The code block of a panel; a transform such as codeCallouts may have replaced it by a node with it as fallback.
     */
    private static function codeOf(Node $node): ?Node
    {
        if ($node->type === 'code_block') {
            return $node;
        }
        $fallback = PlainText::nodes($node->f['fallback'] ?? []);
        if ($node->type !== 'block_extension' || count($fallback) !== 1 || $fallback[0]->type !== 'code_block') {
            return null;
        }
        $code = clone $fallback[0];
        if (isset($node->f['attrs'])) {
            $code->f['attrs'] = $node->f['attrs'];
        }

        return $code;
    }

    /**
     * @return array<int, array{block: \MarkupCarve\Carve\Node, node: \MarkupCarve\Carve\Node, label: string, selected: bool}>
     */
    private static function panels(Node $node, RenderHost $host): array
    {
        $slots = $node->f['slots'] ?? null;
        $panels = [];
        foreach (PlainText::nodes(is_array($slots) ? $slots['panels'] ?? [] : []) as $child) {
            $block = self::codeOf($child);
            if ($block === null) {
                continue;
            }
            $label = self::label($block, $host) ?? 'Code ' . (count($panels) + 1);
            $panels[] = ['block' => $block, 'node' => $child, 'label' => $label, 'selected' => false];
        }
        $marked = 0;
        foreach ($panels as $at => $panel) {
            $attrs = $panel['block']->f['attrs'] ?? null;
            if ($attrs instanceof Attrs && self::hasKey($attrs, 'selected')) {
                $marked = $at;

                break;
            }
        }
        if (isset($panels[$marked])) {
            $panels[$marked]['selected'] = true;
        }

        return $panels;
    }

    /**
     * The bracket label, then the quoted title, then the language.
     */
    private static function label(Node $block, RenderHost $host): ?string
    {
        $title = $block->f['title'] ?? null;
        $group = $block->f['groupLabel'] ?? null;
        $syntax = $block->f['syntax'] ?? null;
        $labels = [
            is_string($group) ? trim($group) : '',
            is_array($title) ? trim(($host->plainText)(PlainText::nodes($title))) : '',
            is_string($syntax) ? $syntax : '',
        ];
        foreach ($labels as $label) {
            if ($label !== '') {
                return $label;
            }
        }

        return null;
    }

    private static function hasKey(Attrs $attrs, string $name): bool
    {
        foreach ($attrs->pairs as [$key]) {
            if ($key === $name) {
                return true;
            }
        }

        return false;
    }

    private static function withoutSelected(Node $node): Node
    {
        $attrs = $node->f['attrs'] ?? null;
        if (!$attrs instanceof Attrs || !self::hasKey($attrs, 'selected')) {
            return $node;
        }
        $copy = clone $node;
        $rest = clone $attrs;
        $rest->pairs = array_values(array_filter($attrs->pairs, static fn (array $p): bool => $p[0] !== 'selected'));
        $copy->f['attrs'] = $rest;

        return $copy;
    }
}
