<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic Carve documents for the round trip gate: blocks and inline content drawn from a seeded generator, so a
 * seed names the same document on every run.
 */
final class DocGenerator
{
    /**
     * @var array<string>
     */
    private const WORDS = ['tea', 'milk', 'Über', 'naïve', 'x2', 'Intro', 'café', 'λ', 'a&b', 'q<r'];

    /**
     * @var array<string>
     */
    private const ATTRS = ['', '{.c}', '{#id%d}', '{k=v}', '{.c lang=de}', '{:fr}', '{.c data-x=1}'];

    private Randomizer $random;

    private int $ids = 0;

    public function __construct(int $seed)
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    public function document(): string
    {
        $blocks = [];
        $count = $this->random->getInt(1, 6);
        for ($i = 0; $i < $count; $i++) {
            $blocks[] = $this->block(2);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    private function block(int $depth): string
    {
        $kind = $this->random->getInt(0, $depth > 0 ? 12 : 5);
        $n = ++$this->ids;

        return match ($kind) {
            0, 1 => $this->attrs() . $this->inline(2),
            2 => $this->attrs() . str_repeat('=', $this->random->getInt(1, 3)) . ' ' . $this->inline(1),
            3 => '```' . $this->pick(['', 'php', 'js']) . "\n" . $this->word() . " <b>\n```",
            4 => $this->table(),
            5 => '***',
            6 => $this->list($depth),
            7 => $this->prefix('> ', $this->block($depth - 1)),
            8 => $this->attrs() . '::: ' . $this->pick(['', 'note', 'warning']) . "\n" . $this->block($depth - 1) . "\n:::",
            9 => ': ' . $this->word() . "\n  " . $this->inline(1),
            10 => '[' . $this->word() . '][r' . $n . "]\n\n" . $this->attrs() . '[r' . $n . ']: /d' . $n,
            11 => $this->word() . '[^n' . $n . "] and more\n\n[^n" . $n . ']: ' . $this->inline(1),
            default => $this->word() . "\\\n" . $this->word() . ' %% note',
        };
    }

    private function list(int $depth): string
    {
        $marker = $this->pick(['-', '*', '1.', 'a)']);
        $loose = $this->random->getInt(0, 1) === 1 ? "\n\n" : "\n";
        $items = [];
        $count = $this->random->getInt(1, 3);
        for ($i = 0; $i < $count; $i++) {
            $box = $marker === '-' && $this->random->getInt(0, 3) === 0 ? '[x] ' : '';
            $body = $this->random->getInt(0, 3) === 0 ? $this->block($depth - 1) : $this->inline(1);
            $items[] = $marker . ' ' . $box . $this->indent($body, strlen($marker) + 1);
        }

        return implode($loose, $items);
    }

    private function table(): string
    {
        $columns = $this->random->getInt(1, 3);
        $rows = [];
        $head = $this->random->getInt(0, 1) === 1;
        for ($r = 0; $r < $this->random->getInt(1, 3); $r++) {
            $cells = [];
            for ($c = 0; $c < $columns; $c++) {
                $mark = ($head && $r === 0 ? '=' : '') . $this->pick(['', '', '{<}', '{~}', '{>}']);
                $cells[] = $mark . ' ' . $this->inline(0) . ' ';
            }
            $rows[] = '|' . implode('|', $cells) . '|';
        }

        return implode("\n", $rows);
    }

    private function inline(int $depth): string
    {
        $parts = [];
        $count = $this->random->getInt(1, 4);
        for ($i = 0; $i < $count; $i++) {
            $parts[] = $this->span($depth);
        }

        return implode($this->pick([' ', ' ', ' ', "\n"]), $parts);
    }

    private function span(int $depth): string
    {
        $kind = $this->random->getInt(0, $depth > 0 ? 11 : 3);
        $inner = $depth > 0 ? $this->inline($depth - 1) : $this->word();

        return match ($kind) {
            0, 1 => $this->word(),
            2 => '`' . $this->word() . '`',
            3 => '$`' . $this->word() . '`',
            4 => '*' . $inner . '*',
            5 => '_' . $inner . '_',
            6 => '[' . $inner . '](/u' . $this->random->getInt(0, 9) . ')',
            7 => '[' . $inner . ']' . $this->pick(['{.c}', '{k=v}', '{:de}']),
            8 => '![' . $this->word() . '](i.png)',
            9 => '{=' . $inner . '=}',
            10 => $this->word() . '\\ "' . $this->word() . '"',
            default => '[' . $inner . ']{.ext-' . $this->pick(['a', 'b']) . '}',
        };
    }

    private function attrs(): string
    {
        $attrs = sprintf($this->pick(self::ATTRS), ++$this->ids);

        return $attrs === '' ? '' : $attrs . "\n";
    }

    private function word(): string
    {
        return $this->pick(self::WORDS);
    }

    private function prefix(string $prefix, string $text): string
    {
        return $prefix . str_replace("\n", "\n" . $prefix, $text);
    }

    private function indent(string $text, int $width): string
    {
        return str_replace("\n", "\n" . str_repeat(' ', $width), $text);
    }

    /**
     * @param array<string> $options
     */
    private function pick(array $options): string
    {
        return $options[$this->random->getInt(0, count($options) - 1)];
    }
}
