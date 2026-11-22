<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Block\BlockParser;
use MarkupCarve\Carve\Block\TreeSink;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\InlineRunner;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\NulReplacer;

/**
 * C2-W-078: a smart punctuation node with a run character beside it is written as its source only where the leaf reads
 * back with it (C2-I-104 to C2-I-117). The leaf is read back and aligned with its children as the carve-js repair
 * compares them (`compareList` in write-check.ts), so each difference is reported at the same path.
 */
final class SmartReadBack
{
    /**
     * Reads `source`, the written leaf, back and reports the children that do not read back as themselves.
     *
     * @param array<mixed> $children
     * @param string $source
     * @param string $path The path of the leaf.
     * @param string $type The type of the leaf.
     * @param \MarkupCarve\Carve\Writer\State $w
     *
     * @return void
     */
    public static function check(array $children, string $source, string $path, string $type, State $w): void
    {
        $w->chargeRepair($path, 'smart-run', $source);
        $parsed = self::leaf(self::parse($source, self::context($children, new InlineContext())), $type);
        if ($parsed === null) {
            return;
        }
        self::compareList(array_values($children), $parsed->children ?? [], $path . '/children', $path, $type, $w);
    }

    /**
     * The block and inline stages of `Carve::parse`: the later stages do not touch smart punctuation.
     *
     * @param string $source
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function parse(string $source, InlineContext $ctx): Node
    {
        $sink = new TreeSink($source);
        (new BlockParser($sink))->parse($source, true);
        $doc = $sink->root;
        assert($doc !== null);
        InlineRunner::run($doc, $source, $ctx);
        if (str_contains($source, "\0") || !mb_check_encoding($source, 'UTF-8')) {
            NulReplacer::apply($doc);
        }

        return $doc;
    }

    /**
     * The definitions elsewhere in the document that the nodes of the leaf use, so they read back as those nodes.
     *
     * @param array<mixed> $nodes
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     *
     * @return \MarkupCarve\Carve\Inline\InlineContext
     */
    private static function context(array $nodes, InlineContext $ctx): InlineContext
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $key = $node['key'] ?? null;
            if (is_string($key)) {
                $folded = Chars::fold($key);
                $type = $node['type'] ?? '';
                if ($type === 'abbreviation') {
                    $expansion = $node['expansion'] ?? '';
                    $ctx->abbreviations[$folded] ??= is_string($expansion) ? $expansion : '';
                } elseif ($type === 'footnote_ref') {
                    $ctx->footnoteKeys[$folded] = true;
                } elseif ($type === 'link' || $type === 'image') {
                    $ctx->linkKeys[$folded] = true;
                }
            }
            if (is_array($node['children'] ?? null)) {
                self::context($node['children'], $ctx);
            }
        }

        return $ctx;
    }

    /**
     * The leaf of the read-back document, where it is the only block of the type the writer wrote.
     *
     * @param \MarkupCarve\Carve\Node $document
     * @param string $type
     *
     * @return \MarkupCarve\Carve\Node|null
     */
    private static function leaf(Node $document, string $type): ?Node
    {
        $blocks = $document->children ?? [];
        $block = $blocks[0] ?? null;
        if (count($blocks) !== 1 || $block === null) {
            return null;
        }
        if ($type === 'definition_term') {
            $terms = $block->children ?? [];
            $block = $block->type === 'definition_list' && count($terms) === 1 ? $terms[0] : null;
        }

        return $block !== null && $block->type === $type ? $block : null;
    }

    /**
     * Returns false where the lists cannot be compared further.
     *
     * @param array<mixed> $expected
     * @param array<\MarkupCarve\Carve\Node> $read
     * @param string $path The path of the list `expected`.
     * @param string $ownerPath
     * @param string $ownerType
     * @param \MarkupCarve\Carve\Writer\State $w
     *
     * @return bool
     */
    private static function compareList(array $expected, array $read, string $path, string $ownerPath, string $ownerType, State $w): bool
    {
        $j = 0;
        $offset = 0;
        foreach ($expected as $i => $e) {
            if (!is_array($e)) {
                continue;
            }
            $type = is_string($e['type'] ?? null) ? $e['type'] : '';
            $a = $read[$j] ?? null;
            if ($type === 'text') {
                if (!self::compareText($e, $read, $j, $offset)) {
                    if ($a === null || $a->type === 'text' || $a->type === 'smart_punctuation') {
                        $w->lose($path . '/' . $i, 'text', 'value');
                    }

                    return false;
                }

                continue;
            }
            // A node behind part of a read text stands inside that text.
            if (!self::compareNode($e, $type, $offset > 0 ? null : $a, $path . '/' . $i, $w)) {
                return false;
            }
            $j++;
        }
        if ($j < count($read)) {
            $w->lose($ownerPath, $ownerType, null);

            return false;
        }

        return true;
    }

    /**
     * Returns false where the lists cannot be compared further.
     *
     * @param array<mixed> $e
     * @param string $type
     * @param \MarkupCarve\Carve\Node|null $a
     * @param string $at The path of `e`.
     * @param \MarkupCarve\Carve\Writer\State $w
     *
     * @return bool
     */
    private static function compareNode(array $e, string $type, ?Node $a, string $at, State $w): bool
    {
        if ($a === null || $a->type !== $type) {
            // The leaf is read without the definitions of its document, so only a run can be judged here.
            if ($type === 'smart_punctuation') {
                $w->lose($at, $type, null);
            }

            return false;
        }
        $field = $type === 'smart_punctuation' ? self::differingField($e, $a->f) : null;
        if ($field !== null) {
            $w->lose($at, $type, $field);
        }
        if (!is_array($e['children'] ?? null) || $a->children === null) {
            return true;
        }

        return self::compareList(array_values($e['children']), $a->children, $at . '/children', $at, $type, $w);
    }

    /**
     * Text reads back as the next characters of the read text, which may hold several text nodes of the tree.
     *
     * @param array<mixed> $e
     * @param array<\MarkupCarve\Carve\Node> $read
     * @param int $j The read node, moved past the text when it is used up.
     * @param int $offset The bytes of that node already matched.
     *
     * @return bool
     */
    private static function compareText(array $e, array $read, int &$j, int &$offset): bool
    {
        $value = is_string($e['value'] ?? null) ? $e['value'] : '';
        if ($value === '') {
            return true;
        }
        $a = $read[$j] ?? null;
        $readValue = $a !== null && $a->type === 'text' && is_string($a->f['value'] ?? null) ? $a->f['value'] : null;
        $length = strlen($value);
        if ($readValue === null || strlen($readValue) - $offset < $length || substr_compare($readValue, $value, $offset, $length) !== 0) {
            return false;
        }
        $offset += $length;
        if ($offset === strlen($readValue)) {
            $j++;
            $offset = 0;
        }

        return true;
    }

    /**
     * The first field, in the order of the node, in which the two differ.
     *
     * @param array<mixed> $e
     * @param array<string, mixed> $a
     *
     * @return string|null
     */
    private static function differingField(array $e, array $a): ?string
    {
        foreach ($e as $name => $value) {
            if ($name !== 'type' && (!array_key_exists($name, $a) || $a[$name] !== $value)) {
                return (string)$name;
            }
        }
        foreach (array_keys($a) as $name) {
            if (!array_key_exists($name, $e)) {
                return $name;
            }
        }

        return null;
    }
}
