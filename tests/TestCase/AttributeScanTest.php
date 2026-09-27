<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards the closer short-circuits in findAttributeEnd(). An inline attribute
 * block `[x]{...}` scans forward for its `}`; without a bound, a run of `[x]{`
 * openers with no closer (or one far, never-balancing `}`) makes every opener
 * walk to end-of-text in quadratic time. The scanner stops when no closer
 * remains or an opening brace appears outside an unquoted value.
 */
#[Group('scaling')]
class AttributeScanTest extends TestCase
{
    use ScalingGuardTrait;

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testUnclosedSpanAttrStaysLiteral(): void
    {
        $this->assertSame('<p>[x]{[x]{</p>', trim($this->converter->convert('[x]{[x]{')));
    }

    public function testFarBraceNeverBalancesLeavesOpenersLiteral(): void
    {
        // The nested opener invalidates the first block; the last span is valid.
        $this->assertSame('<p>[x]{<span>x</span></p>', trim($this->converter->convert('[x]{[x]{}')));
    }

    public function testValidSpanAttrStillParses(): void
    {
        $this->assertSame('<p><span class="x">a</span></p>', trim($this->converter->convert('[a]{.x}')));
    }

    public function testQuotedBraceInAttrValueStillParses(): void
    {
        // A closing brace inside a quoted value does not close the block.
        $this->assertSame('<p><span key="v}v">a</span></p>', trim($this->converter->convert('[a]{key="v}v"}')));
    }

    /**
     * Doubling the opener count must not triple the time (linear ~2x,
     * quadratic ~4x). A generous 20s absolute wall backstops a full regression
     * while leaving headroom for coverage-instrumented CI.
     *
     * @param string $fragment
     * @param string $suffix
     */
    #[DataProvider('attributeShapeProvider')]
    public function testAttributeScanScalesLinearly(string $fragment, string $suffix): void
    {
        $this->assertScanScalesLinearly($this->converter, $fragment, $suffix, "'{$fragment}'");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function attributeShapeProvider(): array
    {
        return [
            'no-closer' => ['[x]{', ''],
            'far-brace' => ['[x]{', '}'],
            'distant-invalid-pipe' => ['[x]{k=a', '|}'],
            'invalid-pipe-value' => ['*x*{k=|', '}'],
            'invalid-backslash-value' => ['[x]{k=\\', '}'],
        ];
    }
}
