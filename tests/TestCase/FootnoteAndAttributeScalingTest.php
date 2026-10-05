<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scaling')]
class FootnoteAndAttributeScalingTest extends TestCase
{
    use ScalingGuardTrait;

    public function testChainedFootnoteRenderingScalesLinearly(): void
    {
        $converter = CarveConverter::create();
        $sources = [];
        $documents = [];
        foreach ([2048, 16384] as $n) {
            $source = "[^n0]\n\n";
            for ($i = 0; $i < $n; $i++) {
                $source .= '[^n' . $i . ']: note' . ($i + 1 < $n ? ' [^n' . ($i + 1) . ']' : '') . "\n\n";
            }
            $sources[] = $source;
            $documents[strlen($source)] = $converter->parse($source);
        }
        $this->assertConversionScalesLinearly(
            static function (string $source) use ($converter, $documents): void {
                $converter->render($documents[strlen($source)]);
            },
            $sources[0],
            $sources[1],
            'chained footnote rendering',
            2048,
            16384,
        );
    }

    public function testDistinctAttributeSlotsScaleLinearly(): void
    {
        $converter = CarveConverter::create();
        $sources = [];
        foreach ([1024, 4096] as $n) {
            $source = '[x]{';
            for ($i = 0; $i < $n; $i++) {
                $source .= 'k' . $i . '=x ';
            }
            $sources[] = $source . '}';
        }
        $this->assertConversionScalesLinearly(
            static function (string $source) use ($converter): void {
                $converter->convert($source);
            },
            $sources[0],
            $sources[1],
            'distinct attribute slots',
            1024,
            4096,
        );
    }
}
