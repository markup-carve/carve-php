<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Lint\FragileConstructLinter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A lint walk that shifts its pending list costs O(siblings) per node (carve-php#4654).
 */
#[Group('scaling')]
class LintWalkScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * Wide apart, so the walk shows past the parse each lint call makes.
     *
     * @var int
     */
    private const SMALL = 4000;

    /**
     * @var int
     */
    private const LARGE = 32000;

    public function testManySiblingBlocksLintLinearly(): void
    {
        $linter = new FragileConstructLinter();
        $this->assertConversionScalesLinearly(
            static function (string $source) use ($linter): void {
                $linter->lint($source);
            },
            str_repeat("word\n\n", self::SMALL),
            str_repeat("word\n\n", self::LARGE),
            'sibling paragraphs',
            self::SMALL,
            self::LARGE,
        );
    }
}
