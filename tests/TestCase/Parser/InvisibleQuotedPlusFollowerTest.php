<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvisibleQuotedPlusFollowerTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function invisibleFollowers(): array
    {
        return [
            'line comment' => ['%% c'],
            'fenced comment' => ["%%% c\n > hidden\n > %%%"],
            'reference definition' => ['[r]: /u'],
        ];
    }

    #[DataProvider('invisibleFollowers')]
    public function testAcceptedPlusKeepsTheVisibleFollowerInItsNestedQuote(string $invisible): void
    {
        $source = "> > a\n > +\n > $invisible\n > b\n";
        $expected = <<<'HTML'
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
