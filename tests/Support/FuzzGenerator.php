<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded fuzz inputs for scripts/fuzz.php: random bytes, a Carve token soup, generated documents, mutants of the spec
 * examples and clause cases, and deep nesting of every container and inline span. A seed and an index name the same
 * input on every run, so a find is replayed from its two numbers.
 */
final class FuzzGenerator
{
    /**
     * @var array<string>
     */
    public const KINDS = ['bytes', 'soup', 'doc', 'mutant', 'nest'];

    /**
     * @var array<string>
     */
    private const TOKENS = [
        '*', '_', '`', '``', '```', '~~~', '{', '}', '{=', '=}',
        '{+', '+}', '{-', '-}', '{^', '^}', '{~', '~}', '{.c}',
        '{#i}', '{k=v}', '{k="a b"}', '{:de}', '[', ']', '](', ')', '![', '[^n]',
        '[^n]:', '[r]:', '[r]', '<', '>', '> ',
        '- ', '* ', '+ ', '1. ', 'a) ', 'i. ', '- [ ] ', '- [x] ', ': ', ':::',
        '::: note', '|', '|=', '|={<}', '{<}', '{>}',
        '{~}', '=', '= ', '== ', '#', '# ', '%%', '%%%', '\\', '\\ ',
        '$', '$`', '$$`', '"', "'", '...', '--', '---', '***',
        '@', '@a', ':s:', 'https://x.y/', '<https://x.y>', '<a@b.c>', '&amp;', '&#0;', '&#x110000;', '&',
        "\t", ' ', '  ',
        '    ', "\n", "\n", "\n\n", "\r\n", "\r", "\0", "\u{FEFF}", "\u{202E}", "\u{200B}",
        "\u{00A0}", 'é', '漢', '🙂', 'a',
        'word', 'x', '1', '0', '!', '?', ';', '^', '~', '+',
        '-', '/', '//', '=>', '<!--', '-->', '<b>', '</b>', '<div>',
        '``` =html', '{=html}', '::: {.c}', '[[', ']]', '{{', '}}', '((', '))', ':::',
        ':::x', '!include', '{!', '!}',
        '- - -', '* * *', '[a]{.b}', '[a](b "t")', '`a`{=html}', '"q"', '<?', '?>',
    ];

    /**
     * @var array<string>
     */
    private const OPENERS = [
        'quote' => '> ',
        'list' => '- ',
        'ordered' => '1. ',
        'div' => ":::\n",
        'term' => ': t',
    ];

    private Randomizer $random;

    /**
     * @param int $seed
     * @param array<string> $seeds Inputs to mutate.
     */
    public function __construct(int $seed, private array $seeds = [])
    {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    /**
     * The input of `$kind` for this generator's next draw.
     */
    public function next(string $kind): string
    {
        return match ($kind) {
            'bytes' => $this->bytes(),
            'soup' => $this->soup($this->random->getInt(1, 200)),
            'doc' => (new DocGenerator($this->random->getInt(0, PHP_INT_MAX)))->document(),
            'mutant' => $this->mutant(),
            default => $this->nest(),
        };
    }

    private function bytes(): string
    {
        $length = $this->random->getInt(0, 400);
        if ($this->random->getInt(0, 1) === 0) {
            return $this->random->getBytes(max(1, $length));
        }
        // Mostly ASCII punctuation and newlines, so the bytes reach past the first line.
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= chr($this->random->getInt(0, 3) === 0 ? 10 : $this->random->getInt(32, 126));
        }

        return $out;
    }

    private function soup(int $count): string
    {
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $out .= self::TOKENS[$this->random->getInt(0, count(self::TOKENS) - 1)];
        }

