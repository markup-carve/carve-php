<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\InlineParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the trigger-byte derivation used to gate extension inline
 * matchers (InlineParser::patternFirstBytes). The set MUST be a complete
 * superset of every byte that can begin a match, or null when it cannot be
 * safely bounded.
 */
class PatternFirstBytesTest extends TestCase
{
    protected InlineParser $parser;

    protected ReflectionMethod $method;

    protected function setUp(): void
    {
        $this->parser = new InlineParser(new BlockParser());
        $this->method = new ReflectionMethod(InlineParser::class, 'patternFirstBytes');
    }

    /**
     * @param string|null $pattern
     * @param array<string, true>|null $expected
     */
    #[DataProvider('firstBytesProvider')]
    public function testPatternFirstBytes(?string $pattern, ?array $expected): void
    {
        $result = $this->method->invoke($this->parser, $pattern);

        if ($expected === null) {
            $this->assertNull($result, 'Expected indeterminate (null) trigger set');

            return;
        }

        $this->assertIsArray($result);
        // Compare as sets (order-independent).
        ksort($result);
        ksort($expected);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{0: string|null, 1: array<string, true>|null}>
     */
    public static function firstBytesProvider(): array
    {
        return [
            // Plain literal first byte.
            'plain literal' => ['/foo/', ['f' => true]],
            'single char' => ['/x/', ['x' => true]],

            // Escaped literal first char (wikilinks: \[ ).
            'escaped bracket' => ['/\[\[([^\]|]+)\]\]/', ['[' => true]],
            'escaped dot' => ['/\.foo/', ['.' => true]],
            'escaped dollar' => ['/\$x/', ['$' => true]],

            // Escaped shorthand class is indeterminate.
            'escaped digit shorthand' => ['/\d+/', null],
            'escaped word shorthand' => ['/\w+/', null],
            'word boundary' => ['/\bfoo/', null],

            // Alternation / group at start (autolink: (http|https|ftp)).
            'alternation literals' => ['/(http|https|ftp):\/\//', ['h' => true, 'f' => true]],
            'single-alt group' => ['/(foo)bar/', ['f' => true]],
            'nested group alternation' => ['/((ab|cd)|ef)/', ['a' => true, 'c' => true, 'e' => true]],
            'alternation with escaped' => ['/(\[|foo)/', ['[' => true, 'f' => true]],
            'group then literal' => ['/(ab)cd/', ['a' => true]],

            // A group made optional pushes the trigger to what follows -> null.
            'optional group' => ['/(ab)?cd/', null],
            // An alternative that is itself indeterminate -> whole thing null.
            'alternation with wildcard branch' => ['/(foo|.bar)/', null],
            'alternation with anchor branch' => ['/(foo|^bar)/', null],

            // Positive char class.
            'char class' => ['/[abc]x/', ['a' => true, 'b' => true, 'c' => true]],
            'char class single' => ['/[h]ttp/', ['h' => true]],

            // Negated class / shorthand inside -> null.
            'negated class' => ['/[^abc]/', null],
            'class with shorthand' => ['/[\d]/', null],
            'optional class' => ['/[abc]?x/', null],

            // Enumerable ASCII ranges are expanded to their member bytes (still a
            // complete superset). bare-email autolink starts `[a-zA-Z0-9._%+-]`.
            'range class digits' => ['/[0-2]x/', ['0' => true, '1' => true, '2' => true]],
            'range mixed with literals' => [
                '/[a-c._]x/',
                ['a' => true, 'b' => true, 'c' => true, '.' => true, '_' => true],
            ],
            'malformed range descending' => ['/[z-a]/', null],
            // POSIX / equivalence / collating constructs inside a class are far
            // broader than their literal spelling -> null.
            'posix class' => ['/[[:alpha:]]+/', null],
            'nested-bracket class' => ['/[a[b]/', null],

            // Case-insensitive flag: include both cases of each letter.
            'case-insensitive literal' => ['/http/i', ['h' => true, 'H' => true]],
            'case-insensitive alternation' => [
                '/(http|ftp)/i',
                ['h' => true, 'H' => true, 'f' => true, 'F' => true],
            ],
            'case-insensitive escaped non-letter unchanged' => ['/\[x/i', ['[' => true]],
            'case-insensitive class' => ['/[ab]/i', ['a' => true, 'A' => true, 'b' => true, 'B' => true]],

            // Extended/free-spacing mode (`x`) makes leading whitespace
            // insignificant -> the first body byte is not the trigger -> null.
            'extended flag' => ['/( foo | bar )/x', null],
            'extended flag plain' => ['/foo/x', null],
            'combined flags with x' => ['/foo/ix', null],

            // Unicode case-insensitive (`iu`) can fold to multibyte chars (e.g.
            // `k` matches Kelvin sign U+212A) -> ASCII expansion is incomplete ->
            // null. `u` alone or `i` alone are fine.
            'unicode case-insensitive' => ['/[k]/iu', null],
            'unicode flag only' => ['/foo/u', ['f' => true]],
            'unicode ci literal' => ['/foo/iu', null],

            // Top-level (ungrouped) alternation: union every branch's first
            // bytes (not just the first branch).
            'top-level alternation' => ['/foo|bar/', ['f' => true, 'b' => true]],
            'top-level alternation three' => ['/a|b|c/', ['a' => true, 'b' => true, 'c' => true]],
            'top-level alt escaped branch' => ['/\[x|foo/', ['[' => true, 'f' => true]],
            'top-level alt indeterminate branch' => ['/foo|.bar/', null],
            'empty alternative' => ['/foo|/', null],

            // Zero-min counted quantifier makes the leading token optional -> the
            // following token could also start the match -> null.
            'optional counted literal' => ['/a{0,1}bc/', null],
            'optional counted zero' => ['/x{0}y/', null],
            'optional counted comma-min' => ['/a{,2}bc/', null],
            'optional counted group' => ['/(ab){0,1}cd/', null],
            // Non-zero minimum keeps the token mandatory.
            'mandatory counted literal' => ['/a{2,3}bc/', ['a' => true]],
            'mandatory counted exact' => ['/a{3}b/', ['a' => true]],
            // A `{` that is not a quantifier stays a literal trigger.
            'brace literal not quantifier' => ['/a{b/', ['a' => true]],

            // Indeterminate leads -> null (correctness: run everywhere).
            'anchor caret' => ['/^foo/', null],
            'wildcard dot' => ['/.foo/', null],
            'anchored A' => ['/\Afoo/', null],
            'lookahead' => ['/(?=foo)x/', null],
            'negative lookahead' => ['/(?!foo)x/', null],
            'optional literal' => ['/a?b/', null],
            'star literal' => ['/a*b/', null],
            'alternation metachar' => ['/(|x)/', null],

            // Lookbehind is skipped; trigger is what follows (mentions: @ ).
            'lookbehind then literal' => ['/(?<![A-Za-z0-9_])@(\w+)/', ['@' => true]],
            'lookbehind then escaped' => ['/(?<=x)\[y/', ['[' => true]],

            // Multibyte / non-ASCII first byte -> null.
            'multibyte literal' => ['/\x{00e9}/u', null],

            // Degenerate inputs.
            'null pattern' => [null, null],
            'too short' => ['/', null],
        ];
    }
}
