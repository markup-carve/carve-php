<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Cli;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;

final class Fixer
{
    /**
     * @param string $source
     * @param array<string, mixed> $options
     */
    public static function fix(string $source, array $options): string
    {
        $fixable = self::catalogFixes();
        $rules = is_array($options['rules'] ?? null) ? $options['rules'] : [];
        foreach ($fixable as $code => $_) {
            if (!isset($rules[$code])) {
                $rules[$code] = 'on';
            }
        }
        $options['rules'] = $rules;
        while (true) {
            $edits = [];
            foreach (Carve::lint($source, $options) as $entry) {
                if (!isset($fixable[$entry->code]) || $entry->startOffset < 0 || $entry->file !== null) {
                    continue;
                }
                $replacement = self::replacement($source, $entry);
                if ($replacement !== null && ($entry->code !== 'djot-plus-bullet' || self::opensList($source, $entry, $replacement))) {
                    $edits[] = [$entry->startOffset, $entry->endOffset, $replacement];
                }
            }
            $selected = self::nonconflicting($edits);
            if ($selected === []) {
                break;
            }
            $next = $source;
            foreach (array_reverse($selected) as [$start, $end, $replacement]) {
                $next = substr_replace($next, $replacement, $start, $end - $start);
            }
            if ($next === $source) {
                break;
            }
            $source = $next;
        }

        return $source;
    }

    private static function opensList(string $source, Diagnostic $entry, string $replacement): bool
    {
        $changed = substr_replace($source, $replacement, $entry->startOffset, $entry->endOffset - $entry->startOffset);
        $tree = json_decode(Carve::toJson($changed), true);

        return is_array($tree) && self::hasItemAt($tree, $entry->startOffset);
    }

    /**
     * @param array<mixed> $node
     * @param int $offset
     */
    private static function hasItemAt(array $node, int $offset): bool
    {
        $pos = $node['pos'] ?? null;
        if (($node['type'] ?? null) === 'list_item' && is_array($pos) && ($pos['startOffset'] ?? null) === $offset) {
            return true;
        }
        $children = $node['children'] ?? null;
        foreach (is_array($children) ? $children : [] as $child) {
            if (is_array($child) && self::hasItemAt($child, $offset)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private static function catalogFixes(): array
    {
        $catalog = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/lint-rules.json'), true);
        $rows = is_array($catalog) ? ($catalog['rules'] ?? []) : [];
        $fixable = [];
        foreach (is_array($rows) ? $rows : [] as $rule) {
            if (is_array($rule) && isset($rule['fix']) && is_string($rule['id'] ?? null)) {
                $fixable[$rule['id']] = true;
            }
        }

        return $fixable;
    }

    /**
     * @param array<array{int, int, string}> $edits
     *
     * @return array<array{int, int, string}>
     */
    private static function nonconflicting(array $edits): array
    {
        usort($edits, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);
        $selected = [];
        foreach ($edits as $edit) {
            $last = count($selected) - 1;
            if ($last >= 0 && $edit[0] < $selected[$last][1]) {
                if ($edit[0] === $selected[$last][0] && $edit[1] === $selected[$last][1] && $edit[2] !== $selected[$last][2]) {
                    array_pop($selected);
                }

                continue;
            }
            $selected[] = $edit;
        }

        return $selected;
    }

    private static function replacement(string $source, Diagnostic $entry): ?string
    {
        $text = substr($source, $entry->startOffset, $entry->endOffset - $entry->startOffset);

        return match ($entry->code) {
            'markdown-strong-double-star' => preg_replace('/^\*\*(.*)\*\*$/s', '*$1*', $text),
            'markdown-strikethrough-double-tilde' => preg_replace('/^~~(.*)~~$/s', '{~$1~}', $text),
            'deprecated-bare-strike' => preg_replace('/^~(.*)~$/s', '{~$1~}', $text),
            'deprecated-bare-highlight' => preg_replace('/^=(.*)=$/s', '{=$1=}', $text),
            'djot-emphasis-underscore' => preg_replace('/^_(.*)_$/s', '/$1/', $text),
            'djot-intraword-underscore' => preg_replace('/^_(.*)_$/s', '{/$1/}', $text),
            'djot-subscript-tilde' => preg_replace('/^~(.*)~$/s', '{,$1,}', $text),
            'djot-superscript-caret' => preg_replace('/^\^(.*)\^$/s', '{^$1^}', $text),
            'heading-closing-hashes' => '',
            'djot-plus-bullet' => preg_replace('/^\+/', '-', $text),
            'thematic-break-spelling' => '***',
            default => null,
        };
    }
}
