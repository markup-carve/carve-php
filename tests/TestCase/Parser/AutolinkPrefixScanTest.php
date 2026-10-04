<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\InlineParser;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class AutolinkPrefixScanTest extends TestCase
{
    use ScalingGuardTrait;

    public function testMalformedPrefixesLeaveTheFinalAutolinkAvailable(): void
    {
        $converter = CarveConverter::create();
        foreach (['<a:x ', '<a:x<', '<word '] as $prefix) {
            $html = $converter->convert(str_repeat($prefix, 512) . '<https://example.com>{.last}');
            self::assertSame(1, substr_count($html, '<a '));
            self::assertStringContainsString('class="last"', $html);
            self::assertStringContainsString('href="https://example.com"', $html);
        }
    }

    public function testLookaheadKeepsFinalNewlineAndUnicodeBehavior(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            public function autolinkEnd(string $text): ?int
            {
                return $this->findAutolinkEnd($text, 0);
            }
        };
        foreach (["<https://example.com\n>", "<a@example.com\n>", '<https://example.com/é>', "<https://example.com/\xFF>"] as $source) {
            self::assertSame(strlen($source), $parser->autolinkEnd($source));
        }
        foreach (["<https://example.com\t>", "<https://example.com\r\n>", "<https://example.com\n x>", '<https://example.com/<x>'] as $source) {
            self::assertNull($parser->autolinkEnd($source));
        }
        self::assertNull($parser->autolinkEnd(str_repeat('<a:x ', 512) . '>'));
        self::assertSame(5, $parser->autolinkEnd('<a:x><tail>>>'));
    }

    public function testLongDollarRunRetainsDisplayMath(): void
    {
        $converter = CarveConverter::create();
        $html = $converter->convert(str_repeat('$', 8192) . '`x`');
        self::assertStringContainsString(str_repeat('$', 8190), $html);
        self::assertStringContainsString('x', $html);
        self::assertSame($converter->convert('$$`x`'), substr($html, 0, 3) . substr($html, 8193));
    }

    public function testFailedCombinedScansVisitTheCodeTailOnce(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            public int $codeProbes = 0;

            protected function findCodeSpanEnd(string $text, int $pos): ?int
            {
                $this->codeProbes++;

                return parent::findCodeSpanEnd($text, $pos);
            }
        };
        $parser->parse(new Paragraph(), str_repeat('/*a ', 512) . '`*/`');
        self::assertLessThanOrEqual(10, $parser->codeProbes);
    }

    public function testAnEmptyCombinedCloserDoesNotBlockAnEarlierOpener(): void
    {
        $parser = new class (new BlockParser()) extends InlineParser {
            public function combined(string $text, int $pos): ?array
            {
                return $this->parseBoldItalic($text, $pos);
            }
        };
        $source = '/*a /**/ b';
        self::assertNull($parser->combined($source, 4));
        self::assertSame(8, $parser->combined($source, 0)['pos']);
    }

    #[Group('scaling')]
    public function testInvalidPrefixesAndDollarRunsScaleLinearly(): void
    {
        $converter = CarveConverter::create();
        $this->assertScanScalesLinearly($converter, '<a:x ', '>', 'invalid autolink with a far closer', 4096);
        $this->assertScanScalesLinearly($converter, '$', '`x`', 'long math prefix', 4096);
        $this->assertScanScalesLinearly($converter, '</#', '', 'crossrefs without a closer', 4096);
        $this->assertScanScalesLinearly($converter, '</#', ' x>', 'invalid nested crossrefs with a closer', 4096);
        $this->assertScanScalesLinearly($converter, '/{% ', 'x/', 'unclosed comments in emphasis', 4096);
        $this->assertScanScalesLinearly($converter, '/*a ', ' [x */]', 'combined span with rejected closer', 4096);
    }
}
