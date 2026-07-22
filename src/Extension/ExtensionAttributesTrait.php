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
     */
    protected function renderExtensionAttributes(
        Node $node,
        HtmlRenderer $renderer,
        array $fixedClasses = [],
        array $excludeAttrs = [],
        array $excludeClasses = [],
        array $defaultAttrs = [],
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
        // Defaults only where the author set nothing.
        foreach ($defaultAttrs as $name => $value) {
            if (!array_key_exists($name, $ordered)) {
                $ordered[$name] = $value;
            }
        }

        $ordered = $renderer->sanitizeAttributes($ordered);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $ordered = $safeMode->filterAttributes($ordered);
        }

        return $renderer->renderAttributeArray($ordered);
    }
}
