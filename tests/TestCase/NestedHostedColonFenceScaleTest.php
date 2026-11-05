<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Colon divs nested on continuation lines of a list item must not re-read
 * their body at every depth (carve-php#4647). Lint parses about twenty times,
 * so it is measured too.
 */
#[Group('scaling')]
class NestedHostedColonFenceScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * Both sizes stay below the nesting cap, so a per-depth body scan shows.
     *
     * @var int
     */
    private const SMALL = 50;

    /**
     * @var int
     */
    private const LARGE = 200;

    public function testNestedHostedDivsParseLinearly(): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->convert($input);
            },
            $this->nested(self::SMALL),
            $this->nested(self::LARGE),
            'nested list-hosted colon divs',
            self::SMALL,
            self::LARGE,
        );
    }

    public function testNestedHostedDivsLintLinearly(): void
    {
        $linter = new DocumentLinter();
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($linter): void {
                $linter->lint($input);
            },
            $this->nested(self::SMALL),
            $this->nested(self::LARGE),
            'lint of nested list-hosted colon divs',
            self::SMALL,
            self::LARGE,
        );
    }

    public function testTheNestingStillRendersInsideTheItem(): void
    {
        $html = (new CarveConverter())->convert($this->nested(3));

        $this->assertSame(3, substr_count($html, '<aside class="admonition note"'));
        $this->assertStringEndsWith("</aside>\n    </aside>\n  </li>\n</ul>", rtrim($html));
    }

    private function nested(int $depth): string
    {
        return "- x\n\n" . str_repeat("  :::: note\n  text\n", $depth);
    }
}
