<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5663: every smart punctuation node held its own field array, so a 105 KB run of `<!---->` took 103 MB
 * to format. Nodes of one short spelling now share one array.
 */
final class SmartPunctuationFieldsTest extends TestCase
{
    public function testSharedFieldsStayPerNode(): void
    {
        $doc = Carve::parse("a -- b -- c\n");
        $nodes = $doc->children[0]->children ?? [];
        $first = $nodes[1];
        $second = $nodes[3];
        self::assertSame(['kind' => 'en_dash', 'glyph' => '–', 'source' => '--'], $first->f);
        self::assertSame($first->f, $second->f);

        $first->f['glyph'] = '-';
        self::assertSame('–', $second->f['glyph']);
        self::assertSame('–', Carve::parse("x -- y\n")->children[0]->children[1]->f['glyph'] ?? null);
    }

    public function testARunOfDashesTakesNoArrayPerNode(): void
    {
        $source = str_repeat('a -- ', 10000);
        $before = memory_get_usage();
        $doc = Carve::parse($source);

        // 15.7 MB before the fix, 11.5 MB after.
        self::assertLessThan(13 * 1024 * 1024, memory_get_usage() - $before);
        self::assertCount(20000, $doc->children[0]->children ?? []);
    }
}
