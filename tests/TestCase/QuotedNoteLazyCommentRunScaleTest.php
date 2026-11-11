<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Each lazy `{%` opener in a quoted note's body was probed against every
 * later line of the paragraph (carve#5911).
 */
#[Group('scaling')]
class QuotedNoteLazyCommentRunScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @var int
     */
    private const SMALL = 500;

    /**
     * @var int
     */
    private const LARGE = 2000;

    public function testLazyCommentRunsInAQuotedNoteParseLinearly(): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->convert($input);
            },
            $this->note(self::SMALL),
            $this->note(self::LARGE),
            'lazy comment runs in a quoted note',
            self::SMALL,
            self::LARGE,
        );
    }

    public function testARunLongerThanTheProbeWindowStillEndsAtItsCloser(): void
    {
        $run = "> {% c\n" . str_repeat(">   z\n", 20) . ">   z %}\n";
        $html = (new CarveConverter())->convert("> [^n]: b\n" . $run . $run . "- s\n\nx[^n]\n");

        $this->assertStringContainsString("</blockquote>\n<ul>\n  <li>s</li>\n</ul>", $html);
    }

    private function note(int $runs): string
    {
        return "> [^n]: b\n" . str_repeat("> {% c\n>   z %}\n", $runs) . "- s\n\nx[^n]\n";
    }
}
