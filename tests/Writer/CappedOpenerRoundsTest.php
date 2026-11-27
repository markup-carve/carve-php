<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#6052: past the inline depth cap (C2-I-118) the rounds of the reported openers read what their comparison
 * reaches. On this shape at 12k that is 3 opener rounds instead of about 100; the output stays the input.
 */
final class CappedOpenerRoundsTest extends TestCase
{
    public function testTheShapePastTheCapIsWrittenAsBefore(): void
    {
        $input = str_repeat("[a *[a][]*[x]: /u\n", 722);

        self::assertSame($input, Writer::format($input));
    }
}