        return $out;
    }

    private function mutant(): string
    {
        $text = $this->seeds === [] ? $this->soup(30) : $this->seeds[$this->random->getInt(0, count($this->seeds) - 1)];
        $rounds = $this->random->getInt(1, 6);
        for ($i = 0; $i < $rounds; $i++) {
            $text = $this->mutate($text);
        }

        return $text;
    }

    private function mutate(string $text): string
    {
        $lines = explode("\n", $text);
        $at = $this->random->getInt(0, max(0, strlen($text) - 1));
        $line = $this->random->getInt(0, count($lines) - 1);

        switch ($this->random->getInt(0, 11)) {
            case 0:
                return $text === '' ? $text : substr_replace($text, chr($this->random->getInt(0, 255)), $at, 1);
            case 1:
                return substr_replace($text, $this->soup($this->random->getInt(1, 4)), $at, 0);
            case 2:
                return substr_replace($text, '', $at, $this->random->getInt(1, 8));
            case 3:
                array_splice($lines, $line, 0, [$lines[$line]]);

                return implode("\n", $lines);
            case 4:
                array_splice($lines, $line, 1);

                return implode("\n", $lines);
            case 5:
                $lines[$line] = ' ' . $lines[$line];

                return implode("\n", $lines);
            case 6:
                $lines[$line] = (string)preg_replace('/^ /', '', $lines[$line]);

                return implode("\n", $lines);
            case 7:
                $prefix = ['> ', '- ', '  ', '1. ', "\t", '| '][$this->random->getInt(0, 5)];

                return $prefix . str_replace("\n", "\n" . $prefix, $text);
            case 8:
                return ":::\n" . $text . "\n:::";
            case 9:
                $other = $this->seeds === [] ? $text : $this->seeds[$this->random->getInt(0, count($this->seeds) - 1)];

                return substr($text, 0, $at) . substr($other, $this->random->getInt(0, strlen($other)));
            case 10:
                return str_repeat($text, $this->random->getInt(2, 4));
            default:
                $lines[$line] = $lines[$line] . $this->soup(1);

                return implode("\n", $lines);
        }
    }

    private function nest(): string
    {
        $shape = $this->random->getInt(0, 13);
        // Indented shapes grow with the square of their depth; 5,000 levels of them would be 25 MB.
        $max = in_array($shape, [1, 8], true) ? 3 : 6;

        return self::nested($shape, [50, 100, 200, 500, 1000, 2000, 5000][$this->random->getInt(0, $max)]);
    }

    /**
     * Shape `$shape` nested `$depth` deep; scripts/fuzz.php also times these at doubling depths.
     */
    public static function nested(int $shape, int $depth): string
    {
        return match ($shape) {
            0 => str_repeat('> ', $depth) . "a\n",
            1 => implode('', array_map(static fn (int $i): string => str_repeat('  ', $i) . "- a\n", range(0, $depth - 1))),
            2 => str_repeat(":::\n", $depth) . "a\n" . str_repeat(":::\n", $depth),
            3 => str_repeat('{*a ', $depth) . 'b' . str_repeat('*}', $depth) . "\n",
            4 => str_repeat('[', $depth) . 'a' . str_repeat('](u)', $depth) . "\n",
            5 => str_repeat('*_', $depth) . 'a' . str_repeat('_*', $depth) . "\n",
            6 => str_repeat('{', $depth) . "a\n",
            7 => str_repeat('[^n]', $depth) . "\n\n[^n]: " . str_repeat('[^n]', $depth) . "\n",
            8 => implode('', array_map(static fn (int $i): string => str_repeat('   ', $i) . "1. a\n", range(0, $depth - 1))),
            9 => str_repeat('> - ', $depth) . "a\n",
            10 => str_repeat('{=', $depth) . 'a' . str_repeat('=}', $depth) . "\n",
            11 => str_repeat('![', $depth) . 'a' . str_repeat('](i)', $depth) . "\n",
            12 => str_repeat('`', $depth) . "a\n",
            default => implode('', array_map(static fn (int $i): string => str_repeat(':', 3 + $i % 40) . "\n", range(0, $depth - 1))),
        };
    }
}
