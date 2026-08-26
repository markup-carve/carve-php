<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class DefinitionBodyCanonicalSpaceTest extends TestCase
{
    public function testOneSpaceIsAcceptedAndCanonical(): void
    {
        self::assertSame(":: x\n: y\n", CarveConverter::toCarve(":: x\n: y\n"));
    }

    public function testWiderSeparatorIsNarrowedWithItsBody(): void
    {
        $source = ":: x\n:  y\n\n   > q\n";
        $canonical = ":: x\n: y\n\n  > q\n";

        self::assertSame($canonical, CarveConverter::toCarve($source));
        $converter = CarveConverter::create();
        self::assertSame($converter->convert($source), $converter->convert($canonical));
    }
}
