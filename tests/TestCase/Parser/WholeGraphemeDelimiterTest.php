<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class WholeGraphemeDelimiterTest extends TestCase
{
    public function testDecoratedDelimitersStayText(): void
    {
        $converter = new CarveConverter();
        foreach ([
            '*️⃣abc*' => '<p>*️⃣abc*</p>',
            '*a*️⃣' => '<p>*a*️⃣</p>',
            '*a*́ b*' => '<p><strong>a*́ b</strong></p>',
            '_︎x _y_' => '<p>_︎x <u>y</u></p>',
            '~a~‍b~' => '<p><s>a~‍b</s></p>',
            '*x* *️⃣ *y*' => '<p><strong>x</strong> *️⃣ <strong>y</strong></p>',
            '{*️⃣abc*}' => '<p>{*️⃣abc*}</p>',
            '/*️a*/' => '<p><em>*️a*</em></p>',
        ] as $source => $expected) {
            self::assertSame($expected . "\n", $converter->convert($source), $source);
        }
    }
}
