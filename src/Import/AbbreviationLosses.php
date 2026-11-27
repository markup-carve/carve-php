<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Inline\Chars;

/**
 * Text fields of an imported tree that its written abbreviation definitions turn into abbreviation nodes.
 *
 * @phpstan-type ScanState array{word: string, before: int, last: int, paths: array<string, true>, inWord: bool, tooLong: bool}
 */
final class AbbreviationLosses
{
    /**
     * @var string
     */
    private const ASCII_WORD = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * Block leaves whose children are ordinary inline flow.
     *
     * @var array<string, true>
     */
    private const LEAVES = [
        'paragraph' => true,
        'heading' => true,
        'definition_term' => true,
        'line' => true,
        'cell_paragraph' => true,
    ];

    /**
     * Nodes whose content is an atom in the surrounding inline flow (C2-I-099 to C2-I-100).
     *
     * @var array<string, true>
     */
    private const ATOMS = [
        'code' => true,
        'math' => true,
        'literal_inline' => true,
        'image' => true,
        'raw_inline' => true,
        'inline_extension' => true,
        'footnote_ref' => true,
        'snippet_use' => true,
    ];

    /**
     * @var array<string, true>
     */
    private array $keys = [];

    private int $maxLength = 0;

    /**
     * @var array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    private array $losses = [];

    /**
     * @param array<string, array<string, mixed>> $abbreviations
     */
    private function __construct(array $abbreviations)
    {
        foreach (array_keys($abbreviations) as $key) {
            $key = (string)$key;
            if ($key === '' || strspn($key, self::ASCII_WORD) !== strlen($key)) {
                continue;
            }
            $this->keys[$key] = true;
            $this->maxLength = max($this->maxLength, strlen($key));
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, array<string, mixed>> $abbreviations
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function find(array $document, array $abbreviations): array
    {
        if ($abbreviations === []) {
            return [];
        }
        $scan = new self($abbreviations);
        if ($scan->keys === []) {
            return [];
        }
        // The writer spells text directly. Scan the imported inline flow, not a second parsed document: the latter
        // doubles peak memory even when the definition's key does not appear in any text field.
        $scan->visit($document, '');

        return $scan->losses;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     */
    private function visit(array $node, string $path): void
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        foreach ($node as $field => $value) {
            if (!is_array($value) || in_array($field, ['attrs', 'data', 'metadata', 'pos', 'segments'], true)) {
                continue;
            }
            $here = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$field);
            if (($field === 'children' && isset(self::LEAVES[$type])) || $field === 'title') {
                $this->flow($value, $here);
            } elseif (is_string($value['type'] ?? null)) {
                $this->visit($value, $here);
            } else {
                foreach ($value as $i => $child) {
                    if (is_array($child) && is_string($child['type'] ?? null)) {
                        $this->visit($child, $here . '/' . $i);
                    }
                }
            }
        }
    }

    /**
     * @param array<mixed> $items
     * @param string $path
     */
    private function flow(array $items, string $path): void
    {
        $state = ['word' => '', 'before' => -1, 'last' => -1, 'paths' => [], 'inWord' => false, 'tooLong' => false];
        foreach ($items as $i => $node) {
            if (is_array($node)) {
                $this->inline($node, $path . '/' . $i, $state);
            }
        }
        $this->finish(-1, $state);
    }

    /**
     * @phpstan-param ScanState $state
     *
     * @param array<mixed> $node
     * @param string $path
     * @param array<string, mixed> $state
     */
    private function inline(array $node, string $path, array &$state): void
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $literal = self::visible($node, $type);
        if ($literal !== null) {
            $this->feed($literal, $type === 'text' ? $path : null, $state);

            return;
        }
        if ($type === 'comment' && ($node['form'] ?? null) === 'brace') {
            return;
        }
        if ($type === 'inline_footnote' && is_array($node['children'] ?? null)) {
            $this->boundary($state);
            $this->flow($node['children'], $path . '/children');
            $this->boundary($state);

            return;
        }
        if (is_array($node['children'] ?? null) && !isset(self::ATOMS[$type])) {
            foreach ($node['children'] as $i => $child) {
                if (is_array($child)) {
                    $this->inline($child, $path . '/children/' . $i, $state);
                }
            }

            return;
        }
        $this->boundary($state);
    }

    /**
     * @param array<mixed> $node
     * @param string $type
     */
    private static function visible(array $node, string $type): ?string
    {
        return match ($type) {
            'text' => is_string($node['value'] ?? null) ? $node['value'] : null,
            'abbreviation' => is_string($node['key'] ?? null) ? $node['key'] : null,
            'soft_break' => "\n",
            'non_breaking_space' => "\u{00A0}",
            'smart_punctuation' => is_string($node['source'] ?? null) ? $node['source'] : null,
            default => null,
        };
    }

    /**
     * @phpstan-param ScanState $state
     *
     * @param string $value
     * @param string|null $path
     * @param array<string, mixed> $state
     */
    private function feed(string $value, ?string $path, array &$state): void
    {
        $length = strlen($value);
        for ($at = 0; $at < $length;) {
            $wordLength = strspn($value, self::ASCII_WORD, $at);
            if ($wordLength > 0) {
                if (!$state['inWord']) {
                    $state['inWord'] = true;
                    $state['before'] = $state['last'];
                }
                if (!$state['tooLong'] && strlen($state['word']) + $wordLength <= $this->maxLength) {
                    $state['word'] .= substr($value, $at, $wordLength);
                    if ($path !== null) {
                        $state['paths'][$path] = true;
                    }
                } else {
                    $state['tooLong'] = true;
                    $state['word'] = '';
                    $state['paths'] = [];
                }
                $state['last'] = ord($value[$at + $wordLength - 1]);
                $at += $wordLength;

                continue;
            }
            $cp = Chars::at($value, $at);
            $this->finish($cp, $state);
            $state['last'] = $cp;
            $at += Chars::len($value, $at);
        }
    }

    /**
     * @phpstan-param ScanState $state
     *
     * @param array<string, mixed> $state
     */
    private function boundary(array &$state): void
    {
        $this->finish(-1, $state);
        $state['last'] = -1;
    }

    /**
     * @phpstan-param ScanState $state
     *
     * @param int $next
     * @param array<string, mixed> $state
     */
    private function finish(int $next, array &$state): void
    {
        if (!$state['inWord']) {
            return;
        }
        if (
            !$state['tooLong'] && isset($this->keys[$state['word']])
            && !Chars::isWord($state['before']) && !Chars::isWord($next)
        ) {
            foreach (array_keys($state['paths']) as $path) {
                $this->losses[] = new Diagnostic(
                    'field-unspellable',
                    'warning',
                    'render-loss',
                    'Carve source cannot spell the value field of a text node here',
                    $path . '/value',
                );
            }
        }
        $state['word'] = '';
        $state['paths'] = [];
        $state['inWord'] = false;
        $state['tooLong'] = false;
    }
}
