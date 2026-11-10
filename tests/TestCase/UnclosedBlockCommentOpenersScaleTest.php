<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5088: a `{%` opener with no `%}` before the blank decides nothing, so its paragraph is not rescanned per opener.
 */
class UnclosedBlockCommentOpenersScaleTest extends TestCase
{
    use ScalingGuardTrait;

    public function testUnclosedOpenersStayText(): void
    {
        $this->assertSame(
            "<p>{% a</p>\n<section id=\"h\">\n  <h1>h</h1>\n  <p>{% a</p>\n</section>\n<section id=\"h-2\">\n  <h1>h</h1>\n</section>",
            trim((new CarveConverter())->convert("{% a\n # h\n{% a\n # h\n")),
        );
    }

    #[Group('scaling')]
    #[DataProvider('shapes')]
    public function testUnclosedOpenersScaleLinearly(string $fragment): void
    {
        $this->assertScanScalesLinearly(new CarveConverter(), $fragment, '', json_encode($fragment, JSON_THROW_ON_ERROR), 200);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shapes(): array
    {
        return [
            'root' => ["{% a\n # h\n"],
            'quote' => ["> {% a\n>  # h\n"],
            'item marker line' => ["- {% a\n   # h\n"],
        ];
    }
}
