<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the closer short-circuit in parseBoldItalic(). A `/*` opener scans
 * forward for its `*` + `/` closer; a run of `/*` openers with no closer would
 * otherwise walk to end-of-text at every opener -> O(n^2). A memoized strrpos
 * bails in O(1) when no closer lies ahead. Output must be byte-identical.
 */
class BoldItalicScanTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testUnclosedBoldItalicStaysLiteral(): void
    {
        // No `*/` closer and no bare `/` closer either -> fully literal.
        $this->assertSame('<p>/*x</p>', trim($this->converter->convert('/*x')));
    }

    public function testUnclosedBoldItalicFallsThroughToBareItalic(): void
    {
        // No `*/` closer -> parseBoldItalic declines (byte-identical to the old
        // to-end-of-text scan); the bare `/` italic path then handles it.
        $this->assertSame('<p><em>*x</em>*x</p>', trim($this->converter->convert('/*x/*x')));
    }

    public function testClosedBoldItalicStillParses(): void
    {
        $this->assertSame(
            '<p><strong><em>x</em></strong></p>',
            trim($this->converter->convert('/*x*/')),
        );
    }

    /**
     * Two shapes: a plain unclosed `/*` run, and the trailing-paren variant
     * `/*a](` + one far `)` (the opener falls through to the `/` italic scan,
     * whose link-destination skip must also be bounded).
     *
     * @param string $fragment
     * @param string $suffix
     */
    #[DataProvider('boldItalicShapeProvider')]
    public function testBoldItalicScanScalesLinearly(string $fragment, string $suffix): void
    {
        $small = str_repeat($fragment, 25000) . $suffix;
        $large = str_repeat($fragment, 50000) . $suffix;

        $elapsedSmall = $this->bestConvertTime($small);
        $elapsedLarge = $this->bestConvertTime($large);

        $this->assertLessThan(20.0, $elapsedSmall, "25000x '{$fragment}' took {$elapsedSmall}s");
        $this->assertLessThan(20.0, $elapsedLarge, "50000x '{$fragment}' took {$elapsedLarge}s");

        $ratio = $elapsedLarge / max($elapsedSmall, 0.001);
        $this->assertLessThan(
            3.0,
            $ratio,
            "Doubling input scaled time {$ratio}x: small={$elapsedSmall}s large={$elapsedLarge}s",
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function boldItalicShapeProvider(): array
    {
        return [
            'no-closer' => ['/*x', ''],
            'trailparen' => ['/*a](', ')'],
        ];
    }

    private function bestConvertTime(string $input): float
    {
        $this->converter->convert($input);

        $best = INF;
        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            $this->converter->convert($input);
            $best = min($best, (hrtime(true) - $start) / 1e9);
        }

        return $best;
    }
}
