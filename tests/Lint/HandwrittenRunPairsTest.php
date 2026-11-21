<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Lint\Linter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Handwritten lint reads pairs on the text runs of every inline host and paragraph lines behind their container
 * prefixes, as data mode and carve-js do (carve-php#5558). Expected findings are the carve-js output.
 */
final class HandwrittenRunPairsTest extends TestCase
{
    /**
     * @return array<string, array{string, array<array{string, string, int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'attribute-shaped line behind a quote mark' => [
                "> ==h [l](u)\n> {x} __u\n",
                [['block-marker-as-text', '/children/0/children/0', 15, 22]],
            ],
            'later quote line' => [
                "> a\n> {x} b\n",
                [['block-marker-as-text', '/children/0/children/0', 6, 11]],
            ],
            'strong pair across the lines of a term' => [
                ":: d\n  **a\n       i== b**\n",
                [
                    ['markdown-strong-double-star', '/children/0/children/0', 7, 25],
                    ['description-term-multi-line', '/children/0/children/0', 7, 10],
                ],
            ],
            'strong pair across the lines of a link' => [
                "[a **b\nc** d](u)\n",
                [['markdown-strong-double-star', '/children/0/children/0', 3, 10]],
            ],
            'run pairing takes the opener from the line above' => [
                "b c **a\n  {#i} **x**\n",
                [['block-marker-as-text', '/children/0', 10, 20]],
            ],
            'literal quote marks on a later line of a list paragraph' => [
                "- ~~s_e_\n> > {a=}\n",
                [
                    ['block-opener-without-blank-line', '/children/0/children/0/children/0', 9, 10],
                    ['block-marker-as-text', '/children/0/children/0/children/0', 13, 17],
                ],
            ],
            'underline span on a later line of a term' => [
                ":: t\n  {_u_} b\n",
                [
                    ['description-term-multi-line', '/children/0/children/0', 7, 14],
                    ['block-marker-as-text', '/children/0/children/0', 7, 14],
                ],
            ],
            'underline span on a later line of a paragraph' => [
                "a\n{_u_} b\n",
                [],
            ],
            'underscore pair after a letter that ends the line above' => [
                "b c\n_e_ x\n",
                [['deprecated-bare-underline', '/children/0', 4, 7]],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<array{string, string, int, int}> $expected
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testFindings(string $source, array $expected): void
    {
        $found = [];
        foreach (Linter::lint($source) as $diagnostic) {
            $data = $diagnostic->toArray();
            $found[] = [$data['code'], $data['path'], $data['range']['startOffset'], $data['range']['endOffset']];
        }

        $this->assertSame($expected, $found);
    }
}
