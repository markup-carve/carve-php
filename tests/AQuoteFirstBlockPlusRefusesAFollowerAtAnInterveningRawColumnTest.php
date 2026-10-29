<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#4238: a quote's first-block `+` reads its follower at the raw column, before
 * rule A moves the line. Off column 0, the opener line's column and the owning quote's
 * column it is refused and reads like a letter (CARVE-P9-067, carve#3421).
 */
class AQuoteFirstBlockPlusRefusesAFollowerAtAnInterveningRawColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'heading at column 1' => ["> +\n # h\n", '<blockquote><p>+</p></blockquote><section id="h"><h1>h</h1></section>'],
            'table at column 1' => ["> +\n | a |\n", '<blockquote><p>+</p></blockquote><table><tbody><tr><td>a</td></tr></tbody></table>'],
            'quoted follower at column 1' => ["> > +\n > q\n", "<blockquote><blockquote><p>+\nq</p></blockquote></blockquote>"],
            'item-hosted heading at column 3' => ["- > +\n   # h\n", '<ul><li><blockquote><p>+</p></blockquote><h1 id="h">h</h1></li></ul>'],
        ];
    }

    #[DataProvider('documents')]
    public function testReadsTheDocumentAsTheModelDoes(string $source, string $html): void
    {
        $this->assertSame($html, (string)preg_replace('/>\s+</', '><', trim((new CarveConverter())->convert($source))));
    }

    public function testReadsEveryRefusedFollowerLikeALetter(): void
    {
        $converter = new CarveConverter();
        $hosts = [
            [[], '', false],
            [['- a'], '  ', false],
            [['- a', '  - b'], '    ', false],
            [['1. a'], '   ', false],
            [[], '- ', true],
            [[], '- - ', true],
        ];
        $count = 0;
        foreach ($hosts as [$pre, $lead, $marker]) {
            foreach (['> +', '> > +', '> > > +'] as $stack) {
                $opener = $lead . $stack;
                $eligible = [0, $marker ? strlen($lead) - strlen(ltrim($lead)) : strlen($lead), (int)strrpos($opener, '>')];
                for ($col = 0; $col <= 8; $col++) {
                    if (in_array($col, $eligible, true)) {
                        continue;
                    }
                    foreach ([0, 1, 2] as $depth) {
                        foreach (['q', '# h', '| a |', '***', '::: note', '{.c}'] as $block) {
                            $follower = str_repeat(' ', $col) . str_repeat('> ', $depth) . $block;
                            $doc = static fn (string $end): string => implode("\n", [...$pre, substr($opener, 0, -1) . $end, $follower]) . "\n";
                            $letter = $converter->convert($doc('z'));
                            $this->assertSame(substr_replace($letter, '+', (int)strpos($letter, 'z'), 1), $converter->convert($doc('+')), $doc('+'));
                            $count++;
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan(2000, $count);
    }
}
