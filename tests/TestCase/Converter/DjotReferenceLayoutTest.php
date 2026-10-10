<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DjotReferenceLayoutTest extends TestCase
{
    public function testReferenceLabelBoundaries(): void
    {
        $converter = new DjotToCarve();
        $method = new ReflectionMethod($converter, 'normalizeDjotReferenceUses');
        foreach (
            [
                ["[a [b]][r]\n\n{.c}\n[r]: /u", '[a [b]](/u){class=c}'],
                ["\\[a][r] [b][r]\n\n{.c}\n[r]: /u", '\\[a][r] [b](/u){class=c}'],
                ["[a `[` b][r]\n\n{.c}\n[r]: /u", '[a `[` b](/u){class=c}'],
                ["[a][r\\]x] [b][r]\n\n{.c}\n[r]: /u", '[a][r\\]x] [b](/u){class=c}'],
                ["[_link_][]\n\n[link]: /u", '[_link_](/u)'],
            ] as [$source, $expected]
        ) {
            self::assertSame($expected, $method->invoke($converter, $source));
        }
    }

    public function testFormattedReferenceAfterUnfinishedLabels(): void
    {
        $prefix = str_repeat('[a ', 8192);
        $converter = new DjotToCarve();
        $method = new ReflectionMethod($converter, 'normalizeDjotReferenceUses');
        self::assertSame($prefix . '[_link_](/u)', $method->invoke($converter, $prefix . "[_link_][]\n\n[link]: /u"));
    }

    public function testDefinitionAttributeOrder(): void
    {
        $converter = new DjotToCarve();
        $method = new ReflectionMethod($converter, 'normalizeDjotReferenceUses');
        self::assertSame('[a](/u){x=last y=middle}', $method->invoke($converter, "[a][r]\n\n{x=first}\n{y=middle x=last}\n[r]: /u"));
    }

    public function testManyInlinedDefinitions(): void
    {
        $uses = [];
        $definitions = [];
        for ($n = 0; $n < 1024; $n++) {
            $uses[] = '[a][r' . $n . ']';
            $definitions[] = "{.c}\n[r" . $n . ']: /u';
        }
        $converter = new DjotToCarve();
        $method = new ReflectionMethod($converter, 'normalizeDjotReferenceUses');
        self::assertSame(implode(' ', array_fill(0, 1024, '[a](/u){class=c}')), $method->invoke($converter, implode(' ', $uses) . "\n\n" . implode("\n", $definitions)));
    }

    public function testConsecutiveReferenceAttributeBlocks(): void
    {
        $converter = new DjotToCarve();
        $method = new ReflectionMethod($converter, 'normalizeDjotReferenceUses');
        foreach (
            [
                ["[a][r]{title=first}{title=last}\n\n{.base}\n[r]: /u", '[a](/u){class=base title=last}'],
                ["[a][r]{.a}{.b}\n\n{title=base}\n[r]: /u", '[a](/u){title=base class="a b"}'],
                ["[a][r]{.a title=x}{.b title=y}\n\n{.base title=z}\n[r]: /u", '[a](/u){class="a b" title=y}'],
                ["[a][r]\n\n{.a}\n{.b}\n[r]: /u", '[a](/u){class="a b"}'],
            ] as [$source, $expected]
        ) {
            self::assertSame($expected, $method->invoke($converter, $source));
        }
    }
}
