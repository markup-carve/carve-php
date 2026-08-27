<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * Serialize an extension element's attributes in the author's SOURCE order,
 * matching carve-js / carve-rs and the core inline-span renderer.
 *
 * The core div/admonition renderer deliberately emits `class` first (the
 * structural type class leads); extensions do NOT - an authored `#id` that
 * comes before a class in the source stays first (issue #304). This trait
 * reads {@see Node::getAttributeOrder()} (the recorded source order that fmt
 * already uses) rather than the storage order, and merges the extension's own
 * fixed classes into the `class` value at its slot.
 */
trait ExtensionAttributesTrait
{
    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     * @param array<string> $fixedClasses Extension classes, prepended to the author's classes.
     * @param array<string> $excludeAttrs Attribute names to drop entirely.
     * @param array<string> $excludeClasses Author classes to drop from the merged value.
     * @param array<string, string> $defaultAttrs Added only when the author did not set them.
     * @param string|null $tag The element being written, so a text-alignment
     *   `align` renders its CSS declaration (markup-carve/carve#1755).
     */
    protected function renderExtensionAttributes(
        Node $node,
        HtmlRenderer $renderer,
        array $fixedClasses = [],
        array $excludeAttrs = [],
        array $excludeClasses = [],
        array $defaultAttrs = [],
        ?string $tag = null,
    ): string {
        $stored = $node->getAttributes();
        if ($excludeAttrs !== []) {
            $drop = array_flip(array_map('strtolower', $excludeAttrs));
            foreach (array_keys($stored) as $name) {
                if (isset($drop[strtolower((string)$name)])) {
                    unset($stored[$name]);
                }
            }
        }

        // Merged class value: fixed classes first, then the author's, deduped.
        $classes = [];
        foreach ($fixedClasses as $class) {
            if ($class !== '' && !in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }
        foreach ($node->getClassList() as $class) {
            if ($class !== '' && !in_array($class, $excludeClasses, true) && !in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }
        $mergedClass = implode(' ', $classes);

        // Rebuild the attribute array in SOURCE order (getAttributeOrder slots
        // first, then any programmatic leftovers), substituting the merged class.
        $ordered = [];
        $emit = function (string $key) use (&$ordered, $stored, $mergedClass): void {
            if (array_key_exists($key, $ordered)) {
                return;
            }
            if ($key === 'class') {
                if ($mergedClass !== '') {
                    $ordered['class'] = $mergedClass;
                }

                return;
            }
            if (array_key_exists($key, $stored)) {
                $ordered[$key] = $stored[$key];
            }
        };
        foreach ($node->getAttributeOrder() as $slot) {
            $emit($slot === '#id' ? 'id' : ($slot === '.class' ? 'class' : $slot));
        }
        foreach (array_keys($stored) as $key) {
            $emit((string)$key);
        }
        // An extension class with no authored class slot: append it.
        if ($mergedClass !== '' && !array_key_exists('class', $ordered)) {
            $ordered['class'] = $mergedClass;
        }
        // Author attributes get the always-on hardening and safe-mode name
        // filtering.
        $ordered = $renderer->sanitizeAttributes($ordered);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $ordered = $safeMode->filterAttributes($ordered);
        }

        // Extension-generated defaults are TRUSTED, so they are applied AFTER
        // filtering (a strict safe mode must not strip an extension's own
        // `style`, e.g. the color-swatch tint). They win only where the author
        // set nothing - and an author value that safe mode just stripped counts
        // as absent, matching the pre-refactor behavior.
        foreach ($defaultAttrs as $name => $value) {
            if (!array_key_exists($name, $ordered)) {
                $ordered[$name] = $value;
            }
        }

        return $renderer->renderAttributeArray($ordered, $tag);
    }

    /**
     * The `role` and the accessible name for a wrapper an extension builds.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     * @param string $role The role to claim when the author claimed none.
     * @param string $groupLabel The resolved name; empty suppresses the name.
     *
     * @return array<string, string>
     */
    protected function groupNameAttributes(Node $node, HtmlRenderer $renderer, string $role, string $groupLabel): array
    {
        $authored = $this->authoredAttributeNames($node, $renderer);

        $attrs = [];
        if (!isset($authored['role'])) {
            $attrs['role'] = $role;
        }

        // Union, so the role stays ahead of the name it belongs to.
        return $attrs + $this->accessibleNameAttributes($node, $renderer, $groupLabel);
    }

    /**
     * The accessible name alone, for an element whose role is already implicit.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     * @param string $label The resolved name; empty suppresses it.
     *
     * @return array<string, string>
     */
    protected function accessibleNameAttributes(Node $node, HtmlRenderer $renderer, string $label): array
    {
        $authored = $this->authoredAttributeNames($node, $renderer);
        if (
            $label === ''
            || isset($authored['aria-label'])
            || isset($authored['aria-labelledby'])
        ) {
            return [];
        }

        return ['aria-label' => $label];
    }

    /**
     * The lower-cased names of the author's attributes that SURVIVE hardening.
     *
     * Read through the same hardening and safe-mode filtering
     * {@see self::renderExtensionAttributes()} applies, so a value safe mode is
     * about to strip counts as absent. Reading the raw node instead would let a
     * host blocking `aria-label` suppress the author's name AND ours, and leave
     * the element anonymous: the exact defect the name exists to fix.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     *
     * @return array<string, bool>
     */
    protected function authoredAttributeNames(Node $node, HtmlRenderer $renderer): array
    {
        $surviving = $renderer->sanitizeAttributes($node->getAttributes());
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $surviving = $safeMode->filterAttributes($surviving);
        }

        $authored = [];
        foreach (array_keys($surviving) as $name) {
            $authored[strtolower((string)$name)] = true;
        }

        return $authored;
    }
}
