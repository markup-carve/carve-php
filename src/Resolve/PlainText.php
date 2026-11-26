<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Resolve;

use MarkupCarve\Carve\Node;

/**
 * The plain text of inline content (C2-H-143): the text heading slugs, cross-reference text, task
 * labels and caption sequence keys are made from.
 */
final class PlainText
{
    /**
     * C2-H-143: nodes with a fixed text; those that give none are fixed by type before any resolution.
     *
     * @var array<string, string>
     */
    private const FIXED = [
        'non_breaking_space' => "\u{00A0}",
        'soft_break' => ' ',
        'hard_break' => ' ',
        'footnote_ref' => '',
        'inline_footnote' => '',
        'xref' => '',
        'raw_inline' => '',
        'critic_comment' => '',
        'comment' => '',
        'citation_group' => '',
    ];

    /**
     * C2-H-143: nodes whose text is one field behind a prefix.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const FIELD = [
        'text' => ['value', ''],
        'code' => ['value', ''],
        'literal_inline' => ['value', ''],
        'math' => ['value', ''],
        'image' => ['alt', ''],
        'abbreviation' => ['key', ''],
        'mention' => ['target', '@'],
        'tag' => ['name', '#'],
    ];

    /**
     * C2-H-143: only nodes in the default arm take their children's plain text.
     *
     * @param string $type
     *
     * @return bool
     */
    public static function usesChildren(string $type): bool
    {
        return !isset(self::FIXED[$type]) && !isset(self::FIELD[$type])
            && !in_array($type, ['smart_punctuation', 'autolink', 'caption_number', 'ruby', 'symbol', 'inline_extension'], true);
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
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
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string, string>|null $symbols
     *
     * @return string
     */
    public static function node(Node $node, ?array $symbols = null): string
    {
        $type = $node->type;
        $field = self::FIELD[$type] ?? null;
        if ($field !== null) {
            return $field[1] . self::str($node->f[$field[0]] ?? '');
        }
        if (isset(self::FIXED[$type])) {
            return self::FIXED[$type];
        }

        return match ($type) {
            'smart_punctuation', 'autolink', 'caption_number' => self::derived($node),
            'ruby' => self::ruby($node, $symbols),
            'symbol' => self::symbol($node, $symbols),
            'inline_extension' => ($node->f['name'] ?? '') === 'index' ? '' : self::of($node->children ?? [], $symbols),
            default => self::of($node->children ?? [], $symbols),
        };
    }

    /**
     * @param \MarkupCarve\Carve\Node $node A smart punctuation, an autolink or a caption number.
     *
     * @return string
     */
    private static function derived(Node $node): string
    {
        $f = $node->f;

        return match ($node->type) {
            'smart_punctuation' => self::str($f['glyph'] ?? $f['source'] ?? ''),
            'autolink' => self::str($f['text'] ?? $f['href'] ?? ''),
            default => is_int($f['n'] ?? null) ? (string)$f['n'] : '',
        };
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string, string>|null $symbols
     *
     * @return string
     */
    private static function symbol(Node $node, ?array $symbols): string
    {
        if ($symbols === null) {
            return '';
        }
        $name = self::str($node->f['name'] ?? '');

        return $symbols[$name] ?? ':' . $name . ':';
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
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
            $base = self::of(self::nodes($pair->f['base'] ?? []), $symbols);
            $annotation = self::of(self::nodes($pair->f['annotation'] ?? []), $symbols);
            $out .= $base . '(' . $annotation . ')';
        }

        return $out;
    }

    /**
     * @param mixed $value
     *
     * @return array<\MarkupCarve\Carve\Node>
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
