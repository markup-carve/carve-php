<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the closer short-circuit in parseBoldItalic(). A `/*` opener scans
 * forward for its `*` + `/` closer; a run of `/*` openers with no closer would
 * otherwise walk to end-of-text at every opener -> O(n^2). A memoized strrpos
 * bails in O(1) when no closer lies ahead. Output must be byte-identical.
 */
#[Group('scaling')]
class BoldItalicScanTest extends TestCase
{
    use ScalingGuardTrait;

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
        $this->assertScanScalesLinearly($this->converter, $fragment, $suffix, "'{$fragment}'");
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
}
