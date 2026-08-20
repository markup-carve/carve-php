<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\BlockSkeletonWork;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class BlockSkeletonShadowTest extends TestCase
{
    protected function tearDown(): void
    {
        BlockSkeletonWork::$on = false;
        BlockSkeletonWork::reset();
    }

    public function testAuthoritativeShadowIsExactAcrossTheStandaloneCorpus(): void
    {
        $paths = glob(__DIR__ . '/../../spec/tests/corpus/*.crv');
        self::assertIsArray($paths);
        self::assertGreaterThan(1000, count($paths), 'The corpus is truncated.');

        $events = 0;
        $families = [];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);

            BlockSkeletonWork::$on = false;
            $expected = $this->converter()->convert($source);
            BlockSkeletonWork::$on = true;
            $actual = $this->converter()->convert($source);
            self::assertSame($expected, $actual, basename($path));

            $skeleton = BlockSkeletonWork::$last;
            self::assertNotNull($skeleton, basename($path));
            $events += $skeleton->eventCount();
            foreach ($skeleton->frames as $frame) {
                foreach ($frame->events as $event) {
                    self::assertNotNull($event->sourceLine, basename($path));
                }
            }
            foreach ($skeleton->acceptanceCounters() as $family => $count) {
                $families[$family] = ($families[$family] ?? 0) + $count;
            }
        }

        self::assertGreaterThan(1000, $events, 'The shadow event counter is not live.');
        self::assertArrayHasKey('paragraph', $families);
        self::assertArrayHasKey('heading', $families);
        self::assertArrayHasKey('table', $families);
        self::assertSame(23, $families['definition.abbreviation.active'] ?? 0);
        self::assertSame(73, $families['definition.footnote.active'] ?? 0);
        self::assertSame(7, $families['definition.footnote.inactive'] ?? 0);
        self::assertSame(91, $families['definition.reference.active'] ?? 0);
        self::assertSame(19, $families['definition.reference.inactive'] ?? 0);
    }

    public function testAuthoritativeShadowIsExactOnTheConcatenatedCorpus(): void
    {
        $paths = glob(__DIR__ . '/../../spec/tests/corpus/*.crv');
        self::assertIsArray($paths);
        $sources = [];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            $sources[] = $source;
        }
        $source = implode("\n\n", $sources);

        BlockSkeletonWork::$on = false;
        $expected = $this->converter()->convert($source);
        BlockSkeletonWork::$on = true;
        $actual = $this->converter()->convert($source);

        self::assertSame($expected, $actual);
        self::assertNotNull(BlockSkeletonWork::$last);
        self::assertGreaterThan(1000, BlockSkeletonWork::$last->eventCount());
        $counters = BlockSkeletonWork::$last->acceptanceCounters();
        self::assertSame(5, $counters['definition.footnote.active'] ?? 0);
        self::assertSame(16, $counters['definition.footnote.inactive'] ?? 0);
        self::assertSame(29, $counters['definition.reference.active'] ?? 0);
        self::assertSame(5, $counters['definition.reference.inactive'] ?? 0);
    }

    private function converter(): CarveConverter
    {
        return new CarveConverter(renderer: new HtmlRenderer());
    }
}
