<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DjotReferenceLayoutTest extends TestCase
{
    public function testLongInheritedAttributesStayShared(): void
    {
        $value = str_repeat('v', 32768);
        foreach (['title', 'class', 'data-long'] as $attribute) {
            $source = '{' . $attribute . '="' . $value . '"}' . "\n[r]: /u\n\n" . str_repeat('[x][r] ', 1024);
            $result = (new DjotToCarve())->convert($source);
            self::assertLessThan(strlen($source) * 2, strlen($result));
            self::assertSame(1, substr_count($result, $value));
        }
    }

    public function testClassOverridesKeepOtherInheritedAttributesShared(): void
    {
        $value = str_repeat('v', 32768);
        $source = '{.base title="' . $value . '"}' . "\n[r]: /u\n\n[x][r] " . str_repeat('[x][r]{.own} ', 1024);
        $result = (new DjotToCarve())->convert($source);
        self::assertLessThan(strlen($source) * 3, strlen($result));
        self::assertLessThanOrEqual(2, substr_count($result, $value));
        $small = "{.base title=\"a|b\"}\n[r]: /u\n\n| [x][r] | [x][r]{.own} |\n";
        $expected = "| [x](/u){class=base title=\"a\\|b\"} | [x](/u){title=\"a\\|b\" class=own} |\n";
        self::assertSame((new CarveConverter())->convert($expected), (new CarveConverter())->convert((new DjotToCarve())->convert($small)));
    }

    public function testMalformedLabelKeepsLaterReferenceDefaults(): void
    {
        $source = "m[i][j\nthen [x][r] and [y][r]{title=own}\n\n{title=base}\n[r]: /u\n";
        $converter = new DjotToCarve();
        $method = new ReflectionMethod($converter, 'normalizeDjotReferenceUses');
        $normalized = $method->invoke($converter, $source);
        self::assertStringContainsString('[r]: /u {title=base}', $normalized);
        self::assertStringContainsString('[y][r]{title=own}', $normalized);
    }

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
            self::assertSame((new CarveConverter())->convert($expected), (new CarveConverter())->convert($method->invoke($converter, $source)));
        }
    }

    public function testFormattedReferenceAfterUnfinishedLabels(): void
    {
        $prefix = str_repeat('[a ', 8192);
        $converter = new DjotToCarve();
        $method = new ReflectionMethod($converter, 'normalizeDjotReferenceUses');
        self::assertSame($prefix . "[_link_][link]\n\n[link]: /u", $method->invoke($converter, $prefix . "[_link_][]\n\n[link]: /u"));
    }

    public function testDefinitionAttributeOrder(): void
    {
        $converter = new DjotToCarve();
        $method = new ReflectionMethod($converter, 'normalizeDjotReferenceUses');
        self::assertSame((new CarveConverter())->convert('[a](/u){x=last y=middle}'), (new CarveConverter())->convert($method->invoke($converter, "[a][r]\n\n{x=first}\n{y=middle x=last}\n[r]: /u")));
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
        self::assertSame((new CarveConverter())->convert(implode(' ', array_fill(0, 1024, '[a](/u){class=c}'))), (new CarveConverter())->convert($method->invoke($converter, implode(' ', $uses) . "\n\n" . implode("\n", $definitions))));
    }

    public function testConsecutiveReferenceAttributeBlocks(): void
    {
        $converter = new DjotToCarve();
        $method = new ReflectionMethod($converter, 'normalizeDjotReferenceUses');
        foreach (
            [
                ["[a][r]{title=first}{title=last}\n\n{.base}\n[r]: /u", '[a](/u){title=last class=base}'],
                ["[a][r]{.a}{.b}\n\n{title=base}\n[r]: /u", '[a](/u){class="a b" title=base}'],
                ["[a][r]{.a title=x}{.b title=y}\n\n{.base title=z}\n[r]: /u", '[a](/u){class="a b" title=y}'],
                ["[a][r]\n\n{.a}\n{.b}\n[r]: /u", '[a](/u){class="a b"}'],
            ] as [$source, $expected]
        ) {
            self::assertSame((new CarveConverter())->convert($expected), (new CarveConverter())->convert($method->invoke($converter, $source)));
        }
    }

    public function testEscapedReferenceNamesKeepSharedDestinations(): void
    {
        $target = '/' . str_repeat('x', 32768);
        foreach (['escaped', 'styled', 'attributes'] as $shape) {
            $source = $shape === 'escaped' ? '[a\\*b]: ' . $target . "\n\n" . str_repeat('[link][a\\\\*b] ', 1024)
                : ($shape === 'attributes' ? "{.target}\n" : '') . '[link]: ' . $target . "\n\n" . str_repeat('[_link_][] ', 1024);
            $result = (new DjotToCarve())->convertWithFidelityReport($source);
            self::assertSame(1, substr_count($result->value, $target));
            self::assertLessThan(strlen($source) * 2, strlen($result->value));
            self::assertSame([], array_values(array_filter($result->report()['diagnostics'], static fn (array $row): bool => str_contains($row['message'], 'unresolved Djot reference'))));
        }
    }

    public function testEscapedReferenceNamesDistinguishUnresolvedUses(): void
    {
        foreach (['*', '^', ' '] as $marker) {
            $name = 'a\\' . $marker . 'b';
            foreach (['', "{.t}\n"] as $attrs) {
                $source = $attrs . '[' . $name . "]: /x\n\n[link][" . $name . "]\n";
                $result = (new DjotToCarve())->convertWithFidelityReport($source);
                $losses = array_values(array_filter($result->report()['diagnostics'], static fn (array $row): bool => str_contains($row['message'], 'unresolved Djot reference')));
                self::assertCount(1, $losses);
                self::assertStringNotContainsString('href="/x"', (new CarveConverter())->convert($result->value));
            }
        }
    }

    public function testCaretReferenceNamesDoNotReportFalseLosses(): void
    {
        foreach (['a^b', 'a^^b', 'a ^b', 'a^ b'] as $name) {
            $source = "{.target}\n[" . $name . "]: /x\n\n[link][" . $name . "]\n";
            $result = (new DjotToCarve())->convertWithFidelityReport($source);
            self::assertSame([], array_values(array_filter($result->report()['diagnostics'], static fn (array $row): bool => str_contains($row['message'], 'unresolved Djot reference'))));
        }
    }

    public function testNonbreakingLabelWhitespaceDoesNotResolveSpaceDefinition(): void
    {
        foreach (['a b', "a\u{00a0}b"] as $label) {
            $result = (new DjotToCarve())->convertWithFidelityReport('[' . $label . "]: /x\n\n[a\\ b][]\n");
            self::assertCount(1, array_filter($result->report()['diagnostics'], static fn (array $row): bool => str_contains($row['message'], 'unresolved Djot reference')));
        }
    }

    public function testPipesInsideNestedAttributesRemainCellBoundaries(): void
    {
        $source = "{.c}\n[x]: /u\n\n| h |\n|---|\n| [a{k=\"a|b\"}][x] |\n";
        $method = new ReflectionMethod(DjotToCarve::class, 'normalizeDjotReferenceUses');
        $normalized = $method->invoke(new DjotToCarve(), $source);
        self::assertStringContainsString('[a{k="a|b"}][x]', $normalized);
        self::assertStringNotContainsString('[x]{class=c}', $normalized);
    }
}
