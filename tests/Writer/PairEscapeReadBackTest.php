<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-146: a format whose only escapes are pair escapes takes no bare read-back (carve-php#5904); the forms are those
 * the bare read-back gave.
 */
final class PairEscapeReadBackTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'prose paragraph under a heading' => [
                "## Section 0\n\nalpha with *strong*, _emphasis_, `code` and a [link](https://example.com/0).\n",
                "== Section 0\n\nalpha with *strong*, \\_emphasis_, `code` and a [link](https://example.com/0).\n",
            ],
            'strike and highlight pairs' => ["a ~s~ and =x= then _u_ here\n", "a \\~s~ and \\=x= then \\_u_ here\n"],
            'pairs in two paragraphs' => ["first _a_ para\n\nsecond ~b~ para =c=\n", "first \\_a_ para\n\nsecond \\~b~ para \\=c=\n"],
            'pair in a list item and a quote' => ["- item _a_ x\n\n> quote =b= y\n", "- item \\_a_ x\n\n> quote \\=b= y\n"],
            'pair beside a braced underline' => ["a {_u_} and _v_ b\n", "a {_u_} and \\_v_ b\n"],
            'pair after a brace' => ["a {_v_ b\n", "a {\\_v_ b\n"],
            'pair across a soft break' => ["a _b\nc_ d\n", "a \\_b\nc_ d\n"],
            'pair with a double star' => ["a **b** c _d_\n", "a \\*\\*b** c \\_d_\n"],
            'pair with a reference that needs a round' => [
                "\\_x_ gamma|, --- \"q\", [link](https://e.com/1), epsilon {/it/ \\[^n] {^s^}\n",
                "\\_x_ gamma|, --- \"q\", [link](https://e.com/1), epsilon {/it/ \\[^n] {^s^}\n",
            ],
            'pair in a table cell' => ["|= h |\n| a _b_ c |\n", "|= h |\n| a \\_b_ c |\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testFormatKeepsTheForm(string $source, string $expected): void
    {
        $out = Carve::format($source);

        $this->assertSame($expected, $out);
        $this->assertSame(Carve::render($source), Carve::render($out));
        $this->assertSame($out, Carve::format($out));
    }
}
