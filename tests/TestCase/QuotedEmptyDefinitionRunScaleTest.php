<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A quoted definition reads ahead only while its title is open, so a run
 * of them in one quote paragraph stays linear (carve-php#3476, #3480).
 */
#[Group('scaling')]
class QuotedEmptyDefinitionRunScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @return array<string, array{string}>
     */
    public static function shapes(): array
    {
        return [
            'untitled' => ["> [a]: <>\n"],
            'unclosed parenthesized title' => ["> [a]: <> (x\n"],
            'untitled destination' => ["> [a]: /u\n"],
            'destination, unclosed parenthesized title' => ["> [a]: /u (x\n"],
            'destination, unclosed quoted title' => ["> [a]: /u \"x\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testARunOfQuotedEmptyDefinitionsScalesLinearly(string $fragment): void
    {
        $smallRepeats = 500;
        $largeRepeats = $smallRepeats * 4;

        $this->assertConversionScalesLinearly(
            static function (string $input): void {
                (new MarkdownToCarve())->convert($input);
            },
            "> p\n>\n" . str_repeat($fragment, $smallRepeats),
            "> p\n>\n" . str_repeat($fragment, $largeRepeats),
            $fragment,
            $smallRepeats,
            $largeRepeats,
        );
    }
}
