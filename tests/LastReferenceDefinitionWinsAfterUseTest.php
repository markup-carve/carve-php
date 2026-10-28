<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LastReferenceDefinitionWinsAfterUseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'collapsed link' => ["[a]: x\n[a][]\n[a]: b\n", '<a href="b">a</a>'];
        yield 'full link' => ["[a]: x\n[text][a]\n[a]: b\n", '<a href="b">text</a>'];
        yield 'image' => ["[a]: x\n![alt][a]\n[a]: b\n", '<img src="b" alt="alt">'];
        yield 'later attributes' => ["[a]: x {.old}\n[a][]\n[a]: b {.new}\n", 'class="new"'];
        yield 'list host' => ["[a]: x\n- [a][]\n[a]: b\n", '<a href="b">a</a>'];
    }

    #[DataProvider('cases')]
    public function testTheLastDefinitionResolvesEveryEarlierUse(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString($expected, $html);
        self::assertStringNotContainsString('class="old"', $html);
    }
}
