<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PROBING EVERY CANDIDATE MUST NOT COST THE VALUE ONCE PER CANDIDATE.
 *
 * PART 9 §25 moved the URL-list probe from the value's head to every one of
 * its tokens, which turns a fixed amount of work per attribute into work
 * proportional to the candidate count. That is linear when the value is split
 * once and each token is probed on its own, and quadratic the moment a probe
 * re-reads the whole value per token - the shape this guards, because the
 * value is attacker-supplied and a document may carry one enormous `srcset`.
 *
 * The GROWING DIMENSION IS THE CANDIDATE COUNT INSIDE ONE VALUE, not the
 * number of attributes in the document. Repeating a whole attribute would
 * grow the token count too, but linearly across independent values, so it
 * cannot separate a per-value quadratic from a healthy scan.
 *
 * Both halves of the set are measured, because they do not share a separator
 * class: `srcset` splits on commas as well as ASCII whitespace, `ping` on
 * whitespace only.
 */
#[Group('scaling')]
class UrlListAttributeProbeScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * Candidates in the smaller sample. The trait's inline default builds a
     * document from a repeated fragment; here the repeat count is candidates
     * inside a single attribute value, so a smaller count already produces a
     * value far larger than any real document carries.
     *
     * @var int
     */
    private const SMALL_CANDIDATES = 5000;

    /**
     * @var int
     */
    private const LARGE_CANDIDATES = 20000;

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function urlListShapeProvider(): array
    {
        return [
            // Clean throughout, so every candidate is probed and none
            // short-circuits the scan: the worst case for this code path.
            'srcset clean' => ['![a](s.png){srcset="', 'c.png 1x, ', '"}', 'srcset'],
            'ping clean' => ['[y](s.html){ping="', 'https://example.com/c ', '"}', 'ping'],
        ];
    }

    #[DataProvider('urlListShapeProvider')]
    public function testTheTokenWiseProbeScalesLinearly(
        string $prefix,
        string $candidate,
        string $suffix,
        string $label,
    ): void {
        $this->assertConversionScalesLinearly(
            function (string $input): void {
                $this->converter->convert($input);
            },
            $prefix . str_repeat($candidate, self::SMALL_CANDIDATES) . $suffix,
            $prefix . str_repeat($candidate, self::LARGE_CANDIDATES) . $suffix,
            $label . ' candidates',
            self::SMALL_CANDIDATES,
            self::LARGE_CANDIDATES,
        );
    }

    /**
     * The measurement above is only meaningful if the value really reaches the
     * probe. A shape that stopped parsing as an attribute would scale
     * beautifully and guard nothing.
     */
    public function testTheMeasuredShapeIsActuallyProbed(): void
    {
        $clean = '![a](s.png){srcset="' . str_repeat('c.png 1x, ', 3) . '"}';
        $this->assertStringContainsString('srcset="c.png 1x,', $this->converter->convert($clean));

        $dirty = '![a](s.png){srcset="'
            . str_repeat('c.png 1x, ', 3)
            . 'javascript:alert(1) 2x"}';
        $this->assertStringContainsString('srcset=""', $this->converter->convert($dirty));
    }
}
