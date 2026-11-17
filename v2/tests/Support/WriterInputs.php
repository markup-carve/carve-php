<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Support;

use Generator;

/**
 * The inputs of the writer gates: the line shapes and the inline tokens of the carve-js 0.2 gates
 * (`test/support/enumerate.ts`, `test/support/inline-enumerate.ts`), every input over them up to a length, and seeded
 * random inputs. The generator is deterministic: a seed gives the same inputs on every run.
 */
final class WriterInputs
{
    /**
     * @var array<string>
     */
    public const LINES = [
        '', 'a', '  a', '    a', "\ta", '- a', '  - a', '    - a',
        ' - a', '* a', '- [x] a', '-', '1. a', '  2. a', '3) a', '. a',
        '> a', '>', '  > a', '- > a', '```', '  ```', '~~~', '- ```',
        '= a', '***', '%% a', '  %% a', ':: t', ': d', '  : d', 'a.  x',
        'i) x', '[^a]: x', '[a]: /u', '{.a}', '{{ x }}', '^ c', '![a](b)', ':::',
        '::: x', '::: |', '%%%', '---', '| a |', '|= a |', '| ^ | < |', '+ a |',
        '|', '{footer-rows=1}',
    ];

    /**
     * @var array<string>
     */
    public const TOKENS = [
        'a', ' ', "\n", '\\', '*', '/', '`', '[', ']', '(/u)',
        '![', '^[',
        '[^', ':n[', '{.c}', '{', '}', '{*', '*}', '{/', '/}', '{-',
        '-}', '{=',
        '=}', '{%', '%}', '{#', '#}', '%%', '<a:b>', '<', '@', '-',
        '"', '\'',
        '|', '\\`', '\\*', '\\/', '\\_', '\\~', '\\=', '\\^', '\\,', '\\+',
        '\\-', '`a`',
    ];

    /**
     * A byte-order mark, a lone CR, escaped sigils and brackets, a footnote reference and bare sigils.
     *
     * @var array<string>
     */
    public const RANDOM_TOKENS = ["\u{FEFF}", "\r", '\\!', '\\[', '\\]', '[^^]', '!', '^', '('];

    /**
     * Every glue-joined sequence of exactly `$length` items of `$alphabet`, behind `$prefix`.
     *
     * @param array<string> $alphabet
     * @param int $length
     * @param string $glue
     * @param string $prefix
     *
     * @return \Generator<int, string>
     */
    public static function enumerate(array $alphabet, int $length, string $glue, string $prefix): Generator
    {
        $count = count($alphabet);
        $choice = array_fill(0, $length, 0);
        while (true) {
            $parts = [];
            foreach ($choice as $at) {
                $parts[] = $alphabet[$at];
            }

            yield $prefix . implode($glue, $parts);

            $at = $length - 1;
            while ($at >= 0 && $choice[$at] === $count - 1) {
                $choice[$at--] = 0;
            }
            if ($at < 0) {
                return;
            }
            $choice[$at]++;
        }
    }

    /**
     * `$count` random inputs from `$seed`, in turn a content of 5 to 9 tokens behind `x` and a document of 4 to 12
     * lines, half of the lines with up to three tokens behind them.
     *
     * @param int $count
     * @param int $seed
     *
     * @return \Generator<int, string>
     */
    public static function random(int $count, int $seed): Generator
    {
        $random = new Mulberry32($seed);
        $tokens = array_merge(self::TOKENS, self::RANDOM_TOKENS);
        for ($at = 0; $at < $count; $at++) {
            if ($at % 2 === 0) {
                $out = 'x';
                for ($n = $random->between(5, 9); $n > 0; $n--) {
                    $out .= $random->pick($tokens);
                }

                yield $out;

                continue;
            }
            $lines = [];
            for ($n = $random->between(4, 12); $n > 0; $n--) {
                $line = $random->pick(self::LINES);
                if ($random->next() < 0.5) {
                    for ($k = $random->between(1, 3); $k > 0; $k--) {
                        $line .= $random->pick($tokens);
                    }
                }
                $lines[] = $line;
            }

            yield implode("\n", $lines);
        }
    }
}
