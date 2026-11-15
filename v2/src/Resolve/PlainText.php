<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Resolve;

use MarkupCarve\Carve2\Node;

/**
 * The plain text of inline content (C2-H-143): the text heading slugs, cross-reference text, task
 * labels and caption sequence keys are made from.
 */
final class PlainText
{
    /**
     * C2-H-143: nodes that give no text, fixed by type before any resolution.
     *
     * @var array<string, true>
     */
    private const SILENT = [
        'footnote_ref' => true,
        'inline_footnote' => true,
        'xref' => true,
        'symbol' => true,
        'raw_inline' => true,
        'critic_comment' => true,
        'comment' => true,
        'citation_group' => true,
    ];

    /**
     * @param array<\MarkupCarve\Carve2\Node> $nodes
     * @param array<string, string>|null $symbols Set for a caption key: a symbol gives the text the caption writes (C2-B-208).
     *
     * @return string
     */
    public static function of(array $nodes, ?array $symbols = null): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= self::node($node, $symbols);
        }

        return $out;
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     * @param array<string, string>|null $symbols
     *
     * @return string
     */
    public static function node(Node $node, ?array $symbols = null): string
    {
        $f = $node->f;
        switch ($node->type) {
            case 'text':
            case 'code':
            case 'literal_inline':
            case 'math':
                return self::str($f['value'] ?? '');
            case 'non_breaking_space':
                return "\u{00A0}";
            case 'soft_break':
            case 'hard_break':
                return ' ';
            case 'smart_punctuation':
                return self::str($f['glyph'] ?? $f['source'] ?? '');
            case 'image':
                return self::str($f['alt'] ?? '');
            case 'autolink':
                return self::str($f['text'] ?? $f['href'] ?? '');
            case 'abbreviation':
                return self::str($f['key'] ?? '');
            case 'mention':
                return '@' . self::str($f['target'] ?? '');
            case 'tag':
                return '#' . self::str($f['name'] ?? '');
            case 'caption_number':
                return isset($f['n']) && is_int($f['n']) ? (string)$f['n'] : '';
            case 'ruby':
                return self::ruby($node, $symbols);
            case 'symbol':
                if ($symbols === null) {
                    return '';
                }
                $name = self::str($f['name'] ?? '');

                return $symbols[$name] ?? ':' . $name . ':';
        }
        if (isset(self::SILENT[$node->type]) || ($node->type === 'inline_extension' && ($f['name'] ?? '') === 'index')) {
            return '';
        }

        return $node->children === null ? '' : self::of($node->children, $symbols);
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     * @param array<string, string>|null $symbols
     *
     * @return string
     */
    private static function ruby(Node $node, ?array $symbols): string
    {
        $out = '';
        $pairs = $node->f['pairs'] ?? [];
        foreach (is_array($pairs) ? $pairs : [] as $pair) {
            if (!$pair instanceof Node) {
                continue;
            }
            $out .= self::of(self::nodes($pair->f['base'] ?? []), $symbols) . '(' . self::of(self::nodes($pair->f['annotation'] ?? []), $symbols) . ')';
        }

        return $out;
    }

    /**
     * @param mixed $value
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    public static function nodes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if ($v instanceof Node) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    public static function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string)$value : '');
    }
}
