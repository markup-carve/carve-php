<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. The standard document transforms (C2-X-053): each returns a bundle with one transform and no
 * definitions; pass `$bundle->transforms()` to `Carve::renderWithDocumentTransforms()`.
 */
final class Transforms
{
    /**
     * Default attributes per node type, merged under the authored ones: `class` adds classes, `id` sets a missing id,
     * any other key a missing pair.
     *
     * @param array<string, array<string, string>> $defaults Node type to its default attributes.
     *
     * @return \MarkupCarve\Carve\Extension\Bundle
     */
    public static function defaultAttributes(array $defaults): Bundle
    {
        $run = static function (Node $view) use ($defaults): Node {
            ViewWalk::edit($view, static function (array $nodes) use ($defaults): array {
                foreach ($nodes as $node) {
                    if (isset($defaults[$node->type])) {
                        self::applyDefaults($node, $defaults[$node->type]);
                    }
                }

                return $nodes;
            });

            return $view;
        };

        return new Bundle([], [new DocumentTransform('carve.defaultAttributes', $run)]);
    }

    /**
     * Replaces each tab in code blocks and code spans with `$width` spaces.
     *
     * @throws \InvalidArgumentException
     */
    public static function tabNormalize(int $width = 2): Bundle
    {
        if ($width < 0 || $width > 16) {
            throw new InvalidArgumentException('tab width must be an integer from 0 to 16');
        }
        $spaces = str_repeat(' ', $width);
        $run = static function (Node $view) use ($spaces): Node {
            ViewWalk::edit($view, static function (array $nodes) use ($spaces): array {
                foreach ($nodes as $node) {
                    if ($node->type === 'code_block' || $node->type === 'code') {
                        $node->f['value'] = str_replace("\t", $spaces, PlainText::str($node->f['value'] ?? ''));
                    }
                }

                return $nodes;
            });

            return $view;
        };

        return new Bundle([], [new DocumentTransform('carve.tabNormalize', $run)]);
    }

    /**
     * Writes each inline footnote as its content in parentheses after a space, for targets without notes.
     */
    public static function inlineFootnotesToParentheses(): Bundle
    {
        $run = static function (Node $view): Node {
            ViewWalk::edit($view, static function (array $nodes): array {
                $out = [];
                foreach ($nodes as $node) {
                    if ($node->type !== 'inline_footnote') {
                        $out[] = $node;

                        continue;
                    }
                    $open = new Node('text');
                    $open->f['value'] = ' (';
                    $close = new Node('text');
                    $close->f['value'] = ')';
                    array_push($out, $open, ...($node->children ?? []));
                    $out[] = $close;
                }

                return $out;
            });

            return $view;
        };

        return new Bundle([], [new DocumentTransform('carve.inlineFootnotesToParentheses', $run)]);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string, string> $defaults
     */
    private static function applyDefaults(Node $node, array $defaults): void
    {
        $authored = $node->f['attrs'] ?? null;
        $attrs = $authored instanceof Attrs ? $authored : new Attrs();
        foreach ($defaults as $name => $value) {
            if ($name === 'class') {
                foreach (explode(' ', $value) as $class) {
                    if ($class !== '' && !in_array($class, $attrs->classes, true)) {
                        $attrs->classes[] = $class;
                    }
                }
            } elseif ($name === 'id') {
                $attrs->id ??= $value;
            } elseif (!in_array($name, array_column($attrs->pairs, 0), true)) {
                $attrs->pairs[] = [$name, $value];
            }
        }
        if ($attrs->id !== null || $attrs->classes !== [] || $attrs->pairs !== []) {
            $node->f['attrs'] = $attrs;
        }
    }
}
