<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Each nested div is one ownership frame, so a per-line walk over every open
 * frame is quadratic in the nesting depth (carve-php#4650).
 */
#[Group('scaling')]
class NestedTopLevelColonFenceScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @var int
     */
    private const SMALL = 1500;

    /**
     * @var int
     */
    private const LARGE = 6000;

    public function testNestedTopLevelDivsParseLinearly(): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->parse($input);
            },
            $this->nested(self::SMALL),
            $this->nested(self::LARGE),
            'nested top-level colon divs',
            self::SMALL,
            self::LARGE,
        );
    }

    public function testTheNestingStillRenders(): void
    {
        $html = (new CarveConverter())->convert($this->nested(3) . "::::\n::::\n::::\n\nafter\n");

        $this->assertSame(3, substr_count($html, '<aside class="admonition note"'));
        $this->assertStringEndsWith("    </aside>\n  </aside>\n</aside>\n<p>after</p>", rtrim($html));
    }

    private function nested(int $depth): string
    {
        return str_repeat(":::: note\ntext\n", $depth);
    }
}
