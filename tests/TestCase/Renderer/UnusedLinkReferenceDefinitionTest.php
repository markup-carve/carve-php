<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class UnusedLinkReferenceDefinitionTest extends TestCase
{
    public function testUnusedDefinitionRemainsVisibleOnTextTargets(): void
    {
        $source = "[Text][REF]\n\n[ref]: /u\n";

        self::assertSame("[Text\\][REF\\]\n\n[ref]: /u\n", CarveConverter::markdown()->convert($source));
        self::assertSame("[Text][REF]\n\n[ref]: /u\n", CarveConverter::plainText()->convert($source));
        self::assertSame("[Text][REF]\n\n\e[2m[ref]: /u\e[0m\n", CarveConverter::ansi()->convert($source));
    }

    public function testReferencedDefinitionRemainsSuppressed(): void
    {
        $source = "[t][r]\n\n[r]: /u\n";

        self::assertSame("[t](/u)\n", CarveConverter::markdown()->convert($source));
        self::assertSame("t\n", CarveConverter::plainText()->convert($source));
        self::assertStringNotContainsString('[r]:', CarveConverter::ansi()->convert($source));
    }

    public function testNormalizedReferenceLabelSuppressesTheDefinition(): void
    {
        $source = "See [x][foo  bar].\n\n[foo bar]: /url\n";

        self::assertSame("See [x](/url).\n", CarveConverter::markdown()->convert($source));
        self::assertSame("See x.\n", CarveConverter::plainText()->convert($source));
        self::assertStringNotContainsString('[foo bar]:', CarveConverter::ansi()->convert($source));
    }
}
