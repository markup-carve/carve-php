<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEquality;

/**
 * C2-X-023, C2-X-027, C2-X-049: whether the source form of an extension node's record reads back as the node. This is
 * the one place the writer parses what it writes, once per extension node, because the clauses define the spelling by
 * that parse.
 */
final class ExtensionSource
{
    /**
     * @param array<mixed> $node
     * @param string $text The opener, `source.body` and the closer, or `:name[body]`.
     * @param array<mixed> $extensions The registrations of the caller, `[]` for a writer without the extension.
     *
     * @return bool
     */
    public static function readsBack(array $node, string $text, array $extensions): bool
    {
        $source = is_array($node['source'] ?? null) ? $node['source'] : [];
        $form = Inline::str($source, 'form');
        $core = self::parse($text, []);
        $claimed = $form === 'inline' ? self::onlyInline($core) : self::only($core);
        if ($claimed === null || !self::isClaimed($claimed, $form, Inline::str($source, 'name'), Inline::str($source, 'body'))) {
            return false;
        }
        if ($extensions === []) {
            return true;
        }
        $extended = self::parse($text, $extensions);
        $back = $form === 'inline' ? self::onlyInline($extended) : self::only($extended);

        return $back !== null && TreeEquality::equal(self::bare($back), self::bare($node));
    }

    /**
     * @param array<mixed> $node
     * @param string $form
     * @param string $name
     * @param string $body
     *
     * @return bool
     */
    private static function isClaimed(array $node, string $form, string $name, string $body): bool
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];

        return match ($form) {
            'fence' => ($node['type'] ?? '') === 'code_block' && ($node['syntax'] ?? '') === $name && ($node['value'] ?? '') === $body,
            'div' => self::divName($node) === $name && Writer::writeBlocks($children) === $body,
            'inline' => ($node['type'] ?? '') === 'inline_extension' && ($node['name'] ?? '') === $name
                && self::inlineBody($children) === $body,
            default => false,
        };
    }

    /**
     * @param array<mixed> $node
     *
     * @return string|null
     */
    private static function divName(array $node): ?string
    {
        $type = $node['type'] ?? '';
        if ($type === 'admonition' || $type === 'directive') {
            return Inline::str($node, 'kind');
        }
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $classes = is_array($attrs['classes'] ?? null) ? $attrs['classes'] : [];

        return $type === 'div' && is_string($classes[0] ?? null) ? $classes[0] : null;
    }

    /**
     * @param array<mixed> $children
     *
     * @return string
     */
    private static function inlineBody(array $children): string
    {
        if ($children === []) {
            return '';
        }

        return rtrim(Writer::writeBlocks([['type' => 'paragraph', 'children' => $children]]), "\n");
    }

    /**
     * @param string $text
     * @param array<mixed> $extensions
     *
     * @return array<mixed>
     */
    private static function parse(string $text, array $extensions): array
    {
        $options = $extensions === [] ? [] : ['extensions' => $extensions];
        $tree = json_decode(Carve::toJson($text, $options), true, 100000);

        return is_array($tree) ? $tree : [];
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<mixed>|null
     */
    private static function only(array $tree): ?array
    {
        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];

        return count($children) === 1 && is_array($children[0]) ? $children[0] : null;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<mixed>|null
     */
    private static function onlyInline(array $tree): ?array
    {
        $paragraph = self::only($tree);
        if ($paragraph === null || ($paragraph['type'] ?? '') !== 'paragraph') {
            return null;
        }

        return self::only($paragraph);
    }

    /**
     * The node without the attributes, which the writer spells apart from the source form (C2-X-027).
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function bare(array $node): array
    {
        unset($node['attrs']);

        return $node;
    }
}
