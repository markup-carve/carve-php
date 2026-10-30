<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A delimiter that closes an open span of its own kind opens nothing, so it
 * cannot pair with a later delimiter whose attribute block would hide the
 * closer of an enclosing span.
 */
class TheCloserOfAnOpenSpanOpensNoAttributedPairTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $strongEm = '<strong><em>a</em></strong>';

        return [
            'an empty class' => ['_x /*a*/{.} b/{c_} c_', "<p><u>x $strongEm{.} b/{c</u>} c_</p>"],
            'no closer after' => ['_x /*a*/{.} b/{c_}', "<p><u>x $strongEm{.} b/{c</u>}</p>"],
            'an open brace' => ['_x /*a*/{ b/{c_}', "<p><u>x $strongEm{ b/{c</u>}</p>"],
            'an unclosed class' => ['_x /*a*/{.q b/{c_} c_', "<p><u>x $strongEm{.q b/{c</u>} c_</p>"],
            'a valid block first' => ['_x /*a*/{*b*}{.q} b/{c_} c_', "<p><u>x $strongEm<strong class=\"q\">b</strong> b/{c</u>} c_</p>"],
            'an emphasis host' => ['*x /a/{.} b/{c*} c*', '<p><strong>x <em>a</em>{.} b/{c</strong>} c*</p>'],
        ];
    }

    #[DataProvider('cases')]
    public function testTheEnclosingSpanClosesInsideTheBlock(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }
}
