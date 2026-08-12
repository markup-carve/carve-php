<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An escaped brace is a literal `{` and opens nothing, so the delimiter after
 * it is an ordinary bare pair (markup-carve/carve-php#1191).
 *
 * `escaped_char` in `resources/grammar.ebnf` is one backslash and ONE
 * punctuation character; nothing in it suppresses the constructs that follow
 * the character it escapes. This engine skipped any closer followed by `}` on
 * the theory that such a closer belongs to a braced opener - true only when a
 * braced opener EXISTS - so `\{/x/}` rendered as literal text here while
 * carve-js and carve-rs rendered the emphasis.
 */
class EscapedBraceDoesNotSuppressTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function escapedBraceProvider(): array
    {
        return [
            'emphasis' => ['a \{/x/} b', '<p>a {<em>x</em>} b</p>'],
            'highlight' => ['a \{=y=} b', '<p>a {<mark>y</mark>} b</p>'],
            'strong' => ['a \{*y*} b', '<p>a {<strong>y</strong>} b</p>'],
            'underline' => ['a \{_y_} b', '<p>a {<u>y</u>} b</p>'],
            'strike' => ['a \{~y~} b', '<p>a {<s>y</s>} b</p>'],
        ];
    }

    #[DataProvider('escapedBraceProvider')]
    public function testTheDelimiterAfterAnEscapedBraceStillPairs(string $source, string $expected): void
    {
        $this->assertSame($expected, trim($this->converter->convert($source)));
    }

    /**
     * The other half of the same rule: an UNESCAPED brace does open a braced
     * construct, and it still owns the whole run. A fix that simply stopped
     * skipping `X}` closers would break these, so they are the proof that the
     * check is conditional rather than removed.
     */
    #[DataProvider('bracedProvider')]
    public function testAnUnescapedBraceStillOwnsItsConstruct(string $source, string $expected): void
    {
        $this->assertSame($expected, trim($this->converter->convert($source)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bracedProvider(): array
    {
        return [
            'emphasis' => ['a {/x/} b', '<p>a <em>x</em> b</p>'],
            'highlight' => ['a {=y=} b', '<p>a <mark>y</mark> b</p>'],
            'strong' => ['a {*y*} b', '<p>a <strong>y</strong> b</p>'],
        ];
    }

    /**
     * An EVEN run of backslashes is a literal backslash and the brace is real,
     * so the braced construct opens and the brace is consumed.
     */
    public function testAnEvenBackslashRunLeavesTheBraceReal(): void
    {
        $this->assertSame('<p>a \<em>x</em> b</p>', trim($this->converter->convert('a \\\\{/x/} b')));
    }

    /**
     * BOUND, not proof: with a space after the escaped brace there was never a
     * `X}` closer to skip, so this row agreed with the other engines before the
     * change and is unmoved by it. It is here so a fix cannot pass by making
     * every delimiter after a brace pair unconditionally.
     */
    public function testASpacedEscapedBraceWasAlreadyCorrect(): void
    {
        $this->assertSame('<p>a { <em>x</em> b</p>', trim($this->converter->convert('a \{ /x/ b')));
    }
}
