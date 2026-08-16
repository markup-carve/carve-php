<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A typed div stays typed under a class-carrying attribute line, so its
 * quoted title survives fmt.
 *
 * The typed writer used to require exactly one class, so `{.sidebar}` above
 * `::: widget "Title"` fell through to the untyped writer - which has no
 * title slot - and one fmt pass dropped the title and its
 * `admonition-title` heading from the rendered HTML (carve-php#1284). Only
 * the OPENER class decides now; the extra classes are the attribute line's
 * business and are written back there.
 */
class TypedDivKeepsTitleUnderClassAttrLineTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'custom type, class attr line' => ["{.sidebar}\n::: widget \"Title\"\nbody\n:::\n"],
            'custom type, class + id + label' => ["{.sidebar #s1}\n::: widget \"Title\" [Label]\nbody\n:::\n"],
            'built-in type, class attr line' => ["{.x}\n::: note \"Title\"\nbody\n:::\n"],
            'custom type, two added classes' => ["{.a .b}\n::: custom\nbody\n:::\n"],
        ];
    }

    /**
     * @param string $source
     */
    #[DataProvider('sourceProvider')]
    public function testFmtKeepsTheSpellingAndTheHtml(string $source): void
    {
        $canonical = CarveConverter::carve()->render((new CarveConverter())->parse($source));

        $this->assertSame($source, $canonical, 'the authored spelling is already canonical');
        $this->assertSame(
            (new CarveConverter())->convert($source),
            (new CarveConverter())->convert($canonical),
            'fmt must not change the rendered HTML',
        );
    }

    public function testTheTitleSurvivesIntoTheHtml(): void
    {
        $canonical = CarveConverter::carve()->render(
            (new CarveConverter())->parse("{.sidebar}\n::: widget \"Title\"\nbody\n:::\n"),
        );

        $this->assertStringContainsString(
            '<p class="admonition-title">Title</p>',
            (new CarveConverter())->convert($canonical),
        );
    }
}
