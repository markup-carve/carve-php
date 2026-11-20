<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The maintainer rulings of carve#6191 to #6196 (20 November).
 */
final class LintRulings20NovemberTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<array{int, int}>}>
     */
    public static function cases(): array
    {
        return [
            '6191 text line' => [">a  \n", 'blockquote-marker-without-space', [[0, 2]]],
            '6191 frontmatter line' => ["---\ntitle: x\n>a  \n---\n", 'blockquote-marker-without-space', [[13, 15]]],
            '6192 div opener' => ["# H\n\n   ::::\n   x\n   ::::\n", 'block-opener-overindented', [[8, 12]]],
            '6192 heading' => ["   ### H\n", 'block-opener-overindented', [[3, 6]]],
            '6192 code fence' => ["   ````\n   x\n   ````\n", 'block-opener-overindented', [[3, 7]]],
            '6192 thematic break' => ["   ***\n", 'block-opener-overindented', [[3, 6]]],
            '6192 quote mark' => ["   > q\n", 'block-opener-overindented', [[3, 4]]],
            '6192 table row' => ["   | a |\n", 'block-opener-overindented', [[3, 4]]],
            '6192 attributed div opener' => ["   {.x}\n   :::: foo\n   x\n   ::::\n", 'block-opener-overindented', [[11, 15]]],
            '6191 trailing no-break space stays' => [">a\u{a0}\n", 'blockquote-marker-without-space', [[0, 4]]],
            '6193 include line' => ["- a\n\n      {{ x.crv }}\n", 'list-item-block-overindented', []],
            '6194 trailing spaces' => [":::: x\n:::::  \n", 'colon-fence-length-mismatch', [[7, 12]]],
            '6194 quote inside a div' => ["::::\n> :::\n", 'colon-fence-length-mismatch', []],
            '6194 item inside a div' => ["::::\n- a\n\n  :::  \n", 'colon-fence-length-mismatch', []],
            '6195 paragraph line' => ["para\n{.c}\n", 'unattached-block-attribute', []],
            '6195 blank-line warning' => ["para\n{.c}\n", 'block-opener-without-blank-line', [[5, 9]]],
            '6196 quote line is blank' => ["> {.c}\n>\n> # H\n", 'unattached-block-attribute', [[2, 6]]],
            '6196 >> at column 1 is text' => ["{.c}\n>>\n", 'unattached-block-attribute', []],
            '6197 spaced attribute line' => ["para\n{ .c }\n", 'block-opener-without-blank-line', [[5, 11]]],
            '6197 id attribute line' => ["para\n{#id}\n", 'block-opener-without-blank-line', [[5, 10]]],
            '6197 spaced id alone' => ["{ #id }\n", 'unattached-block-attribute', [[0, 7]]],
        ];
    }

    /**
     * @param string $source
     * @param string $code
     * @param array<array{int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testRuling(string $source, string $code, array $expected): void
    {
        $found = [];
        foreach (Carve::lint($source) as $d) {
            if ($d->code === $code) {
                $found[] = [$d->startOffset, $d->endOffset];
            }
        }
        $this->assertSame($expected, $found);
    }

    public function testALineOutsideTheQuoteEndsIt(): void
    {
        $messages = [];
        foreach (Carve::lint("> > {.c}\n>\n") as $d) {
            if ($d->code === 'unattached-block-attribute') {
                $messages[] = $d->message;
            }
        }
        $this->assertCount(1, $messages);
        $this->assertStringStartsWith('This attribute line reaches no block', $messages[0]);
    }

    public function testQuoteLineIsBlankForTheAttributeLine(): void
    {
        $messages = [];
        foreach (Carve::lint("> {.c}\n>\n> # H\n") as $d) {
            if ($d->code === 'unattached-block-attribute') {
                $messages[] = $d->message;
            }
        }
        $this->assertCount(1, $messages);
        $this->assertStringStartsWith('A blank line follows this attribute line', $messages[0]);
    }
}
