<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Extension;

use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Resolve\PlainText;

/**
 * The standard extensions `carve.tabs` and `carve.tab` (C2-X-039 to C2-X-048).
 */
final class Tabs
{
    /**
     * The two registrations; `$renderer` adds the tabs renderer to `carve.tabs`.
     *
     * @param bool $renderer
     *
     * @return array<\MarkupCarve\Carve2\Extension\ExtensionDefinition>
     */
    public static function extensions(bool $renderer = false): array
    {
        $tab = new ExtensionDefinition('carve.tab', '1', ['title', 'body'], parent: ['name' => 'carve.tabs', 'slot' => 'tabs']);
        $tabs = new ExtensionDefinition(
            'carve.tabs',
            '1',
            ['tabs'],
            matcher: ['form' => 'div', 'name' => 'tabs'],
            transform: self::transform(...),
            renderer: $renderer ? self::render(...) : null,
        );

        return [$tab, $tabs];
    }

    /**
     * C2-X-040 to C2-X-042: a `tabs` div whose body is one or more `tab` divs with a nonempty quoted title.
     *
     * @param \MarkupCarve\Carve2\Extension\Claim $claim
     *
     * @return \MarkupCarve\Carve2\Node|null
     */
    public static function transform(Claim $claim): ?Node
    {
        $body = $claim->node->children ?? [];
        if ($claim->form !== 'div' || $body === []) {
            return null;
        }
        foreach ($body as $div) {
            $title = $div->f['title'] ?? null;
            if (
                $div->type !== 'div' || ($claim->sourceName)($div) !== 'tab' || !is_array($title)
                || trim(PlainText::of(PlainText::nodes($title))) === ''
            ) {
                return null;
            }
        }
        $tabs = [];
        $fallback = [];
        foreach ($body as $div) {
            $tab = self::tab($div, ($claim->divSource)($div, 'tab'));
            $tabs[] = $tab;
            foreach (PlainText::nodes($tab->f['fallback']) as $block) {
                $fallback[] = Extensions::copy($block);
            }
        }
        $node = new Node('block_extension', []);
        $node->f['name'] = 'carve.tabs';
        $node->f['version'] = '1';
        $node->f['slots'] = ['tabs' => $tabs];
        $node->f['fallback'] = $fallback;

        return $node;
    }

    /**
     * @param \MarkupCarve\Carve2\Node $div
     * @param array<string, string> $source
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function tab(Node $div, array $source): Node
    {
        $title = PlainText::nodes($div->f['title'] ?? []);
        $body = $div->children ?? [];
        $fallback = [];
        if ($title !== []) {
            $strong = new Node('strong', array_map(Extensions::copy(...), $title));
            $fallback[] = new Node('paragraph', [$strong]);
        }
        foreach ($body as $block) {
            $fallback[] = Extensions::copy($block);
        }
        $node = Node::at('block_extension', $div->start, $div->end);
        $node->children = [];
        $node->f['name'] = 'carve.tab';
        $node->f['version'] = '1';
        $node->f['slots'] = $title === [] ? ['body' => $body] : ['title' => $title, 'body' => $body];
        $node->f['fallback'] = $fallback;
        $node->f['source'] = $source;
        $attrs = Extensions::remainingAttrs($div);
        if ($attrs !== null) {
            $node->f['attrs'] = $attrs;
        }

        return $node;
    }

    /**
     * C2-X-043 to C2-X-048: one radio input, one linked label and one panel per tab; every panel stays in the HTML.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Extension\RenderHost $host
     *
     * @return string
     */
    public static function render(Node $node, RenderHost $host): string
    {
        $controls = '';
        $panels = '';
        $slots = $node->f['slots'] ?? [];
        $tabs = array_values(PlainText::nodes(is_array($slots) ? $slots['tabs'] ?? [] : []));
        do {
            $group = ($host->nextId)('tabs');
            $conflict = false;
            foreach ($tabs as $at => $_tab) {
                if (($host->idTaken)($group . '-' . $at)) {
                    $conflict = true;

                    break;
                }
            }
        } while ($conflict);
        foreach ($tabs as $at => $tab) {
            $tabSlots = is_array($tab->f['slots'] ?? null) ? $tab->f['slots'] : [];
            $title = PlainText::nodes($tabSlots['title'] ?? []);
            $label = $title === [] ? 'Tab ' . ($at + 1) : ($host->plainText)($title);
            $id = $group . '-' . $at;
            $controls .= '<input type="radio" name="' . $group . '" id="' . $id . '"' . ($at === 0 ? ' checked' : '') . '>';
            $controls .= '<label for="' . $id . '">' . ($title === [] ? RenderHost::escapeText($label) : ($host->inlines)($title))
                . '</label>';
            $panels .= '<div class="tab-panel" role="group" aria-label="' . RenderHost::escapeAttribute($label) . '">'
                . ($host->blocks)(PlainText::nodes($tabSlots['body'] ?? [])) . '</div>';
        }

        return '<div class="tabs" role="group" aria-label="' . RenderHost::escapeAttribute($host->groupLabel) . '">'
            . $controls . $panels . '</div>';
    }
}
