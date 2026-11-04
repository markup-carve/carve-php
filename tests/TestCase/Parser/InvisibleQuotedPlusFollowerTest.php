<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvisibleQuotedPlusFollowerTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{0: string, 1?: string}>
     */
    public static function invisibleFollowers(): array
    {
        return [
            // carve#5129: the erased line goes with its `>`, so `+` meets `b` in the inner quote.
            'line comment' => ['%% c'],
            'fenced comment' => ["%%% c\n > hidden\n > %%%"],
            'reference definition' => ['[r]: /u'],
        ];
    }

    #[DataProvider('invisibleFollowers')]
    public function testAcceptedPlusKeepsTheVisibleFollowerInItsNestedQuote(string $invisible, ?string $erased = null): void
    {
        $source = "> > a\n > +\n > $invisible\n > b\n";
        $expected = $erased ?? <<<'HTML'
<blockquote>
  <blockquote>
    <p>a</p>
    <p>b</p>
  </blockquote>
</blockquote>
HTML;

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
