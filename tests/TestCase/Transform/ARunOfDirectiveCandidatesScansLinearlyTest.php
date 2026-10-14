<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Each `{{` candidate in an inline run asks whether its first or last character
 * is escaped. Answering by walking the run from its start made a run of many
 * candidates quadratic in the expander and the writer.
 */
#[Group('scaling')]
class ARunOfDirectiveCandidatesScansLinearlyTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @var int
     */
    private const SMALL_REPEATS = 1000;

    /**
     * @var int
     */
    private const MULTIPLE = 4;

    /**
     * @return iterable<string, array{string}>
     */
    public static function shapeProvider(): iterable
    {
        yield 'escaped candidates' => ['\{{ a.crv }} '];
        yield 'escaped and live candidates' => ['\{{ a.crv }} {{ b.crv }} '];
    }

    #[DataProvider('shapeProvider')]
    public function testTheExpanderScalesLinearly(string $fragment): void
    {
        $resolver = new class implements IncludeResolverInterface {
            public function resolve(string $path, IncludeContext $context): ?string
            {
                return null;
            }
        };
        $converter = new CarveConverter();

        $this->assertShapeScalesLinearly(static function (string $input) use ($converter, $resolver): void {
            $converter->transform($converter->parse($input), new IncludeExpander($resolver));
        }, $fragment, 'expander: ' . $fragment);
    }

    #[DataProvider('shapeProvider')]
    public function testTheWriterScalesLinearly(string $fragment): void
    {
        $carve = CarveConverter::carve();

        $this->assertShapeScalesLinearly(static function (string $input) use ($carve): void {
            $carve->render($carve->parse($input));
        }, $fragment, 'writer: ' . $fragment);
    }

    /**
     * @param \Closure(string): void $convert
     * @param string $fragment
     * @param string $label
     */
    private function assertShapeScalesLinearly(Closure $convert, string $fragment, string $label): void
    {
        $this->assertConversionScalesLinearly(
            $convert,
            str_repeat($fragment, self::SMALL_REPEATS) . "\n",
            str_repeat($fragment, self::SMALL_REPEATS * self::MULTIPLE) . "\n",
            $label,
            self::SMALL_REPEATS,
            self::SMALL_REPEATS * self::MULTIPLE,
        );
    }
}
